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

    /** Create an EMPTY scratch database (the command never creates one). */
    private function createTargetDatabase(): string
    {
        $name = 'zp_restore_'.bin2hex(random_bytes(6));
        $this->assertSame(1, preg_match(DatabaseRestoreService::NAME_PATTERN, $name));

        DB::statement('CREATE DATABASE "'.$name.'"');
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
            return Artisan::call('zedproxy:backup-restore', [
                'archive' => $archive,
                '--target-database' => $database,
            ]);
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
            app(DatabaseRestoreService::class)->restore($archive, $current);
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
                app(DatabaseRestoreService::class)->restore($archive, (string) $name);
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
            app(DatabaseRestoreService::class)->restore($archive, $target);
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
            app(DatabaseRestoreService::class)->restore($archive, $target, 'Wrong-Pass-2');
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
            app(DatabaseRestoreService::class)->restore($corrupt, $target);
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
            $service->restore($linkArchive, $target);
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
            $service->restore($travArchive, $target);
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
            $service->restore($noDump, $target);
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
            $service->restore($nested, $target);
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
            app(DatabaseRestoreService::class)->restore($archive, $target);
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
            app(DatabaseRestoreService::class)->restore($archive, $target);
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
            app(DatabaseRestoreService::class)->restore($archive, $target);
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
            app(DatabaseRestoreService::class)->restore($archive, $target);
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
                app(DatabaseRestoreService::class)->restore($archive, $target);
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
            app(DatabaseRestoreService::class)->restore($archive, $target);
            $this->fail('script-level transaction control must be refused');
        } catch (RestoreFailure $e) {
            $this->assertSame('transaction_control', $e->reason());
        }

        $this->assertSame(0, $this->objectCount($target), 'no table may survive');

        foreach (['BEGIN;', 'ROLLBACK;', 'START TRANSACTION;', 'ABORT;'] as $i => $statement) {
            $archive = $this->archiveWithDump("CREATE TABLE t (id int);\n{$statement}\n", 'txn'.$i);
            try {
                app(DatabaseRestoreService::class)->restore($archive, $target);
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
                app(DatabaseRestoreService::class)->restore($archive, $target);
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
                $service->restore($archive, $target, 'Cleanup-Pass-1');
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
            $service->restore($archive, $target);
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

    public function test_the_work_directory_is_shredded_on_success_and_failure(): void
    {
        $before = glob(sys_get_temp_dir().'/zp-restore-*') ?: [];

        $this->configureDatabaseOnlyBackup(true, 'Shred-Pass-1');
        $this->seedMarkers();
        $archive = $this->createBackup();
        $target = $this->createTargetDatabase();

        $this->assertSame(0, $this->restoreCommand($archive, $target, 'Shred-Pass-1'), Artisan::output());
        $this->assertSame($before, glob(sys_get_temp_dir().'/zp-restore-*') ?: [], 'success must leave no work directory');

        $this->assertSame(1, $this->restoreCommand($archive, $target, 'Wrong-Pass'));
        $this->assertSame($before, glob(sys_get_temp_dir().'/zp-restore-*') ?: [], 'failure must leave no work directory');
    }
}
