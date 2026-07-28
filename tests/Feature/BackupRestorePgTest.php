<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\DatabaseRestoreService;
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
        $this->tmp = sys_get_temp_dir().'/zp-restore-it-'.bin2hex(random_bytes(6));
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
