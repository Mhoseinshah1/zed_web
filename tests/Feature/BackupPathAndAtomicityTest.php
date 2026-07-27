<?php

namespace Tests\Feature;

use App\Filament\Pages\BackupSettingsPage;
use App\Models\BackupLog;
use App\Models\SiteSetting;
use App\Models\TelegramAdminTopic;
use App\Models\User;
use App\Services\Backup\BackupFailure;
use App\Services\Backup\BackupPathPolicy;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Backup storage-path policy + atomic archive lifecycle.
 *
 * Covers: absolute-only path validation on the RAW value (control characters
 * rejected before any trimming) shared by Filament and runtime, fail-closed
 * behavior for invalid values inserted directly into the database, verified
 * private work directories, temp-name builds with one atomic rename
 * commitment, the encrypted-run security boundary (no success with plaintext
 * residue), one immutable pinned root per run, checked filesystem operations
 * with per-criticality failure handling, preservation of previously
 * committed backups, and canary-free operator channels AND server logs.
 */
class BackupPathAndAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->tmp = sys_get_temp_dir().'/zpbk_atomic_'.bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp).' '.escapeshellarg($this->tmp.'_other'));
        parent::tearDown();
    }

    private function policy(): BackupPathPolicy
    {
        return app(BackupPathPolicy::class);
    }

    private function configureBot(): void
    {
        SiteSetting::set('telegram_admin_enabled', 'true');
        app(TelegramSettings::class)->storeToken('123456:TEST-TOKEN');
        SiteSetting::set('telegram_admin_chat_id', '-1001234567890');
        TelegramAdminTopic::seedDefaults();
    }

    /** File-only backup config writing into $this->tmp (no pg_dump). */
    private function configureFileOnlyBackup(): void
    {
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $this->tmp);
        SiteSetting::set('backup_include_database', 'false');
        SiteSetting::set('backup_include_storage', 'true');
    }

    /** A partial-mock service whose protected process seams can be stubbed. */
    private function partialService(): BackupService|Mockery\MockInterface
    {
        $svc = Mockery::mock(
            BackupService::class,
            [app(BackupSettings::class), app(TelegramAdminNotifier::class)],
        )->makePartial()->shouldAllowMockingProtectedMethods();

        return $svc;
    }

    private function hasTar(): bool
    {
        return Process::run(['bash', '-lc', 'command -v tar'])->successful();
    }

    private function hasOpenssl(): bool
    {
        return Process::run(['bash', '-lc', 'command -v openssl'])->successful();
    }

    /** @return array<int,string> all operator-facing telegram message bodies */
    private function notificationMessages(): array
    {
        return DB::table('telegram_admin_notification_logs')->pluck('message')->all();
    }

    // ── Path policy ──────────────────────────────────────────────────────────

    public function test_empty_path_resolves_to_application_default(): void
    {
        $this->assertSame(storage_path('app/backups'), $this->policy()->resolve(''));
        $this->assertSame(storage_path('app/backups'), $this->policy()->resolve('   '));
        $this->assertSame(storage_path('app/backups'), $this->policy()->resolve(null));
    }

    public function test_valid_absolute_path_is_normalized(): void
    {
        $this->assertSame('/var/backups/zed', $this->policy()->resolve('/var//backups///zed/'));
        $this->assertSame('/var/backups/zed', $this->policy()->resolve('  /var/backups/zed  '));
    }

    public function test_relative_path_is_rejected_and_never_auto_transformed(): void
    {
        try {
            $this->policy()->resolve('M.hosein');
            $this->fail('relative path must be rejected');
        } catch (BackupFailure $e) {
            $this->assertSame(BackupFailure::CATEGORY_CONFIG, $e->category());
            // Fail closed — NOT silently rewritten to /home/M.hosein or similar.
            $this->assertStringNotContainsString('M.hosein', $e->publicMessage());
            $this->assertStringContainsString('مطلق', $e->publicMessage());
        }
    }

    public function test_malformed_paths_are_rejected(): void
    {
        $invalid = [
            'relative/dir',
            './here',
            '../up',
            '/',
            '//',
            '/var/../etc',
            '/var/./x',
            "/tmp/\x00null",
            "/tmp/\x01ctl",
            "/tmp/with\nnewline",
        ];

        foreach ($invalid as $path) {
            try {
                $this->policy()->resolve($path);
                $this->fail('path must be rejected: '.bin2hex($path));
            } catch (BackupFailure $e) {
                $this->assertSame(BackupFailure::CATEGORY_CONFIG, $e->category(), bin2hex($path));
            }
        }
    }

    public function test_leading_and_trailing_control_characters_are_rejected_on_the_raw_value(): void
    {
        // Trimming must never turn an invalid value into a valid path: these
        // would all become "/tmp/ok" if a plain trim() ran before validation.
        $decorated = [
            "\x00/tmp/ok", "/tmp/ok\x00",
            "\n/tmp/ok", "/tmp/ok\n",
            "\r/tmp/ok", "/tmp/ok\r",
            "\t/tmp/ok", "/tmp/ok\t",
            "\x0B/tmp/ok", "/tmp/ok\x0B",
            "\x7F/tmp/ok", "/tmp/ok\x7F",
        ];

        foreach ($decorated as $raw) {
            foreach (['resolve', 'normalizeForStorage', 'validateAbsolute'] as $method) {
                try {
                    $this->policy()->{$method}($raw);
                    $this->fail("raw control character must be rejected by {$method}: ".bin2hex($raw));
                } catch (BackupFailure $e) {
                    $this->assertSame(BackupFailure::CATEGORY_CONFIG, $e->category(), $method.' '.bin2hex($raw));
                }
            }
        }
    }

    // ── Runtime fail-closed for values that bypassed the admin form ─────────

    public function test_runtime_fails_closed_on_invalid_stored_path_before_any_process_runs(): void
    {
        $this->configureBot();
        SiteSetting::set('backup_enabled', 'true');
        // Simulate the production incident: a bare relative value written
        // straight into the database (bypassing Filament validation).
        SiteSetting::set('backup_storage_path', 'M.hosein');
        SiteSetting::set('backup_include_database', 'true'); // pg_dump must never be reached

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('مطلق', (string) $result['error']);
        $this->assertStringNotContainsString('M.hosein', (string) $result['error']);

        // No CWD-relative directory was created anywhere near the app.
        $this->assertDirectoryDoesNotExist(base_path('M.hosein'));
        $this->assertDirectoryDoesNotExist(getcwd().'/M.hosein');

        // BackupLog + Telegram both carry only the sanitized message.
        $log = BackupLog::latestLog();
        $this->assertSame(BackupLog::STATUS_FAILED, $log->status);
        $this->assertStringNotContainsString('M.hosein', (string) $log->error);
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString('M.hosein', $message);
        }
    }

    public function test_runtime_rejects_stored_path_with_trailing_control_character(): void
    {
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', "/tmp/zpbk-evil\n");
        SiteSetting::set('backup_include_database', 'false');
        SiteSetting::set('backup_include_storage', 'true');

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('غیرمجاز', (string) $result['error']);
        $this->assertDirectoryDoesNotExist('/tmp/zpbk-evil');
    }

    // ── Successful lifecycle ─────────────────────────────────────────────────

    public function test_unencrypted_success_commits_final_archive_and_cleans_work_dir(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
        $this->configureBot();
        $this->configureFileOnlyBackup();

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);

        $finals = glob($this->tmp.'/zedproxy-backup-*.tar.gz');
        $this->assertCount(1, $finals);
        $this->assertGreaterThan(0, filesize($finals[0]));
        $this->assertSame($finals[0], $result['path']);
        $this->assertSame($finals[0], BackupLog::latestLog()->file_path);

        // Work dir (and with it every temp artifact) is gone.
        $this->assertSame([], glob($this->tmp.'/.work_*'));

        // Telegram messages never disclose filesystem paths: not the
        // configured root, not storage_path(), not a work-dir name.
        $messages = $this->notificationMessages();
        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString($this->tmp, $message);
            $this->assertStringNotContainsString(storage_path(), $message);
            $this->assertStringNotContainsString('.work_', $message);
        }
    }

    public function test_encrypted_success_commits_only_the_encrypted_artifact_with_no_plaintext_residue(): void
    {
        if (! $this->hasTar() || ! $this->hasOpenssl()) {
            $this->markTestSkipped('tar/openssl not available');
        }
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Test-Backup-Pass');

        // A plaintext canary travels through the source material so residue
        // would be detectable byte-for-byte.
        $canaryFile = storage_path('app/public/zpbk_plain_canary.txt');
        file_put_contents($canaryFile, 'ZP_PLAINTEXT_CANARY_'.bin2hex(random_bytes(8)));

        try {
            $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

            $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);

            $encrypted = glob($this->tmp.'/zedproxy-backup-*.tar.gz.enc');
            $this->assertCount(1, $encrypted);
            $this->assertGreaterThan(0, filesize($encrypted[0]));

            // No committed plaintext archive, no leftover work dir, no dump.
            $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*.tar.gz'));
            $this->assertSame([], glob($this->tmp.'/.work_*'));

            // NOTHING under the backup root contains the plaintext canary.
            $canary = (string) file_get_contents($canaryFile);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS));
            $scanned = 0;
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $scanned++;
                    $this->assertStringNotContainsString($canary, (string) file_get_contents($file->getPathname()), $file->getPathname());
                }
            }
            $this->assertGreaterThan(0, $scanned);
        } finally {
            @unlink($canaryFile);
        }
    }

    public function test_work_directory_is_private_collision_resistant_and_inside_root(): void
    {
        $this->configureFileOnlyBackup();

        $observed = [];
        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            function (string $dest) use (&$observed): void {
                $work = dirname($dest);
                $observed = [
                    'dest' => $dest,
                    'work' => $work,
                    'exists' => is_dir($work),
                    'perms' => fileperms($work) & 0777,
                    'real' => (string) realpath($work),
                ];
                file_put_contents($dest, 'archive-bytes');
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        // The archive was BUILT under a temp name inside the work dir…
        $this->assertMatchesRegularExpression('#/\.work_[0-9a-f]{16}/archive\.tar\.gz$#', $observed['dest']);
        $this->assertTrue($observed['exists']);
        // …the work dir is private (0700) and physically inside the root…
        $this->assertSame(0700, $observed['perms']);
        $this->assertStringStartsWith((string) realpath($this->tmp).'/', $observed['real']);
        // …and the commit was an atomic rename into the root.
        $this->assertFileExists((string) $result['path']);
        $this->assertStringStartsWith((string) realpath($this->tmp).'/zedproxy-backup-', (string) $result['path']);
        $this->assertSame([], glob($this->tmp.'/.work_*'));
    }

    // ── Encrypted-run security boundary ──────────────────────────────────────

    public function test_plaintext_deletion_failure_prevents_encrypted_success_and_commits_nothing(): void
    {
        $this->configureBot();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Test-Backup-Pass');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'plaintext-archive-bytes'),
        );
        $svc->shouldReceive('encryptArchive')->once()->andReturnUsing(
            fn (string $in, string $out) => file_put_contents($out, 'encrypted-bytes'),
        );
        // Every checked unlink fails: the plaintext can NOT be removed.
        $svc->shouldReceive('unlinkChecked')->andReturn(false);

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // The security boundary holds: no success, nothing committed.
        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('حفاظت', (string) $result['error']);
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));
        $this->assertSame(BackupLog::STATUS_FAILED, BackupLog::latestLog()->status);
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString($this->tmp, $message);
        }
    }

    public function test_work_dir_removal_failure_after_encryption_leaves_no_plaintext_and_keeps_success(): void
    {
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Test-Backup-Pass');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'PLAINTEXT_WORKDIR_CANARY'),
        );
        $svc->shouldReceive('encryptArchive')->once()->andReturnUsing(
            fn (string $in, string $out) => file_put_contents($out, 'encrypted-bytes'),
        );
        // Final housekeeping cannot remove the work dir at all.
        $svc->shouldReceive('removeDir')->andReturnNull();

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // Housekeeping failure does NOT fail the backup — because the
        // security-critical plaintext removal already happened BEFORE commit,
        // the leftover work dir contains no plaintext material.
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertCount(1, glob($this->tmp.'/zedproxy-backup-*.tar.gz.enc'));

        $leftover = [];
        foreach (glob($this->tmp.'/.work_*/*') ?: [] as $file) {
            $leftover[] = $file;
            $this->assertStringNotContainsString('PLAINTEXT_WORKDIR_CANARY', (string) file_get_contents($file), $file);
        }
        $this->assertSame([], $leftover, 'no plaintext temp files may survive an encrypted success');
    }

    // ── Failure injection at each lifecycle boundary ─────────────────────────

    public function test_root_preparation_failure_fails_closed_with_sanitized_permission_error(): void
    {
        $this->configureFileOnlyBackup();

        $policy = Mockery::mock(BackupPathPolicy::class)->makePartial();
        $policy->shouldReceive('ensureUsableRoot')->andThrow(
            BackupFailure::permission('امکان ساخت پوشه ذخیره بکاپ وجود ندارد. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.', 'root_mkdir_failed'),
        );
        $this->app->instance(BackupPathPolicy::class, $policy);

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('دسترسی', (string) $result['error']);
        $this->assertStringNotContainsString($this->tmp, (string) $result['error']);
        $this->assertSame([], glob($this->tmp.'/.work_*'));
    }

    public function test_work_dir_creation_failure_fails_closed(): void
    {
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('createWorkDir')->once()->andThrow(
            BackupFailure::permission('امکان ساخت پوشه کاری بکاپ وجود ندارد. دسترسی‌های مسیر ذخیره بکاپ را بررسی کنید.', 'workdir_mkdir_failed'),
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('پوشه کاری', (string) $result['error']);
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));
    }

    public function test_archive_failure_cleans_work_dir_and_preserves_previous_backup(): void
    {
        $this->configureBot();
        $this->configureFileOnlyBackup();

        $previous = $this->tmp.'/zedproxy-backup-20240101-000000.tar.gz';
        file_put_contents($previous, 'previously-committed-backup');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()
            ->andThrow(BackupFailure::archive('CANARY_TAR_DETAIL', 2));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('آرشیو', (string) $result['error']);
        $this->assertStringNotContainsString('CANARY_TAR_DETAIL', (string) $result['error']);

        // The previously committed backup is untouched; nothing new appeared.
        $this->assertSame('previously-committed-backup', file_get_contents($previous));
        $this->assertSame([$previous], glob($this->tmp.'/zedproxy-backup-*'));
        // Work dir cleaned even on failure.
        $this->assertSame([], glob($this->tmp.'/.work_*'));
    }

    public function test_encryption_failure_commits_nothing_and_cleans_up(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Test-Backup-Pass');

        $svc = $this->partialService();
        $svc->shouldReceive('encryptArchive')->once()
            ->andThrow(BackupFailure::encryption('CANARY_OPENSSL_DETAIL', 1));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('رمزگذاری', (string) $result['error']);
        $this->assertStringNotContainsString('CANARY_OPENSSL_DETAIL', (string) $result['error']);

        // Neither a plaintext nor an encrypted artifact was committed, and
        // the plaintext temp archive died with the work dir.
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));
        $this->assertSame([], glob($this->tmp.'/.work_*'));
    }

    public function test_commit_failure_reports_sanitized_error_and_leaves_no_partial_file(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
        $this->configureBot();
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('commitArchive')->once()
            ->andThrow(BackupFailure::commit('CANARY_FS_DETAIL'));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('نهایی‌سازی', (string) $result['error']);
        $this->assertStringNotContainsString('CANARY_FS_DETAIL', (string) $result['error']);
        $this->assertSame(BackupLog::STATUS_FAILED, BackupLog::latestLog()->status);

        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));
        $this->assertSame([], glob($this->tmp.'/.work_*'));
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString('CANARY_FS_DETAIL', $message);
        }
    }

    public function test_retention_and_housekeeping_delete_failures_do_not_fail_a_committed_backup(): void
    {
        $this->configureFileOnlyBackup();

        $expired = $this->tmp.'/zedproxy-backup-20200101-000000.tar.gz';
        file_put_contents($expired, 'expired-backup');
        touch($expired, time() - 30 * 86400);

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        // Every checked unlink fails: retention cannot delete the expired
        // archive and housekeeping cannot delete temp files.
        $svc->shouldReceive('unlinkChecked')->andReturn(false);

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // The new backup IS committed and stays successful; the failures are
        // non-critical (this is an unencrypted run — no plaintext boundary).
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertFileExists((string) $result['path']);
        $this->assertSame(0, (int) (BackupLog::latestLog()->metadata['cleaned'] ?? -1));
        $this->assertFileExists($expired); // never silently counted as removed
    }

    // ── Sanitized logging (server log included) ──────────────────────────────

    public function test_process_failure_logs_only_positive_listed_safe_fields(): void
    {
        Log::spy();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_include_database', 'true');

        $svc = $this->partialService();
        $svc->shouldReceive('dumpDatabase')->once()
            ->andThrow(BackupFailure::dump('process_failed', 1));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('پایگاه داده', (string) $result['error']);

        // The server log gets exactly the safe structured fields.
        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context = []): bool {
                return $message === 'Backup failed'
                    && ($context['category'] ?? null) === BackupFailure::CATEGORY_DUMP
                    && ($context['reason'] ?? null) === 'process_failed'
                    && ($context['exit_code'] ?? null) === 1
                    && is_int($context['backup_log_id'] ?? null)
                    && ($context['exception'] ?? null) === BackupFailure::class;
            },
        );
    }

    public function test_uncontrolled_exception_detail_reaches_no_channel_including_server_logs(): void
    {
        Log::spy();
        $this->configureBot();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_include_database', 'true');

        // An unexpected exception whose message carries everything the
        // logging policy forbids: stderr fragments, host, user, db, paths.
        $raw = 'pg_dump: error: connection to server at "CANARY_HOST.example" failed: '
            .'FATAL: password authentication failed for user "CANARY_DB_USER" '
            .'database "CANARY_DB_NAME" — /CANARY/ABSOLUTE/PATH/.work_x/database.sql';
        $svc = $this->partialService();
        $svc->shouldReceive('dumpDatabase')->once()->andThrow(new \RuntimeException($raw));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);

        // Operator channels: sanitized internal message only.
        $this->assertStringNotContainsString('CANARY', (string) $result['error']);
        $this->assertStringNotContainsString('CANARY', (string) BackupLog::latestLog()->error);
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString('CANARY', $message);
        }

        // SERVER LOG: the error entry carries the safe positive-listed
        // structure — and none of the raw text.
        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context = []): bool {
                $blob = $message.' '.json_encode($context);

                return ! str_contains($blob, 'CANARY')
                    && ($context['category'] ?? null) === BackupFailure::CATEGORY_INTERNAL
                    && ($context['reason'] ?? null) === 'unexpected_exception'
                    && ($context['exception'] ?? null) === \RuntimeException::class
                    && is_int($context['backup_log_id'] ?? null);
            },
        );
    }

    // ── One immutable root snapshot per run ──────────────────────────────────

    public function test_mid_run_storage_path_change_cannot_redirect_commit_retention_or_reporting(): void
    {
        $this->configureBot();
        $this->configureFileOnlyBackup();

        $other = $this->tmp.'_other';
        mkdir($other, 0777, true);
        $expiredPinned = $this->tmp.'/zedproxy-backup-20200101-000000.tar.gz';
        $expiredOther = $other.'/zedproxy-backup-20200101-000000.tar.gz';
        file_put_contents($expiredPinned, 'expired-in-pinned-root');
        file_put_contents($expiredOther, 'expired-in-other-root');
        touch($expiredPinned, time() - 30 * 86400);
        touch($expiredOther, time() - 30 * 86400);

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            function (string $dest) use ($other): void {
                // Concurrent admin action mid-run: the setting now points at
                // a DIFFERENT directory.
                SiteSetting::set('backup_storage_path', $other);
                file_put_contents($dest, 'archive-bytes');
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        // Commitment happened in the ORIGINAL pinned root.
        $this->assertStringStartsWith((string) realpath($this->tmp).'/zedproxy-backup-', (string) $result['path']);
        $this->assertSame([$expiredOther], glob($other.'/zedproxy-backup-*.tar.gz')); // nothing new appeared there
        // Retention ran ONLY against the pinned root.
        $this->assertFileDoesNotExist($expiredPinned);
        $this->assertFileExists($expiredOther);
        // Telegram disclosed NEITHER path.
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString($this->tmp, $message);
            $this->assertStringNotContainsString($other, $message);
        }
    }

    public function test_mid_run_change_to_an_invalid_path_does_not_fail_the_running_backup(): void
    {
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            function (string $dest): void {
                SiteSetting::set('backup_storage_path', 'now-invalid-relative');
                file_put_contents($dest, 'archive-bytes');
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // The run never re-reads the mutable setting: it completes against
        // the pinned root instead of failing on the new invalid value.
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertStringStartsWith((string) realpath($this->tmp).'/', (string) $result['path']);
        $this->assertSame([], glob($this->tmp.'/.work_*'));
    }

    // ── Filament page uses the same policy ──────────────────────────────────

    public function test_settings_page_rejects_relative_path(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm(['backup_storage_path' => 'relative/backups'])
            ->call('save')
            ->assertHasFormErrors(['backup_storage_path']);

        $this->assertSame('', (string) SiteSetting::get('backup_storage_path', ''));
    }

    public function test_settings_page_rejects_path_with_leading_control_character(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm(['backup_storage_path' => "\t".$this->tmp])
            ->call('save')
            ->assertHasFormErrors(['backup_storage_path']);

        $this->assertSame('', (string) SiteSetting::get('backup_storage_path', ''));
    }

    public function test_settings_page_stores_normalized_absolute_path(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm(['backup_storage_path' => $this->tmp.'//sub/'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->tmp.'/sub', (string) SiteSetting::get('backup_storage_path', ''));
    }
}
