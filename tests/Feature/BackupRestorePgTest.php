<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\DatabaseRestoreService;
use App\Services\Backup\DumpScriptPolicy;
use App\Services\Backup\RestoreFailure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REAL end-to-end proof that a backup this system produces can actually be
 * restored — using the genuine external tools (`pg_dump`, `tar`, `openssl`,
 * `psql`), never a faked process layer.
 *
 * The round trip is: migrated source database → committed marker rows → a real
 * ENCRYPTED archive through the ordinary backup path → decrypt, inspect and
 * extract → `psql` into a separately prepared EMPTY scratch database → assert
 * the data, the foreign key, the sequences and the migration history survived.
 *
 * No RefreshDatabase: `pg_dump` runs on its own connection, so marker rows
 * must be COMMITTED to appear in the dump. Every row, setting, database and
 * file this class creates is removed explicitly in tearDown.
 *
 * On a non-PostgreSQL driver the class skips (the SQLite job never runs it).
 * On PostgreSQL it FAILS rather than skips when a required tool is missing —
 * a restore suite that quietly evaporates would be worse than no suite, and
 * the dedicated CI step additionally runs with --fail-on-skipped.
 */
class BackupRestorePgTest extends TestCase
{
    private string $tmp = '';

    private string $marker = '';

    private ?string $targetDatabase = null;

    /** @var array<string,string|null> */
    private array $originalSettings = [];

    /** @var list<int> */
    private array $createdUserIds = [];

    /** @var list<int> */
    private array $createdOrderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The real backup round trip requires PostgreSQL (CI pgsql job).');
        }

        foreach (['pg_dump', 'psql', 'tar', 'openssl'] as $tool) {
            $this->assertNotSame(
                '',
                trim((string) shell_exec('command -v '.escapeshellarg($tool).' 2>/dev/null')),
                "the PostgreSQL job must provide {$tool}: this suite proves real restorability and must never silently degrade",
            );
        }

        $this->marker = 'zprt'.bin2hex(random_bytes(6));
        // NOT `zp-restore-*`: that is the service's own work-directory prefix,
        // and the leftover sweeps below glob for it.
        $this->tmp = sys_get_temp_dir().'/zp-rstit-'.bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);

        $this->rememberSettings();
    }

    protected function tearDown(): void
    {
        // setUp may have skipped before any fixture existed (non-PostgreSQL run).
        if ($this->tmp === '') {
            parent::tearDown();

            return;
        }

        try {
            $this->dropTargetDatabase();
            Order::whereIn('id', $this->createdOrderIds)->delete();
            User::whereIn('id', $this->createdUserIds)->delete();
            SiteSetting::where('key', 'like', $this->marker.'%')->delete();
            BackupLog::where('file_path', 'like', $this->tmp.'%')->delete();
            $this->restoreSettings();
        } catch (\Throwable) {
            // best effort — never mask the assertion failure under test
        }

        exec('rm -rf '.escapeshellarg($this->tmp));

        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** @var list<string> */
    private const TOUCHED_SETTINGS = [
        'backup_enabled', 'backup_storage_path', 'backup_include_database',
        'backup_include_storage', 'backup_include_uploads', 'backup_include_project_files',
        'backup_encrypt_enabled', 'backup_password', 'telegram_admin_enabled',
    ];

    private function rememberSettings(): void
    {
        foreach (self::TOUCHED_SETTINGS as $key) {
            $this->originalSettings[$key] = SiteSetting::where('key', $key)->value('value');
        }
    }

    private function restoreSettings(): void
    {
        foreach ($this->originalSettings as $key => $value) {
            $value === null
                ? SiteSetting::where('key', $key)->delete()
                : SiteSetting::set($key, $value);
        }
    }

    /** Database-only backup writing into the private tmp dir. */
    private function configureDatabaseOnlyBackup(bool $encrypted, string $password = 'Archive-Pass-1'): void
    {
        SiteSetting::set('telegram_admin_enabled', 'false');
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $this->tmp);
        SiteSetting::set('backup_include_database', 'true');
        SiteSetting::set('backup_include_storage', 'false');
        SiteSetting::set('backup_include_uploads', 'false');
        SiteSetting::set('backup_include_project_files', 'false');
        SiteSetting::set('backup_encrypt_enabled', $encrypted ? 'true' : 'false');

        if ($encrypted) {
            SiteSetting::set('backup_password', app(BackupSettings::class)->encryptPassword($password));
        }
    }

    /**
     * COMMITTED marker rows: a user, a site setting, and an order whose
     * foreign key points at that user.
     *
     * @return array{user:User, order:Order, settingKey:string}
     */
    private function seedMarkers(): array
    {
        $user = User::factory()->create([
            'email' => $this->marker.'@example.com',
            'name' => 'Restore Marker '.$this->marker,
        ]);
        $this->createdUserIds[] = (int) $user->id;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'plan_name' => 'Marker Plan '.$this->marker,
            'price_toman' => 123456,
            'final_price_toman' => 123456,
        ]);
        $this->createdOrderIds[] = (int) $order->id;

        $settingKey = $this->marker.'_setting';
        SiteSetting::set($settingKey, 'marker-value-'.$this->marker);

        return ['user' => $user, 'order' => $order, 'settingKey' => $settingKey];
    }

    private function createBackup(): string
    {
        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL, false);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status'], 'backup run must succeed: '.($result['error'] ?? ''));
        $this->assertIsString($result['path']);
        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, (int) $result['size']);

        return (string) $result['path'];
    }

    // ── Scratch target database ────────────────────────────────────────────

    /**
     * The restore role must be LEAST PRIVILEGE — the service refuses a
     * superuser or a member of pg_execute_server_program /
     * pg_read_server_files / pg_write_server_files. CI's own DB user is a
     * superuser, so tests create a dedicated unprivileged role and point the
     * restore at it. It owns each scratch target, which is exactly the flow
     * the runbook documents (`createdb -O`).
     */
    private const LP_ROLE = 'zp_restore_lp';

    private const LP_PASSWORD = 'zp-restore-lp-pass';

    private function ensureLeastPrivilegeRole(): void
    {
        $exists = (int) DB::scalar('select count(*) from pg_roles where rolname = ?', [self::LP_ROLE]);
        if ($exists === 0) {
            DB::statement('CREATE ROLE '.self::LP_ROLE." LOGIN PASSWORD '".self::LP_PASSWORD."' NOSUPERUSER NOCREATEDB NOCREATEROLE");
        } else {
            DB::statement('ALTER ROLE '.self::LP_ROLE." WITH LOGIN PASSWORD '".self::LP_PASSWORD."' NOSUPERUSER NOCREATEDB NOCREATEROLE");
        }
    }

    /** Run $work with the default connection pointed at the restore role. */
    private function asLeastPrivilegeRole(callable $work): mixed
    {
        $connection = (string) config('database.default');
        $original = (array) config('database.connections.'.$connection);

        Config::set('database.connections.'.$connection.'.username', self::LP_ROLE);
        Config::set('database.connections.'.$connection.'.password', self::LP_PASSWORD);
        DB::purge('zp_restore_target');

        try {
            return $work();
        } finally {
            Config::set('database.connections.'.$connection, $original);
            DB::purge('zp_restore_target');
            DB::purge($connection);
        }
    }

    /** Create an EMPTY scratch database (the command never creates one). */
    private function createTargetDatabase(): string
    {
        $this->ensureLeastPrivilegeRole();

        $name = 'zp_restore_'.bin2hex(random_bytes(6));
        $this->assertSame(1, preg_match(DatabaseRestoreService::NAME_PATTERN, $name));

        DB::statement('CREATE DATABASE "'.$name.'" OWNER '.self::LP_ROLE);
        $this->targetDatabase = $name;

        return $name;
    }

    private function dropTargetDatabase(): void
    {
        if ($this->targetDatabase === null) {
            return;
        }
        $name = $this->targetDatabase;
        $this->targetDatabase = null;

        try {
            DB::purge('zp_restore_target');
            DB::statement('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        } catch (\Throwable) {
            // best effort
        }
    }

    /** A connection bound to the scratch target, for assertions. */
    private function target(string $database)
    {
        $conn = (array) config('database.connections.'.config('database.default'));
        Config::set('database.connections.zp_restore_assert', array_merge($conn, ['database' => $database]));
        DB::purge('zp_restore_assert');

        return DB::connection('zp_restore_assert');
    }

    private function restoreCommand(string $archive, string $database, ?string $password = null): int
    {
        if ($password !== null) {
            putenv(DatabaseRestoreService::PASSWORD_ENV.'='.$password);
        } else {
            putenv(DatabaseRestoreService::PASSWORD_ENV);
        }

        try {
            return (int) $this->asLeastPrivilegeRole(fn () => Artisan::call('zedproxy:backup-restore', [
                'archive' => $archive,
                '--target-database' => $database,
            ]));
        } finally {
            putenv(DatabaseRestoreService::PASSWORD_ENV);
        }
    }

    // ── The real encrypted round trip ──────────────────────────────────────

    public function test_a_real_encrypted_backup_restores_into_an_empty_scratch_database(): void
    {
        $password = 'Round-Trip-Pass-1';
        $this->configureDatabaseOnlyBackup(true, $password);
        $markers = $this->seedMarkers();

        $archive = $this->createBackup();
        $this->assertStringEndsWith('.tar.gz.enc', $archive, 'the encrypted path must be exercised');

        // No plaintext archive or dump may survive next to the encrypted one.
        $this->assertSame([], glob($this->tmp.'/*.tar.gz') ?: [], 'no plaintext archive residue');
        $this->assertSame([], glob($this->tmp.'/**/database.sql') ?: [], 'no plaintext dump residue');

        $target = $this->createTargetDatabase();
        $exit = $this->restoreCommand($archive, $target, $password);
        $this->assertSame(0, $exit, Artisan::output());

        $db = $this->target($target);

        // Structure survived.
        foreach (['migrations', 'users', 'orders', 'site_settings'] as $table) {
            $this->assertSame(
                1,
                (int) $db->scalar(
                    "select count(*) from information_schema.tables where table_schema='public' and table_name = ?",
                    [$table],
                ),
                "table {$table} must exist in the restored database",
            );
        }
        $this->assertGreaterThanOrEqual(52, (int) $db->scalar(
            "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE'",
        ), 'the full public schema must be present');

        // Marker values match EXACTLY.
        $restoredUser = $db->table('users')->where('id', $markers['user']->id)->first();
        $this->assertNotNull($restoredUser);
        $this->assertSame($this->marker.'@example.com', $restoredUser->email);
        $this->assertSame('Restore Marker '.$this->marker, $restoredUser->name);

        $this->assertSame(
            'marker-value-'.$this->marker,
            $db->table('site_settings')->where('key', $markers['settingKey'])->value('value'),
        );

        // The relational foreign key is preserved and still resolves.
        $restoredOrder = $db->table('orders')->where('id', $markers['order']->id)->first();
        $this->assertNotNull($restoredOrder);
        $this->assertSame((int) $markers['user']->id, (int) $restoredOrder->user_id);
        $this->assertSame('Marker Plan '.$this->marker, $restoredOrder->plan_name);
        $this->assertSame(
            1,
            (int) $db->scalar(
                'select count(*) from orders o join users u on u.id = o.user_id where o.id = ?',
                [$markers['order']->id],
            ),
            'the order → user foreign key must still join',
        );

        // Row counts match the source for the tables we control.
        foreach (['users', 'orders', 'site_settings', 'migrations'] as $table) {
            $this->assertSame(
                (int) DB::table($table)->count(),
                (int) $db->table($table)->count(),
                "row count mismatch for {$table}",
            );
        }

        // Migration history present.
        $this->assertGreaterThan(0, (int) $db->scalar('select count(*) from migrations'));

        // Sequences work: a fresh insert must not collide with a restored id.
        $newId = $db->table('users')->insertGetId([
            'name' => 'Post Restore '.$this->marker,
            'username' => 'post_'.$this->marker,
            'email' => 'post-'.$this->marker.'@example.com',
            'password' => bcrypt('Post-Restore-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertGreaterThan((int) $markers['user']->id, $newId, 'the users sequence must be ahead of restored ids');
    }

    public function test_a_plain_unencrypted_archive_also_restores(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $markers = $this->seedMarkers();

        $archive = $this->createBackup();
        $this->assertStringEndsWith('.tar.gz', $archive);
        $this->assertStringNotContainsString('.enc', $archive);

        $target = $this->createTargetDatabase();
        $this->assertSame(0, $this->restoreCommand($archive, $target), Artisan::output());

        $this->assertSame(
            $this->marker.'@example.com',
            $this->target($target)->table('users')->where('id', $markers['user']->id)->value('email'),
        );
    }

    // ── Target refusals ────────────────────────────────────────────────────

    public function test_the_current_application_database_is_refused(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        $current = (string) config('database.connections.'.config('database.default').'.database');

        // Through the command …
        $this->assertSame(1, $this->restoreCommand($archive, $current));

        // … and through the service, with the precise refusal reason.
        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $current));
            $this->fail('the live application database must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category());
            $this->assertSame('current_database', $e->reason());
        }

        // The live database still holds its data — nothing was restored over it.
        $this->assertGreaterThan(0, DB::table('users')->count());
    }

    public function test_reserved_and_malformed_targets_are_refused_before_any_work(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        $rejects = [
            'postgres' => 'reserved_database',
            'template0' => 'reserved_database',
            'template1' => 'reserved_database',
            'Bad-Name' => 'name_rejected',
            'drop table users' => 'name_rejected',
            'zp";select 1;--' => 'name_rejected',
            '' => 'name_rejected',
            str_repeat('a', 64) => 'name_rejected',
        ];

        foreach ($rejects as $name => $reason) {
            try {
                $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, (string) $name));
                $this->fail('target must be refused: '.var_export($name, true));
            } catch (RestoreFailure $e) {
                $this->assertSame($reason, $e->reason(), 'for target '.var_export($name, true));
                $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category());
            }
        }
    }

    public function test_a_non_empty_target_is_refused(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        $target = $this->createTargetDatabase();
        $this->target($target)->statement('create table occupied (id int primary key)');

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('a non-empty target must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('target_not_empty', $e->reason());
        }

        // The pre-existing table is untouched — nothing was restored over it.
        $this->assertSame(
            1,
            (int) $this->target($target)->scalar(
                "select count(*) from information_schema.tables where table_schema='public'",
            ),
        );
    }

    // ── Archive refusals ───────────────────────────────────────────────────

    public function test_a_wrong_password_fails_before_any_restore_happens(): void
    {
        $this->configureDatabaseOnlyBackup(true, 'Correct-Pass-1');
        $this->seedMarkers();
        $archive = $this->createBackup();

        $target = $this->createTargetDatabase();

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target, 'Wrong-Pass-2'));
            $this->fail('a wrong archive password must fail');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_DECRYPTION, $e->category());
            $this->assertSame('process_failed', $e->reason());
        }

        $this->assertSame(0, (int) $this->target($target)->scalar(
            "select count(*) from information_schema.tables where table_schema='public'",
        ), 'nothing may be restored when decryption fails');
    }

    public function test_a_missing_password_in_non_interactive_mode_fails_closed(): void
    {
        $this->configureDatabaseOnlyBackup(true, 'Some-Pass-1');
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        // No password argument and no environment variable. This also guards a
        // real hang: Artisan::call() uses an ArrayInput that reports itself
        // INTERACTIVE, so a prompt here would block forever on a stdin nobody
        // will type into (CI, cron, a deploy script). The command must return.
        $this->assertSame(1, $this->restoreCommand($archive, $target));
        $this->assertStringNotContainsString('Archive password', Artisan::output(), 'must never prompt without a TTY');
        $this->assertSame(0, (int) $this->target($target)->scalar(
            "select count(*) from information_schema.tables where table_schema='public'",
        ));
    }

    public function test_a_corrupt_archive_is_refused(): void
    {
        $corrupt = $this->tmp.'/corrupt.tar.gz';
        file_put_contents($corrupt, random_bytes(4096));
        $target = $this->createTargetDatabase();

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($corrupt, $target));
            $this->fail('a corrupt archive must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_CONTENT, $e->category());
        }
    }

    public function test_unsafe_archive_paths_and_entry_types_are_refused(): void
    {
        $target = $this->createTargetDatabase();
        $service = app(DatabaseRestoreService::class);

        // A symlinked database.sql must never be extracted or followed.
        $linkDir = $this->tmp.'/link';
        mkdir($linkDir, 0700, true);
        file_put_contents($linkDir.'/real.sql', "select 1;\n");
        symlink('real.sql', $linkDir.'/database.sql');
        $linkArchive = $this->tmp.'/link.tar.gz';
        exec('tar -czf '.escapeshellarg($linkArchive).' -C '.escapeshellarg($linkDir).' database.sql real.sql');

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($linkArchive, $target));
            $this->fail('a symlinked database.sql must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('entry_link', $e->reason());
        }

        // A traversal entry must be refused.
        $travDir = $this->tmp.'/trav';
        mkdir($travDir.'/inner', 0700, true);
        file_put_contents($travDir.'/inner/database.sql', "select 1;\n");
        $travArchive = $this->tmp.'/trav.tar.gz';
        exec('tar -czf '.escapeshellarg($travArchive).' -C '.escapeshellarg($travDir.'/inner').' ../inner/database.sql 2>/dev/null');

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($travArchive, $target));
            $this->fail('a traversal entry must be refused');
        } catch (RestoreFailure $e) {
            $this->assertContains($e->reason(), ['entry_traversal', 'dump_nested', 'dump_missing', 'listing_failed']);
        }
    }

    public function test_a_missing_or_nested_database_dump_is_refused(): void
    {
        $target = $this->createTargetDatabase();
        $service = app(DatabaseRestoreService::class);

        // No database.sql at all.
        $noDumpDir = $this->tmp.'/nodump';
        mkdir($noDumpDir, 0700, true);
        file_put_contents($noDumpDir.'/notes.txt', "nothing here\n");
        $noDump = $this->tmp.'/nodump.tar.gz';
        exec('tar -czf '.escapeshellarg($noDump).' -C '.escapeshellarg($noDumpDir).' notes.txt');

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($noDump, $target));
            $this->fail('an archive without database.sql must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('dump_missing', $e->reason());
        }

        // Only a NESTED database.sql.
        $nestedDir = $this->tmp.'/nested';
        mkdir($nestedDir.'/backup', 0700, true);
        file_put_contents($nestedDir.'/backup/database.sql', "select 1;\n");
        $nested = $this->tmp.'/nested.tar.gz';
        exec('tar -czf '.escapeshellarg($nested).' -C '.escapeshellarg($nestedDir).' backup');

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($nested, $target));
            $this->fail('a nested database.sql must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('dump_nested', $e->reason());
        }
    }

    public function test_an_empty_dump_is_refused(): void
    {
        $target = $this->createTargetDatabase();

        $dir = $this->tmp.'/emptydump';
        mkdir($dir, 0700, true);
        touch($dir.'/database.sql');
        $archive = $this->tmp.'/emptydump.tar.gz';
        exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($dir).' database.sql');

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('an empty database.sql must be refused');
        } catch (RestoreFailure $e) {
            $this->assertContains($e->reason(), ['dump_empty', 'dump_missing']);
        }
    }

    // ── Transactional failure + output hygiene ─────────────────────────────

    public function test_a_sql_error_leaves_no_partially_restored_schema(): void
    {
        $target = $this->createTargetDatabase();

        // A dump whose FIRST statements succeed and whose last one fails: with
        // ON_ERROR_STOP + --single-transaction the earlier tables must roll back.
        $dir = $this->tmp.'/badsql';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/database.sql', implode("\n", [
            'CREATE TABLE alpha (id integer primary key);',
            'CREATE TABLE beta (id integer primary key);',
            'INSERT INTO alpha (id) VALUES (1);',
            'THIS IS NOT VALID SQL;',
            '',
        ]));
        $archive = $this->tmp.'/badsql.tar.gz';
        exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($dir).' database.sql');

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('a SQL error must fail the restore');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_RESTORE, $e->category());
            $this->assertSame('psql_failed', $e->reason());
            $this->assertIsInt($e->exitCode());
        }

        $this->assertSame(
            0,
            (int) $this->target($target)->scalar(
                "select count(*) from information_schema.tables where table_schema='public'",
            ),
            'the single transaction must leave NO partially restored schema',
        );
    }

    public function test_no_secret_or_raw_process_output_reaches_operator_surfaces(): void
    {
        $password = 'Ultra-Secret-Archive-Pass-9';
        $this->configureDatabaseOnlyBackup(true, $password);
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        // Capture what REALLY lands in a log file, rather than trusting an
        // in-memory listener: this is the artifact an operator would read.
        $logFile = $this->tmp.'/restore-probe.log';
        Config::set('logging.channels.zp_restore_probe', [
            'driver' => 'single', 'path' => $logFile, 'level' => 'debug',
        ]);
        Config::set('logging.default', 'zp_restore_probe');
        Log::forgetChannel('zp_restore_probe');

        // A wrong password: the failing path is the one most likely to leak.
        $exit = $this->restoreCommand($archive, $target, 'Definitely-Wrong-Pass');
        $this->assertSame(1, $exit);

        $consoleOutput = Artisan::output();
        $logContents = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $this->assertNotSame('', $logContents, 'the failure must be logged for the operator');

        $dbPassword = (string) config('database.connections.'.config('database.default').'.password');
        $haystacks = [$consoleOutput, $logContents];

        foreach ($haystacks as $text) {
            $this->assertStringNotContainsString($password, $text, 'archive password must never surface');
            $this->assertStringNotContainsString('Definitely-Wrong-Pass', $text, 'supplied password must never surface');
            if ($dbPassword !== '') {
                $this->assertStringNotContainsString($dbPassword, $text, 'database password must never surface');
            }
            $this->assertStringNotContainsString($this->tmp, $text, 'absolute server paths must never surface');
            $this->assertStringNotContainsString('bad decrypt', strtolower($text), 'raw openssl stderr must never surface');
            $this->assertStringNotContainsString('PGPASSWORD', $text);
            $this->assertStringNotContainsString('ZP_BACKUP_RESTORE_PASSWORD=', $text);
        }

        // The safe, positive-listed diagnostics ARE present.
        $this->assertStringContainsString('decryption', $logContents);
        $this->assertStringContainsString('process_failed', $logContents);
        $this->assertStringContainsString(basename($archive), $logContents);
        $this->assertStringContainsString($target, $logContents);
    }

    // ── Dangerous psql scripts (4.1) ───────────────────────────────────────

    /** Wrap arbitrary SQL text as a top-level database.sql archive. */
    private function archiveWithDump(string $sql, string $name): string
    {
        $dir = $this->tmp.'/'.$name;
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/database.sql', $sql);
        $archive = $this->tmp.'/'.$name.'.tar.gz';
        exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($dir).' database.sql');

        return $archive;
    }

    public function test_a_shell_meta_command_is_refused_before_psql_and_runs_nothing(): void
    {
        $target = $this->createTargetDatabase();
        $marker = $this->tmp.'/PWNED';

        // Verified against PostgreSQL 16.13: `\!` in a -f script executes a
        // local shell command even with --no-psqlrc and --single-transaction.
        $archive = $this->archiveWithDump(
            "CREATE TABLE alpha (id int);\n\\! touch ".escapeshellarg($marker)."\n",
            'shell',
        );

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('a shell meta-command must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_CONTENT, $e->category());
            $this->assertStringStartsWith('meta_command', $e->reason());
        }

        $this->assertFileDoesNotExist($marker, 'no shell command may ever run');
        $this->assertSame(0, $this->objectCount($target), 'nothing may be restored');
    }

    public function test_a_connect_meta_command_cannot_redirect_to_the_live_database(): void
    {
        $target = $this->createTargetDatabase();
        $live = (string) config('database.connections.'.config('database.default').'.database');
        $before = (int) DB::table('users')->count();

        $archive = $this->archiveWithDump(
            "\\connect {$live}\nDROP TABLE IF EXISTS users;\n",
            'connect',
        );

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('\\connect must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('meta_command_connect', $e->reason());
        }

        $this->assertSame($before, (int) DB::table('users')->count(), 'the live database must be untouched');
        $this->assertSame(0, $this->objectCount($target));
    }

    public function test_include_output_and_copy_meta_commands_are_refused(): void
    {
        $target = $this->createTargetDatabase();

        $cases = [
            'include' => ["\\i /etc/passwd\n", 'meta_command_i'],
            'includerel' => ["\\ir other.sql\n", 'meta_command_ir'],
            'output' => ["\\o /tmp/zp-out\n", 'meta_command_o'],
            'clientcopy' => ["\\copy alpha from '/etc/passwd'\n", 'meta_command_copy'],
            'setenv' => ["\\setenv PATH /tmp\n", 'meta_command_setenv'],
            'backtick' => ["\\restrict `id`\n", 'meta_command_malformed'],
        ];

        foreach ($cases as $name => [$sql, $reason]) {
            $archive = $this->archiveWithDump($sql, 'meta_'.$name);
            try {
                $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
                $this->fail("{$name} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame($reason, $e->reason(), $name);
            }
            $this->assertSame(0, $this->objectCount($target), $name);
        }
    }

    public function test_explicit_transaction_control_cannot_defeat_atomic_restore(): void
    {
        $target = $this->createTargetDatabase();

        // Verified: with a bare COMMIT the outer --single-transaction is over,
        // so a later error leaves tables behind. It must never reach psql.
        $archive = $this->archiveWithDump(
            "CREATE TABLE kept (id int);\nCOMMIT;\nCREATE TABLE after_commit (id int);\nSELECT 1/0;\n",
            'commit',
        );

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('script-level transaction control must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('transaction_control', $e->reason());
        }

        $this->assertSame(0, $this->objectCount($target), 'no table may survive');

        foreach (['BEGIN;', 'ROLLBACK;', 'START TRANSACTION;', 'ABORT;'] as $i => $statement) {
            $archive = $this->archiveWithDump("CREATE TABLE t (id int);\n{$statement}\n", 'txn'.$i);
            try {
                $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
                $this->fail($statement.' must be refused');
            } catch (RestoreFailure $e) {
                $this->assertSame('transaction_control', $e->reason(), $statement);
            }
        }
    }

    public function test_dangerous_text_inside_literals_comments_and_copy_data_is_not_misclassified(): void
    {
        $target = $this->createTargetDatabase();
        $policy = app(DumpScriptPolicy::class);

        $inert = [
            'sql_string' => "INSERT INTO t VALUES ('\\! touch /tmp/x');\n",
            'line_comment' => "-- \\! touch /tmp/x\nCREATE TABLE a (i int);\n",
            'block_comment' => "/* \\! nope\n COMMIT; */\nCREATE TABLE a (i int);\n",
            'dollar_body' => "CREATE FUNCTION f() RETURNS int AS \$\$\nBEGIN\n RETURN 1;\nEND;\n\$\$ LANGUAGE plpgsql;\n",
            'copy_data' => "COPY t (c) FROM stdin;\n\\! touch /tmp/x\nCOMMIT;\n\\.\nCREATE TABLE a (i int);\n",
            'case_end' => "SELECT CASE WHEN 1=1 THEN 2 ELSE 3 END;\n",
            'escape_string' => "INSERT INTO t VALUES (E'a\\\\'' \\! touch /tmp/x');\n",
        ];

        foreach ($inert as $name => $sql) {
            $file = $this->tmp.'/inert_'.$name.'.sql';
            file_put_contents($file, $sql);
            $policy->assertSafe($file); // must not throw
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->objectCount($target));
    }

    public function test_a_genuine_pg_dump_archive_including_restrict_framing_stays_compatible(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        // The real dump from the supported server: COPY blocks, `\.`
        // terminators, and pg_dump's own \restrict/\unrestrict framing.
        $work = $this->tmp.'/genuine';
        mkdir($work, 0700, true);
        exec('tar -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($work).' database.sql');
        $dump = $work.'/database.sql';
        $this->assertFileExists($dump);

        app(DumpScriptPolicy::class)->assertSafe($dump);
        $this->addToAssertionCount(1);

        // And it still restores end to end.
        $target = $this->createTargetDatabase();
        $this->assertSame(0, $this->restoreCommand($archive, $target), Artisan::output());
    }

    public function test_the_allowlist_accepts_only_the_exact_generated_restrict_form(): void
    {
        $policy = app(DumpScriptPolicy::class);

        $good = $this->tmp.'/restrict_ok.sql';
        file_put_contents($good, "\\restrict AbC123xyz\nCREATE TABLE a (i int);\n\\unrestrict AbC123xyz\n");
        $policy->assertSafe($good);
        $this->addToAssertionCount(1);

        foreach ([
            "\\restrict tok extra\n",
            "\\restrict tok; \\! touch /tmp/x\n",
            "\\restrict\n",
            "\\restrict tok\\$(id)\n",
        ] as $i => $sql) {
            $bad = $this->tmp.'/restrict_bad'.$i.'.sql';
            file_put_contents($bad, $sql);
            try {
                $policy->assertSafe($bad);
                $this->fail('malformed restrict must be refused: '.$sql);
            } catch (RestoreFailure $e) {
                $this->assertStringStartsWith('meta_command', $e->reason());
            }
        }
    }

    // ── Complete target emptiness (4.4) ────────────────────────────────────

    private function objectCount(string $database): int
    {
        return (int) $this->target($database)->scalar(
            "select count(*) from (
                select 1 from pg_class c join pg_namespace n on n.oid = c.relnamespace
                 where n.nspname not in ('pg_catalog','information_schema') and n.nspname !~ '^pg_'
                   and c.relkind in ('r','p','v','m','S','f')
                union all
                select 1 from pg_proc p join pg_namespace n on n.oid = p.pronamespace
                 where n.nspname not in ('pg_catalog','information_schema') and n.nspname !~ '^pg_'
                union all
                select 1 from pg_namespace n
                 where n.nspname not in ('pg_catalog','information_schema','public') and n.nspname !~ '^pg_'
            ) x",
        );
    }

    public function test_every_user_object_category_makes_a_target_non_empty(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        $objects = [
            'sequence' => ['create sequence zp_seq', "select count(*) from pg_class where relname='zp_seq'"],
            'matview' => ['create materialized view zp_mv as select 1 as x', "select count(*) from pg_class where relname='zp_mv'"],
            'function' => ['create function zp_fn() returns int as $$ select 1 $$ language sql', "select count(*) from pg_proc where proname='zp_fn'"],
            'schema' => ['create schema zp_custom', "select count(*) from pg_namespace where nspname='zp_custom'"],
        ];

        foreach ($objects as $label => [$create, $probe]) {
            $target = $this->createTargetDatabase();
            $this->target($target)->statement($create);

            try {
                $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
                $this->fail("a target holding a {$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category(), $label);
                $this->assertStringStartsWith('target_not_empty', $e->reason(), $label);
            }

            // The pre-existing object is untouched.
            $this->assertSame(1, (int) $this->target($target)->scalar($probe), $label);
            $this->dropTargetDatabase();
        }
    }

    // ── Checked cleanup (4.2) ──────────────────────────────────────────────

    public function test_cleanup_failures_are_reported_instead_of_being_swallowed(): void
    {
        $this->configureDatabaseOnlyBackup(true, 'Cleanup-Pass-1');
        $this->seedMarkers();
        $archive = $this->createBackup();

        foreach (['sql', 'archive', 'directory', 'throwing'] as $mode) {
            $target = $this->createTargetDatabase();

            $service = new class(app(DumpScriptPolicy::class)) extends DatabaseRestoreService
            {
                public string $mode = '';

                protected function unlinkFile(string $path): void
                {
                    if ($this->mode === 'sql' && str_ends_with($path, '.sql')) {
                        return; // simulate a failed unlink
                    }
                    if ($this->mode === 'archive' && str_ends_with($path, '.tar.gz')) {
                        return;
                    }
                    parent::unlinkFile($path);
                }

                protected function removeDirectory(string $path): void
                {
                    if ($this->mode === 'directory') {
                        return;
                    }
                    if ($this->mode === 'throwing') {
                        throw new \RuntimeException('rmdir blew up');
                    }
                    parent::removeDirectory($path);
                }
            };
            $service->mode = $mode;

            try {
                $this->asLeastPrivilegeRole(fn () => $service->restore($archive, $target, 'Cleanup-Pass-1'));
                $this->fail("unverified cleanup ({$mode}) must not be reported as success");
            } catch (RestoreFailure $e) {
                $this->assertSame(RestoreFailure::CATEGORY_CLEANUP, $e->category(), $mode);
                $this->assertContains($e->reason(), [
                    'dump_not_removed', 'archive_not_removed',
                    'work_directory_not_removed', 'rmdir_error',
                ], $mode);
                // The restore DID commit, so the operator must not be told the
                // target is unchanged, and must be warned off a blind rerun.
                $this->assertStringNotContainsString('هیچ تغییری', $e->publicMessage(), $mode);
                $this->assertStringNotContainsString($this->tmp, $e->publicMessage(), $mode);
            }

            // Clean the forced-leftover directory ourselves.
            foreach (glob(sys_get_temp_dir().'/zp-restore-*') ?: [] as $leftover) {
                exec('rm -rf '.escapeshellarg($leftover));
            }
            $this->dropTargetDatabase();
        }
    }

    public function test_a_successful_restore_verifies_that_no_plaintext_remains(): void
    {
        $before = glob(sys_get_temp_dir().'/zp-restore-*') ?: [];
        $this->configureDatabaseOnlyBackup(true, 'Verified-Pass-1');
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        $this->assertSame(0, $this->restoreCommand($archive, $target, 'Verified-Pass-1'), Artisan::output());
        $this->assertSame($before, glob(sys_get_temp_dir().'/zp-restore-*') ?: []);
    }

    // ── Sanitized unexpected failures + log injection (4.3) ────────────────

    public function test_an_unexpected_exception_is_sanitized_into_a_generic_failure(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        $service = new class(app(DumpScriptPolicy::class)) extends DatabaseRestoreService
        {
            protected function beforeRestore(): void
            {
                throw new \RuntimeException('SECRET-INTERNAL-DETAIL /srv/private/path');
            }
        };

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($archive, $target));
            $this->fail('an unexpected exception must surface as a typed internal failure');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_INTERNAL, $e->category());
            $this->assertStringNotContainsString('SECRET-INTERNAL-DETAIL', $e->publicMessage());
            $this->assertStringNotContainsString('/srv/private', $e->publicMessage());
        }

        foreach (glob(sys_get_temp_dir().'/zp-restore-*') ?: [] as $leftover) {
            exec('rm -rf '.escapeshellarg($leftover));
        }
    }

    public function test_a_hostile_archive_name_cannot_inject_log_lines_or_fields(): void
    {
        $logFile = $this->tmp.'/inject-probe.log';
        Config::set('logging.channels.zp_inject_probe', [
            'driver' => 'single', 'path' => $logFile, 'level' => 'debug',
        ]);
        Config::set('logging.default', 'zp_inject_probe');
        Log::forgetChannel('zp_inject_probe');

        // A filename carrying newlines, a fake log prefix, and control chars.
        $hostile = $this->tmp."/evil\n[2026-01-01 00:00:00] production.ERROR: FORGED\r\x07.tar.gz";
        file_put_contents($hostile, random_bytes(64));

        $target = $this->createTargetDatabase();
        $this->assertSame(1, $this->restoreCommand($hostile, $target));

        $contents = (string) file_get_contents($logFile);
        $this->assertNotSame('', $contents);

        // The label may still contain the attacker's WORDS — harmless inside a
        // JSON string. What must be impossible is STRUCTURE: a second record,
        // an extra line, or raw control characters.
        $this->assertSame(1, substr_count($contents, '[backup-restore]'), 'exactly one log record');
        $this->assertSame(1, count(array_filter(explode("\n", trim($contents)))), 'exactly one log line');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $contents, 'no control characters');
        $this->assertStringNotContainsString("\n[2026-01-01", $contents, 'no forged record start');
    }

    // ── COPY-state parser bypass (follow-up 4.1) ───────────────────────────

    public function test_query_form_copy_cannot_smuggle_a_shell_meta_command(): void
    {
        $target = $this->createTargetDatabase();
        $marker = $this->tmp.'/COPY_BYPASS_PWNED';

        // Reproduced against PostgreSQL 16.13 on the MERGED implementation:
        // the old matcher entered COPY-data mode for anything starting with
        // COPY that merely contained "FROM stdin". This is QUERY-form COPY
        // writing TO stdout, so psql never reads data — it executes the next
        // line as a client meta-command, and the marker file appeared.
        $archive = $this->archiveWithDump(
            "CREATE TABLE stdin (id int);\n"
            ."COPY (SELECT * FROM stdin) TO stdout;\n"
            .'\! touch '.escapeshellarg($marker)."\n"
            ."\\.\n",
            'copybypass',
        );

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('query-form COPY must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_CONTENT, $e->category());
            $this->assertSame('copy_form_unsupported', $e->reason());
            $this->assertStringNotContainsString('stdin', $e->publicMessage());
            $this->assertStringNotContainsString($this->tmp, $e->publicMessage());
        }

        $this->assertFileDoesNotExist($marker, 'no OS command may run');
        $this->assertSame(0, $this->objectCount($target), 'target must stay empty');
    }

    public function test_only_the_exact_table_form_copy_from_stdin_is_accepted(): void
    {
        $policy = app(DumpScriptPolicy::class);

        $refused = [
            'query_form' => 'COPY (SELECT 1) TO stdout;',
            'to_stdout' => 'COPY t TO stdout;',
            'from_program' => "COPY t FROM PROGRAM 'id';",
            'to_program' => "COPY t TO PROGRAM 'sh';",
            'server_file_in' => "COPY t FROM '/etc/passwd';",
            'server_file_out' => "COPY t TO '/tmp/out';",
            'binary_with' => 'COPY t FROM stdin WITH (format binary);',
            'malformed' => 'COPY;',
        ];

        foreach ($refused as $label => $sql) {
            $file = $this->tmp.'/copy_bad_'.$label.'.sql';
            file_put_contents($file, $sql."\n");
            try {
                $policy->assertSafe($file);
                $this->fail("{$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame('copy_form_unsupported', $e->reason(), $label);
            }
        }

        // Genuine forms pg_dump really emits, including mixed case, a
        // multiline column list, and quoted reserved-word identifiers.
        $accepted = [
            'plain' => "COPY public.users (id, name) FROM stdin;\n1\tbob\n\\.\n",
            'mixed_case' => "copy Public.Users (id) from STDIN;\n1\n\\.\n",
            'multiline' => "COPY public.t (\n  a,\n  b\n)\nFROM stdin;\n1\t2\n\\.\n",
            'quoted_ident' => "COPY public.\"order\" (id, \"select\") FROM stdin;\n1\t2\n\\.\n",
            'spaced_ident' => "COPY \"weird tbl\" FROM stdin;\ndata\n\\.\n",
            'in_comment' => "-- COPY (SELECT * FROM stdin) TO stdout;\nCREATE TABLE a (i int);\n",
            'in_string' => "INSERT INTO t VALUES ('COPY x FROM stdin');\n",
        ];

        foreach ($accepted as $label => $sql) {
            $file = $this->tmp.'/copy_ok_'.$label.'.sql';
            file_put_contents($file, $sql);
            $policy->assertSafe($file);
            $this->addToAssertionCount(1);
        }
    }

    // ── Truncated-prefix bypass + large COPY rows (follow-up 4.1.1 / 4.1.2) ─

    public function test_leading_padding_cannot_hide_security_relevant_tokens(): void
    {
        $target = $this->createTargetDatabase();
        $policy = app(DumpScriptPolicy::class);
        $pad = str_repeat(' ', 5_000); // past the old 4 KiB retained window

        $cases = [
            'commit' => [$pad."COMMIT;\n", 'transaction_control'],
            'rollback' => [$pad."ROLLBACK;\n", 'transaction_control'],
            'start_transaction' => [$pad."START TRANSACTION;\n", 'transaction_control'],
            'copy_query_form' => [$pad."COPY (SELECT * FROM stdin) TO stdout;\n", 'copy_form_unsupported'],
            'copy_program' => [$pad."COPY t FROM PROGRAM 'id';\n", 'copy_form_unsupported'],
            'copy_server_file' => [$pad."COPY t FROM '/etc/passwd';\n", 'copy_form_unsupported'],
        ];

        foreach ($cases as $label => [$sql, $reason]) {
            $file = $this->tmp.'/pad_'.$label.'.sql';
            file_put_contents($file, "CREATE TABLE a (i int);\n".$sql);
            try {
                $policy->assertSafe($file);
                $this->fail("padded {$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame($reason, $e->reason(), $label);
            }
        }

        // …and end to end: a padded query-form COPY hiding a shell command is
        // refused before psql, creates no marker, and leaves the target empty.
        $marker = $this->tmp.'/PAD_PWNED';
        $archive = $this->archiveWithDump(
            "CREATE TABLE stdin (id int);\n"
            .$pad."COPY (SELECT * FROM stdin) TO stdout;\n"
            .'\! touch '.escapeshellarg($marker)."\n\\.\n",
            'padbypass',
        );

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
            $this->fail('a padded bypass must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_CONTENT, $e->category());
        }

        $this->assertFileDoesNotExist($marker);
        $this->assertSame(0, $this->objectCount($target));
    }

    public function test_a_wide_copy_header_beyond_the_old_window_still_validates(): void
    {
        $columns = implode(', ', array_map(static fn (int $i): string => 'c'.$i, range(1, 1_200)));
        $this->assertGreaterThan(4_096, strlen($columns), 'the header must exceed the old retained window');

        $file = $this->tmp.'/wide_header.sql';
        file_put_contents($file, 'COPY public.t ('.$columns.") FROM stdin;\n1\n\\.\n");

        app(DumpScriptPolicy::class)->assertSafe($file);
        $this->addToAssertionCount(1);
    }

    public function test_copy_data_streams_without_a_line_limit_and_terminates_exactly(): void
    {
        $policy = app(DumpScriptPolicy::class);

        // A legitimate row larger than one megabyte — previously rejected as
        // `dump_line_too_long`, because the physical-line ceiling was applied
        // inside COPY data as well.
        $row = str_repeat('x', 2_097_152);
        $big = $this->tmp.'/big_copy_row.sql';
        file_put_contents($big, "COPY public.t (c) FROM stdin;\n".$row."\n\\.\n");
        $policy->assertSafe($big);
        $this->addToAssertionCount(1);

        // `\.x` is DATA, not a terminator.
        $dotx = $this->tmp.'/dot_x.sql';
        file_put_contents($dotx, "COPY public.t (c) FROM stdin;\n\\.x\nmore\n\\.\nCREATE TABLE a (i int);\n");
        $policy->assertSafe($dotx);
        $this->addToAssertionCount(1);

        // Terminator directly after a row that straddles the 64 KiB read chunk.
        $boundary = $this->tmp.'/copy_boundary.sql';
        file_put_contents($boundary, "COPY public.t (c) FROM stdin;\n".str_repeat('y', 65_530)."\n\\.\nCREATE TABLE a (i int);\n");
        $policy->assertSafe($boundary);
        $this->addToAssertionCount(1);

        // An unterminated COPY block is still refused.
        $unterminated = $this->tmp.'/copy_unterminated.sql';
        file_put_contents($unterminated, "COPY public.t (c) FROM stdin;\nrow1\nrow2\n");
        try {
            $policy->assertSafe($unterminated);
            $this->fail('an unterminated COPY block must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('dump_unterminated_copy', $e->reason());
        }

        // Oversized NON-COPY SQL is still refused.
        $oversized = $this->tmp.'/oversized_sql.sql';
        file_put_contents($oversized, 'SELECT '.str_repeat('a', 5_000_000).";\n");
        try {
            $policy->assertSafe($oversized);
            $this->fail('an oversized non-COPY line must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('dump_line_too_long', $e->reason());
        }
    }

    public function test_a_large_copy_row_survives_a_real_postgresql_round_trip(): void
    {
        $target = $this->createTargetDatabase();

        // Build the archive by hand so the row size is under our control, and
        // restore it through the real command into a real database.
        $blob = str_repeat('z', 1_500_000);
        $archive = $this->archiveWithDump(
            "CREATE TABLE big_rows (id integer, payload text);\n"
            ."CREATE TABLE migrations (id integer, migration text, batch integer);\n"
            ."CREATE TABLE users (id integer);\n"
            ."CREATE TABLE site_settings (id integer);\n"
            ."INSERT INTO migrations (id, migration, batch) VALUES (1, 'x', 1);\n"
            ."COPY public.big_rows (id, payload) FROM stdin;\n"
            ."1\t".$blob."\n\\.\n",
            'bigrow',
        );

        $this->assertSame(0, $this->restoreCommand($archive, $target), Artisan::output());

        $db = $this->target($target);
        $this->assertSame(1, (int) $db->scalar('select count(*) from big_rows'));
        $this->assertSame(
            strlen($blob),
            (int) $db->scalar('select length(payload) from big_rows where id = 1'),
            'the multi-megabyte COPY row must survive intact',
        );
    }

    // ── Authoritative role validation (follow-up 4.1.3) ────────────────────

    public function test_role_changing_sql_is_refused_by_the_dump_policy(): void
    {
        $policy = app(DumpScriptPolicy::class);

        foreach ([
            'set_role' => 'SET ROLE postgres;',
            'reset_role' => 'RESET ROLE;',
            'set_session_auth' => 'SET SESSION AUTHORIZATION postgres;',
            'reset_session_auth' => 'RESET SESSION AUTHORIZATION;',
            'set_local_role' => 'SET LOCAL ROLE postgres;',
            'padded_set_role' => str_repeat(' ', 5_000).'SET ROLE postgres;',
        ] as $label => $sql) {
            $file = $this->tmp.'/rolechange_'.$label.'.sql';
            file_put_contents($file, "CREATE TABLE a (i int);\n".$sql."\n");
            try {
                $policy->assertSafe($file);
                $this->fail("{$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame('role_change', $e->reason(), $label);
            }
        }
    }

    /**
     * PostgreSQL's lexer turns a comment into WHITESPACE, so a comment placed
     * between two keywords separates them rather than joining them. The
     * accumulator used to drop comments outright, which glued the keywords
     * together (`SETROLE`, `STARTTRANSACTION`) so neither statement-initial
     * guard matched — and both statements were accepted and then executed.
     */
    public function test_comments_cannot_split_a_dangerous_keyword_pair(): void
    {
        $policy = app(DumpScriptPolicy::class);

        $cases = [
            'block_set_role' => ['SET/**/ROLE postgres;', 'role_change'],
            'line_set_role' => ["SET--c\nROLE postgres;", 'role_change'],
            'block_local_role' => ['SET/**/LOCAL/**/ROLE postgres;', 'role_change'],
            'block_session_auth' => ['SET/**/SESSION/**/AUTHORIZATION postgres;', 'role_change'],
            'line_reset_role' => ["RESET--c\nROLE;", 'role_change'],
            'block_start_transaction' => ['START/**/TRANSACTION;', 'transaction_control'],
            'line_start_transaction' => ["START--c\nTRANSACTION;", 'transaction_control'],
            'block_prepare_transaction' => ["PREPARE/**/TRANSACTION 'x';", 'transaction_control'],
            'nested_block_set_role' => ['SET/* /*n*/ */ROLE postgres;', 'role_change'],
        ];

        foreach ($cases as $label => [$sql, $reason]) {
            $file = $this->tmp.'/commentsplit_'.$label.'.sql';
            file_put_contents($file, "CREATE TABLE a (i int);\n".$sql."\n");
            try {
                $policy->assertSafe($file);
                $this->fail("{$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame($reason, $e->reason(), $label);
            }
        }

        // Not a theoretical parse difference: PostgreSQL really executes both
        // of these, so accepting them was a genuine escape from the outer
        // transaction and from the least-privilege role.
        foreach (['SET/**/ROLE NONE;', "START--c\nTRANSACTION;"] as $sql) {
            $this->assertSame(
                0,
                $this->runSqlDirectly($sql),
                'PostgreSQL must actually accept this, or the test proves nothing',
            );
        }

        // The separator must not break legitimate dumps: comments really do
        // appear between tokens in pg_dump output.
        $benign = $this->tmp.'/commentsplit_benign.sql';
        file_put_contents(
            $benign,
            "CREATE/**/TABLE benign (id int);\nCOPY/**/benign (id) FROM stdin;\n1\n\\.\n"
            ."SELECT--c\n1;\n",
        );
        $policy->assertSafe($benign);
        $this->assertTrue(true, 'comment-separated legitimate SQL still validates');
    }

    /** Run one statement through psql as the app role; returns the exit code. */
    private function runSqlDirectly(string $sql): int
    {
        $connection = (string) config('database.default');
        $file = $this->tmp.'/direct_'.bin2hex(random_bytes(4)).'.sql';
        file_put_contents($file, $sql."\n");

        $process = new Process([
            'psql',
            '-h', (string) config("database.connections.{$connection}.host"),
            '-p', (string) config("database.connections.{$connection}.port"),
            '-U', (string) config("database.connections.{$connection}.username"),
            '-d', (string) config("database.connections.{$connection}.database"),
            '-v', 'ON_ERROR_STOP=1',
            '--no-psqlrc',
            '--no-password',
            '-q',
            '-f', $file,
        ], null, ['PGPASSWORD' => (string) config("database.connections.{$connection}.password")]);
        $process->run();

        return (int) $process->getExitCode();
    }

    public function test_a_set_capable_membership_is_refused_even_when_not_inherited(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        // NOINHERIT: the role holds no dangerous privilege right now, but it
        // can SET ROLE to one. `pg_has_role(…, 'member')` catches that; the
        // weaker 'usage' check would not.
        $role = 'zp_restore_setcap';
        DB::statement("drop role if exists {$role}");
        DB::statement("create role {$role} login password 'zp-setcap-pass' nosuperuser nocreatedb nocreaterole noinherit");
        DB::statement("grant pg_read_server_files to {$role}");
        DB::statement('grant all on database "'.$target.'" to '.$role);

        $connection = (string) config('database.default');
        $original = (array) config('database.connections.'.$connection);
        Config::set('database.connections.'.$connection.'.username', $role);
        Config::set('database.connections.'.$connection.'.password', 'zp-setcap-pass');
        DB::purge('zp_restore_target');

        try {
            app(DatabaseRestoreService::class)->restore($archive, $target);
            $this->fail('a SET-capable dangerous membership must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category());
            $this->assertSame('role_read_server_files', $e->reason());
            $this->assertStringNotContainsString($role, $e->publicMessage(), 'the role name must never leak');
        } finally {
            Config::set('database.connections.'.$connection, $original);
            DB::purge('zp_restore_target');
            DB::purge($connection);
            DB::statement("revoke pg_read_server_files from {$role}");
            DB::statement('revoke all on database "'.$target.'" from '.$role);
            DB::statement("drop role if exists {$role}");
        }

        $this->assertSame(0, $this->objectCount($target), 'nothing may be restored');
    }

    public function test_the_role_guard_runs_inside_the_psql_session_that_executes_the_dump(): void
    {
        // The guard travels as a `-c` argument on the SAME psql invocation as
        // `-f`, so the checked identity cannot differ from the executing one.
        $reflection = new \ReflectionClass(DatabaseRestoreService::class);
        $guard = (string) $reflection->getConstant('ROLE_GUARD_SQL');

        $this->assertStringContainsString('is_superuser', $guard);
        $this->assertStringContainsString('session_user', $guard);
        $this->assertStringContainsString('current_user', $guard);
        $this->assertStringContainsString("'member'", $guard, 'must use the SET-capable check');
        foreach (['pg_execute_server_program', 'pg_read_server_files', 'pg_write_server_files'] as $role) {
            $this->assertStringContainsString($role, $guard);
        }
        $this->assertStringContainsString('RAISE EXCEPTION', $guard);
    }

    // ── Content-identity pinning (follow-up 4.1.4) ─────────────────────────

    public function test_a_same_inode_same_size_rewrite_during_staging_is_detected(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $benign = $this->createBackup();
        $target = $this->createTargetDatabase();

        // Same file, same length, different bytes — device, inode and size all
        // still match, so only a content digest can catch this.
        $size = filesize($benign);
        $service = new class(app(DumpScriptPolicy::class)) extends DatabaseRestoreService
        {
            public string $path = '';

            public int $size = 0;

            protected function afterCopySource(): void
            {
                if ($this->path !== '') {
                    // Rewrite AFTER the bytes were copied: same inode, identical
                    // length, different content. dev/ino/size all still match.
                    $handle = fopen($this->path, 'r+b');
                    fwrite($handle, str_repeat('Z', $this->size));
                    fclose($handle);
                }
            }
        };
        $service->path = $benign;
        $service->size = (int) $size;

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($benign, $target));
            $this->fail('a same-inode same-size rewrite must be detected');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_STAGING, $e->category());
            $this->assertSame('source_content_changed', $e->reason());
            $this->assertStringNotContainsString($this->tmp, $e->publicMessage());
        }

        $this->assertSame(0, $this->objectCount($target), 'nothing may be restored');
    }

    public function test_a_symlinked_archive_path_is_refused(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $real = $this->createBackup();

        $link = $this->tmp.'/linked.tar.gz';
        symlink($real, $link);
        $target = $this->createTargetDatabase();

        try {
            $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($link, $target));
            $this->fail('a symlinked archive path must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('path_is_symlink', $e->reason());
        }

        $this->assertSame(0, $this->objectCount($target));
    }

    // ── Dedicated restore credentials (follow-up 4.1.6) ────────────────────

    public function test_partial_dedicated_restore_credentials_fail_closed(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        foreach ([
            ['zp_only_user', null],
            [null, 'zp-only-pass'],
        ] as [$user, $password]) {
            Config::set('database.backup_restore.username', $user);
            Config::set('database.backup_restore.password', $password);

            try {
                app(DatabaseRestoreService::class)->restore($archive, $target);
                $this->fail('partial dedicated credentials must fail closed');
            } catch (RestoreFailure $e) {
                $this->assertSame(RestoreFailure::CATEGORY_ENVIRONMENT, $e->category());
                $this->assertSame('restore_credentials_incomplete', $e->reason());
            }
        }

        Config::set('database.backup_restore.username', null);
        Config::set('database.backup_restore.password', null);
        $this->assertSame(0, $this->objectCount($target), 'nothing may be restored');
    }

    public function test_the_dedicated_credential_path_restores_end_to_end(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $markers = $this->seedMarkers();
        $archive = $this->createBackup();

        $this->ensureLeastPrivilegeRole();
        $target = $this->createTargetDatabase();

        // The operator-facing path: dedicated identity from configuration,
        // NOT a mutated application connection. Host/port/database inherit.
        Config::set('database.backup_restore.username', self::LP_ROLE);
        Config::set('database.backup_restore.password', self::LP_PASSWORD);

        try {
            $exit = Artisan::call('zedproxy:backup-restore', [
                'archive' => $archive,
                '--target-database' => $target,
            ]);
            $this->assertSame(0, $exit, Artisan::output());
        } finally {
            Config::set('database.backup_restore.username', null);
            Config::set('database.backup_restore.password', null);
        }

        $this->assertSame(
            $this->marker.'@example.com',
            $this->target($target)->table('users')->where('id', $markers['user']->id)->value('email'),
        );

        // The application role is still a superuser — proof the restore really
        // used the dedicated identity rather than falling back.
        $this->assertTrue((bool) DB::scalar("select current_setting('is_superuser') = 'on'"));
    }

    public function test_the_dedicated_credentials_are_readable_from_the_cached_config(): void
    {
        // config:cache serialises config/database.php; the keys must survive.
        $exported = require base_path('config/database.php');

        $this->assertArrayHasKey('backup_restore', $exported);
        $this->assertArrayHasKey('username', $exported['backup_restore']);
        $this->assertArrayHasKey('password', $exported['backup_restore']);
    }

    // ── Remaining catalog objects (follow-up 4.1.5) ────────────────────────

    public function test_publications_and_text_search_objects_make_a_target_non_empty(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        $objects = [
            'publication' => ['create publication zp_pub for all tables', "select count(*) from pg_publication where pubname='zp_pub'"],
            'ts_config' => ['create text search configuration zp_tsc (parser = default)', "select count(*) from pg_ts_config where cfgname='zp_tsc'"],
            'conversion' => ["create conversion zp_conv for 'LATIN1' to 'UTF8' from iso8859_1_to_utf8", "select count(*) from pg_conversion where conname='zp_conv'"],
        ];

        foreach ($objects as $label => [$create, $probe]) {
            $target = $this->createTargetDatabase();
            $this->target($target)->statement($create);

            try {
                $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
                $this->fail("a target holding a {$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category(), $label);
                $this->assertStringStartsWith('target_not_empty', $e->reason(), $label);
            }

            $this->assertSame(1, (int) $this->target($target)->scalar($probe), $label.' must be untouched');
            $this->dropTargetDatabase();
        }
    }

    // ── Immutable archive staging (follow-up 4.2) ──────────────────────────

    /**
     * Swap the operator path the instant the source handle is open. A staged
     * snapshot must be unaffected; the previous code reopened the path for
     * both `tar -tvzf` and `tar -xzf`.
     */
    private function swappingService(string $benign, string $malicious): DatabaseRestoreService
    {
        $service = new class(app(DumpScriptPolicy::class)) extends DatabaseRestoreService
        {
            public string $from = '';

            public string $to = '';

            protected function afterOpenSource(): void
            {
                if ($this->from !== '' && $this->to !== '') {
                    copy($this->from, $this->to);
                }
            }
        };
        $service->from = $malicious;
        $service->to = $benign;

        return $service;
    }

    public function test_a_plain_archive_is_staged_immutably_against_a_swap(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $markers = $this->seedMarkers();
        $benign = $this->createBackup();

        // A DIFFERENT but perfectly valid archive. It must be valid: a hostile
        // one would be caught by the dump validator, which would mask whether
        // staging worked at all. The tell is which dump actually ran.
        $swapped = $this->archiveWithDump("CREATE TABLE zp_swapped_in (id int);\n", 'swapplain');

        $target = $this->createTargetDatabase();
        $service = $this->swappingService($benign, $swapped);

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($benign, $target));

            // The STAGED snapshot is what ran: our markers are present …
            $this->assertSame(
                $this->marker.'@example.com',
                $this->target($target)->table('users')->where('id', $markers['user']->id)->value('email'),
                'the staged benign archive must be what was restored',
            );
            // … and nothing from the swapped-in archive exists.
            $this->assertSame(
                0,
                (int) $this->target($target)->scalar(
                    "select count(*) from information_schema.tables where table_name = 'zp_swapped_in'",
                ),
                'the swapped-in archive must never be extracted',
            );
        } catch (RestoreFailure $e) {
            // Failing closed is equally acceptable — what is forbidden is
            // inspecting one archive and extracting another.
            $this->assertContains($e->category(), [
                RestoreFailure::CATEGORY_STAGING,
                RestoreFailure::CATEGORY_CONTENT,
            ]);
            $this->assertSame(0, $this->objectCount($target));
        }
    }

    public function test_an_encrypted_archive_is_staged_immutably_against_a_swap(): void
    {
        $password = 'Swap-Pass-1';
        $this->configureDatabaseOnlyBackup(true, $password);
        $markers = $this->seedMarkers();
        $benign = $this->createBackup();

        // Encrypted under the SAME password, so decryption cannot be what
        // rejects it — only staging can.
        $plain = $this->archiveWithDump("CREATE TABLE zp_swapped_in (id int);\n", 'swapencplain');
        $swapped = $this->tmp.'/swapenc.tar.gz.enc';
        exec('ZP_SWAP_PASS='.escapeshellarg($password).' openssl enc -aes-256-cbc -salt -pbkdf2 -pass env:ZP_SWAP_PASS'
            .' -in '.escapeshellarg($plain).' -out '.escapeshellarg($swapped));
        $this->assertFileExists($swapped);

        $target = $this->createTargetDatabase();
        $service = $this->swappingService($benign, $swapped);

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($benign, $target, $password));

            $this->assertSame(
                $this->marker.'@example.com',
                $this->target($target)->table('users')->where('id', $markers['user']->id)->value('email'),
            );
            $this->assertSame(
                0,
                (int) $this->target($target)->scalar(
                    "select count(*) from information_schema.tables where table_name = 'zp_swapped_in'",
                ),
                'the swapped-in archive must never be decrypted or extracted',
            );
        } catch (RestoreFailure $e) {
            $this->assertContains($e->category(), [
                RestoreFailure::CATEGORY_STAGING,
                RestoreFailure::CATEGORY_CONTENT,
                RestoreFailure::CATEGORY_DECRYPTION,
            ]);
            $this->assertSame(0, $this->objectCount($target));
        }
    }

    public function test_a_swapped_in_hostile_archive_is_never_executed(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $benign = $this->createBackup();

        $marker = $this->tmp.'/SWAP_PWNED';
        $hostile = $this->archiveWithDump('\! touch '.escapeshellarg($marker)."\n", 'swaphostile');

        $target = $this->createTargetDatabase();
        $service = $this->swappingService($benign, $hostile);

        try {
            $this->asLeastPrivilegeRole(fn () => $service->restore($benign, $target));
        } catch (RestoreFailure) {
            // Either outcome is fine; the marker is the assertion that matters.
        }

        $this->assertFileDoesNotExist($marker, 'a swapped-in archive must never execute');
    }

    // ── Remaining catalog objects (follow-up 4.3) ──────────────────────────

    public function test_composite_types_collations_and_extensions_make_a_target_non_empty(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();

        // Each of these scored ZERO under the merged policy.
        $objects = [
            'composite' => ['create type zp_comp as (a int, b text)', "select count(*) from pg_type where typname='zp_comp'"],
            'domain' => ['create domain zp_dom as int', "select count(*) from pg_type where typname='zp_dom'"],
            'enum' => ["create type zp_enum as enum ('a')", "select count(*) from pg_type where typname='zp_enum'"],
            'range' => ['create type zp_range as range (subtype = int)', "select count(*) from pg_type where typname='zp_range'"],
            'collation' => ["create collation zp_coll (locale='C')", "select count(*) from pg_collation where collname='zp_coll'"],
            'extension' => ['create extension pg_trgm', "select count(*) from pg_extension where extname='pg_trgm'"],
        ];

        foreach ($objects as $label => [$create, $probe]) {
            $target = $this->createTargetDatabase();
            $this->target($target)->statement($create);

            try {
                $this->asLeastPrivilegeRole(fn () => app(DatabaseRestoreService::class)->restore($archive, $target));
                $this->fail("a target holding a {$label} must be refused");
            } catch (RestoreFailure $e) {
                $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category(), $label);
                $this->assertStringStartsWith('target_not_empty', $e->reason(), $label);
            }

            $this->assertSame(1, (int) $this->target($target)->scalar($probe), $label.' must be untouched');
            $this->dropTargetDatabase();
        }
    }

    public function test_a_fresh_database_is_still_accepted_as_empty(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        $this->assertSame(0, $this->objectCount($target), 'the baseline must score zero');
        $this->assertSame(0, $this->restoreCommand($archive, $target), Artisan::output());
    }

    // ── Bounded parsing (follow-up 4.4) ────────────────────────────────────

    public function test_the_parser_enforces_line_and_statement_limits(): void
    {
        $tiny = new DumpScriptPolicy(maxLineBytes: 64, maxStatementBytes: 128);

        $long = $this->tmp.'/long_line.sql';
        file_put_contents($long, 'SELECT '.str_repeat('a', 200).";\n");
        try {
            $tiny->assertSafe($long);
            $this->fail('an oversized physical line must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('dump_line_too_long', $e->reason());
        }

        // Many short lines, but no semicolon: the STATEMENT limit must bite.
        $runaway = $this->tmp.'/runaway_statement.sql';
        file_put_contents($runaway, "SELECT\n".str_repeat("a\n", 200));
        try {
            $tiny->assertSafe($runaway);
            $this->fail('an oversized semicolon-free statement must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('dump_statement_too_long', $e->reason());
        }

        // Exactly at the boundary is fine.
        $ok = $this->tmp.'/boundary.sql';
        file_put_contents($ok, str_pad('SELECT 1;', 60, ' ', STR_PAD_RIGHT)."\n");
        $tiny->assertSafe($ok);
        $this->addToAssertionCount(1);
    }

    public function test_lexical_state_survives_chunk_boundaries(): void
    {
        // The read chunk is 64 KiB, so pad each construct past it and prove the
        // classification is still correct on both sides.
        $pad = str_repeat('x', 70_000);
        $policy = app(DumpScriptPolicy::class);

        $inert = [
            'string' => "INSERT INTO t VALUES ('".$pad."\\! touch /tmp/x');\n",
            'block_comment' => '/* '.$pad."\n \\! touch /tmp/x COMMIT; */\nCREATE TABLE a (i int);\n",
            'dollar_body' => "CREATE FUNCTION f() RETURNS int AS \$\$\n".$pad."\nBEGIN RETURN 1; END;\n\$\$ LANGUAGE plpgsql;\n",
            'copy_data' => "COPY public.t (c) FROM stdin;\n".$pad."\n\\! touch /tmp/x\n\\.\nCREATE TABLE a (i int);\n",
        ];

        foreach ($inert as $label => $sql) {
            $file = $this->tmp.'/chunk_'.$label.'.sql';
            file_put_contents($file, $sql);
            $policy->assertSafe($file);
            $this->addToAssertionCount(1);
        }

        // A real meta-command just past a chunk boundary is still caught.
        $dangerous = $this->tmp.'/chunk_meta.sql';
        file_put_contents($dangerous, '-- '.$pad."\n\\! touch /tmp/x\n");
        try {
            $policy->assertSafe($dangerous);
            $this->fail('a meta-command near a chunk boundary must be refused');
        } catch (RestoreFailure $e) {
            $this->assertStringStartsWith('meta_command', $e->reason());
        }
    }

    // ── Restore-role privilege policy (follow-up 4.5) ──────────────────────

    public function test_a_dangerous_restore_role_is_refused_before_psql(): void
    {
        $this->configureDatabaseOnlyBackup(false);
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        // The suite's own CI user IS a superuser — restoring as it must be
        // refused, without naming the role anywhere.
        $this->assertTrue(
            (bool) DB::scalar("select current_setting('is_superuser') = 'on'"),
            'this assertion assumes the CI role is a superuser',
        );

        try {
            app(DatabaseRestoreService::class)->restore($archive, $target);
            $this->fail('a superuser restore role must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame(RestoreFailure::CATEGORY_TARGET, $e->category());
            $this->assertSame('role_superuser', $e->reason());
            $this->assertStringNotContainsString(
                (string) config('database.connections.'.config('database.default').'.username'),
                $e->publicMessage(),
                'the role name must never be exposed',
            );
        }

        $this->assertSame(0, $this->objectCount($target), 'nothing may be restored');

        // …and the least-privilege role succeeds on the very same archive.
        $this->assertSame(0, $this->restoreCommand($archive, $target), Artisan::output());
    }

    public function test_the_work_directory_is_removed_on_success_and_failure(): void
    {
        $before = glob(sys_get_temp_dir().'/zp-restore-*') ?: [];

        $this->configureDatabaseOnlyBackup(true, 'Cleanup-Verified-1');
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        $this->assertSame(0, $this->restoreCommand($archive, $target, 'Cleanup-Verified-1'), Artisan::output());
        $this->assertSame($before, glob(sys_get_temp_dir().'/zp-restore-*') ?: [], 'success must leave no work directory');

        $this->assertSame(1, $this->restoreCommand($archive, $target, 'Wrong-Pass'));
        $this->assertSame($before, glob(sys_get_temp_dir().'/zp-restore-*') ?: [], 'failure must leave no work directory');
    }
}
