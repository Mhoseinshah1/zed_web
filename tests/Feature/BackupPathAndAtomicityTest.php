<?php

namespace Tests\Feature;

use App\Filament\Pages\BackupSettingsPage;
use App\Jobs\SendTelegramDocumentJob;
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
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        $log = BackupLog::latestLog();
        $this->assertSame(0, (int) ($log->metadata['cleaned'] ?? -1));
        $this->assertFalse((bool) ($log->metadata['cleanup_complete'] ?? true));
        $this->assertSame('unlink_failed', $log->metadata['cleanup_reason'] ?? null);
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

    // ── Fail-closed encryption configuration ─────────────────────────────────

    public function test_settings_page_refuses_first_encryption_activation_without_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm(['backup_encrypt_enabled' => true, 'backup_password_new' => null])
            ->call('save');

        // Refused before ANY persistence: "enabled without a password" is
        // never stored.
        $this->assertFalse(app(BackupSettings::class)->encryptEnabled());
        $this->assertSame(BackupSettings::PASSWORD_NONE, app(BackupSettings::class)->passwordState());
    }

    public function test_settings_page_activates_encryption_with_a_new_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm(['backup_encrypt_enabled' => true, 'backup_password_new' => 'Str0ng-Backup-Pass'])
            ->call('save');

        $this->assertTrue(app(BackupSettings::class)->encryptEnabled());
        $this->assertSame(BackupSettings::PASSWORD_OK, app(BackupSettings::class)->passwordState());
        $this->assertSame('Str0ng-Backup-Pass', app(BackupSettings::class)->password());
        // Stored encrypted, never plaintext.
        $this->assertNotSame('Str0ng-Backup-Pass', (string) SiteSetting::get('backup_password', ''));
    }

    public function test_settings_page_keeps_encryption_enabled_with_existing_valid_password(): void
    {
        app(BackupSettings::class)->storePassword('Existing-Pass');
        SiteSetting::set('backup_encrypt_enabled', 'true');
        $storedCiphertext = (string) SiteSetting::get('backup_password', '');
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm(['backup_encrypt_enabled' => true, 'backup_password_new' => null])
            ->call('save');

        // No re-entry required; the stored password is NOT overwritten.
        $this->assertTrue(app(BackupSettings::class)->encryptEnabled());
        $this->assertSame($storedCiphertext, (string) SiteSetting::get('backup_password', ''));
        $this->assertSame('Existing-Pass', app(BackupSettings::class)->password());
    }

    public function test_runtime_encryption_enabled_without_stored_password_fails_before_any_work(): void
    {
        Log::spy();
        $this->configureBot();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true'); // no password ever stored

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('رمز عبور', (string) $result['error']);
        // Failed BEFORE any external process / workspace / commitment.
        $this->assertSame([], glob($this->tmp.'/.work_*'));
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context = []) => ($context['category'] ?? null) === BackupFailure::CATEGORY_CONFIG
                && ($context['reason'] ?? null) === 'encryption_password_missing',
        );
    }

    public function test_runtime_encryption_with_corrupt_ciphertext_fails_closed(): void
    {
        Log::spy();
        $this->configureBot();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        SiteSetting::set('backup_password', 'CANARY-corrupt-ciphertext-blob');

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        // Distinguishable internally from "encryption disabled" AND from
        // "never stored" — via the reason code, not via any exposed detail.
        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context = []): bool {
                $blob = $message.' '.json_encode($context);

                return ($context['reason'] ?? null) === 'encryption_password_unreadable'
                    && ! str_contains($blob, 'CANARY');
            },
        );
        // No plaintext OR encrypted artifact was committed; nothing leaked.
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));
        $this->assertStringNotContainsString('CANARY', (string) $result['error']);
        $this->assertStringNotContainsString('CANARY', (string) BackupLog::latestLog()->error);
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString('CANARY', $message);
        }
    }

    public function test_runtime_encryption_with_foreign_app_key_ciphertext_fails_closed(): void
    {
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        // Valid ciphertext — but produced under a DIFFERENT application key
        // (key rotation / migrated database).
        $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
        SiteSetting::set('backup_password', $foreign->encryptString('Old-Pass'));

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('رمز عبور', (string) $result['error']);
        $this->assertSame(BackupSettings::PASSWORD_INVALID, app(BackupSettings::class)->passwordState());
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*'));
        $this->assertSame([], glob($this->tmp.'/.work_*'));
        // The toggle is NOT silently cleared and the ciphertext survives.
        $this->assertTrue(app(BackupSettings::class)->encryptEnabled());
        $this->assertNotSame('', (string) SiteSetting::get('backup_password', ''));
    }

    // ── Filament never renders the real archive path ─────────────────────────

    public function test_backup_status_page_shows_filename_but_never_the_absolute_path(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
        $this->configureFileOnlyBackup();

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);

        // Internal record keeps the REAL path for Telegram document dispatch.
        $log = BackupLog::latestLog();
        $this->assertSame($result['path'], $log->file_path);
        $this->assertFileExists((string) $log->file_path);

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get('/zed-admin/backup/settings')->getContent();

        // The page may show the archive BASENAME…
        $this->assertStringContainsString(basename((string) $log->file_path), $html);
        // …but never the absolute location (configured root, storage_path,
        // common server roots, or work-directory names).
        $this->assertStringNotContainsString($this->tmp, $html);
        $this->assertStringNotContainsString(storage_path(), $html);
        $this->assertStringNotContainsString('/var/www', $html);
        $this->assertStringNotContainsString('.work_', $html);
    }

    // ── Cleanup can never escape run() ───────────────────────────────────────

    public function test_cleanup_enumeration_exception_cannot_escape_run_and_keeps_committed_success(): void
    {
        Log::spy();
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $svc->shouldReceive('listDirChecked')->andThrow(new \RuntimeException('/secret/internal/path leaked via scandir'));

        $result = $svc->run(BackupLog::TYPE_MANUAL); // must NOT throw

        // The committed backup stays successful — cleanup failed AFTER commit.
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertFileExists((string) $result['path']);
        $this->assertSame(BackupLog::STATUS_SUCCESS, BackupLog::latestLog()->status);

        // Logged with safe fields only: stage, reason, exception CLASS — the
        // exception message (with its internal path) appears nowhere.
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context = []): bool {
                $blob = $message.' '.json_encode($context);

                return ($context['reason'] ?? null) === 'cleanup_exception'
                    && ($context['stage'] ?? null) === 'workdir_cleanup'
                    && ($context['exception'] ?? null) === \RuntimeException::class
                    && ! str_contains($blob, '/secret')
                    && ! str_contains($blob, $this->tmp);
            },
        );
    }

    public function test_cleanup_enumeration_exception_preserves_the_original_failure(): void
    {
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andThrow(BackupFailure::archive('process_failed', 2));
        $svc->shouldReceive('listDirChecked')->andThrow(new \RuntimeException('cleanup blew up too'));

        $result = $svc->run(BackupLog::TYPE_MANUAL); // must NOT throw

        // The ORIGINAL archive failure is what gets recorded — the cleanup
        // exception neither replaces it nor escapes.
        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('آرشیو', (string) $result['error']);
        $this->assertStringContainsString('آرشیو', (string) BackupLog::latestLog()->error);
    }

    public function test_cleanup_enumeration_returning_false_is_handled_without_failing_the_backup(): void
    {
        Log::spy();
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $svc->shouldReceive('listDirChecked')->andReturn(false);

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ($context['reason'] ?? null) === 'scandir_failed'
                && ! str_contains(json_encode($context) ?: '', $this->tmp),
        );
    }

    public function test_symlink_inside_work_dir_is_removed_as_a_link_and_never_followed(): void
    {
        $this->configureFileOnlyBackup();

        $outside = $this->tmp.'_other';
        mkdir($outside, 0777, true);
        file_put_contents($outside.'/victim.txt', 'must-survive-cleanup');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            function (string $dest) use ($outside): void {
                file_put_contents($dest, 'archive-bytes');
                // Malicious symlink planted inside the work directory,
                // pointing OUTSIDE it.
                symlink($outside, dirname($dest).'/evil_link');
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        // Cleanup removed the LINK, never traversed into its target.
        $this->assertSame([], glob($this->tmp.'/.work_*'));
        $this->assertFileExists($outside.'/victim.txt');
        $this->assertSame('must-survive-cleanup', file_get_contents($outside.'/victim.txt'));
    }

    // ── Canonical roots resolving to the filesystem root ─────────────────────

    public function test_symlinked_root_resolving_to_filesystem_root_is_rejected_before_workdir_creation(): void
    {
        $link = $this->tmp.'/rootlink';
        symlink('/', $link);
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $link);
        SiteSetting::set('backup_include_database', 'false');
        SiteSetting::set('backup_include_storage', 'true');

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('ریشه فایل‌سیستم', (string) $result['error']);
        // The static message does not echo the configured or resolved path.
        $this->assertStringNotContainsString($this->tmp, (string) $result['error']);
        // No filesystem-root children were ever attempted.
        $this->assertSame([], glob('/.work_*'));
        $this->assertSame([], glob('/zedproxy-backup-*'));
    }

    public function test_symlinked_root_resolving_to_a_valid_directory_stays_supported(): void
    {
        $real = $this->tmp.'/realroot';
        mkdir($real, 0777, true);
        $link = $this->tmp.'/goodlink';
        symlink($real, $link);
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $link);
        SiteSetting::set('backup_include_database', 'false');
        SiteSetting::set('backup_include_storage', 'true');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        // Committed inside the CANONICAL non-root target.
        $this->assertStringStartsWith((string) realpath($real).'/zedproxy-backup-', (string) $result['path']);
        $this->assertCount(1, glob($real.'/zedproxy-backup-*'));
        $this->assertSame([], glob($real.'/.work_*'));
    }

    // ── One-read one-decrypt encryption snapshot ─────────────────────────────

    public function test_encrypted_run_reads_and_decrypts_the_password_exactly_once(): void
    {
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Snap-Pass');

        // A second decryptString call anywhere in the run fails this
        // expectation — the state check and the password retrieval must be
        // ONE resolution, not two reads.
        Crypt::shouldReceive('decryptString')->once()->andReturn('Snap-Pass');

        $captured = null;
        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'plain-bytes'),
        );
        $svc->shouldReceive('encryptArchive')->once()->andReturnUsing(
            function (string $in, string $out, string $password) use (&$captured): void {
                $captured = $password;
                file_put_contents($out, 'enc-bytes');
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertSame('Snap-Pass', $captured);
    }

    public function test_settings_change_after_password_resolution_cannot_alter_the_run_password(): void
    {
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Original-Pass');

        $captured = null;
        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            function (string $dest): void {
                // Concurrent admin mid-run: replaces the password AND turns
                // encryption off. The running backup must not care.
                app(BackupSettings::class)->storePassword('Hijacked-Pass');
                SiteSetting::set('backup_encrypt_enabled', 'false');
                file_put_contents($dest, 'plain-bytes');
            },
        );
        $svc->shouldReceive('encryptArchive')->once()->andReturnUsing(
            function (string $in, string $out, string $password) use (&$captured): void {
                $captured = $password;
                file_put_contents($out, 'enc-bytes');
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // Still encrypted, still with the ORIGINAL immutable snapshot.
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertSame('Original-Pass', $captured);
        $this->assertStringEndsWith('.tar.gz.enc', (string) $result['path']);
    }

    public function test_password_storage_failure_leaves_previous_settings_state_intact(): void
    {
        $settings = Mockery::mock(BackupSettings::class)->makePartial();
        $settings->shouldReceive('encryptPassword')
            ->andThrow(new \RuntimeException('crypt backend unavailable'));
        $this->app->instance(BackupSettings::class, $settings);
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->fillForm([
                'backup_encrypt_enabled' => true,
                'backup_password_new' => 'Brand-New-Pass',
                'backup_enabled' => true,
            ])
            ->call('save');

        // The save aborted BEFORE any write: no toggle, no password, and no
        // unrelated setting moved.
        $this->assertFalse(app(BackupSettings::class)->encryptEnabled());
        $this->assertSame('', (string) SiteSetting::get('backup_password', ''));
        $this->assertFalse(app(BackupSettings::class)->enabled());
    }

    // ── Retention failure never invalidates a committed backup ───────────────

    public function test_retention_exception_after_commitment_keeps_success_and_telegram_delivery(): void
    {
        Log::spy();
        $this->configureBot();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_send_file_to_telegram', 'true');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $svc->shouldReceive('cleanupOld')->once()->andThrow(new \RuntimeException('retention blew up'));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // The atomic commitment is the success boundary: retention failure
        // may not downgrade the run.
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $log = BackupLog::latestLog();
        $this->assertSame(BackupLog::STATUS_SUCCESS, $log->status);
        $this->assertFileExists((string) $log->file_path);
        $this->assertFalse((bool) ($log->metadata['cleanup_complete'] ?? true));
        $this->assertSame('cleanup_exception', $log->metadata['cleanup_reason'] ?? null);

        // Success reporting AND document dispatch still happen.
        $this->assertDatabaseHas('telegram_admin_notification_logs', ['event_key' => 'backup_success']);
        Queue::assertPushed(SendTelegramDocumentJob::class);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ($context['reason'] ?? null) === 'cleanup_exception'
                && ($context['stage'] ?? null) === 'retention'
                && is_int($context['backup_log_id'] ?? null)
                && ! str_contains(json_encode($context) ?: '', $this->tmp),
        );
    }

    public function test_retention_stat_failure_keeps_success_and_preserves_candidates(): void
    {
        Log::spy();
        $this->configureFileOnlyBackup();

        $expired = $this->tmp.'/zedproxy-backup-20200101-000000.tar.gz';
        file_put_contents($expired, 'expired-backup');
        touch($expired, time() - 30 * 86400);

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $svc->shouldReceive('fileMtimeChecked')->andReturn(false);

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertFileExists((string) $result['path']);
        $this->assertFileExists($expired); // unreadable metadata ⇒ skipped, not deleted
        $log = BackupLog::latestLog();
        $this->assertSame(0, (int) ($log->metadata['cleaned'] ?? -1));
        $this->assertFalse((bool) ($log->metadata['cleanup_complete'] ?? true));
        $this->assertSame('stat_failed', $log->metadata['cleanup_reason'] ?? null);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ($context['reason'] ?? null) === 'stat_failed'
                && ! str_contains(json_encode($context) ?: '', $this->tmp),
        );
    }

    public function test_retention_enumeration_failure_keeps_success(): void
    {
        Log::spy();
        $this->configureFileOnlyBackup();

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $svc->shouldReceive('globBackups')->andReturn(false);

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertFileExists((string) $result['path']);
        $this->assertSame('glob_failed', BackupLog::latestLog()->metadata['cleanup_reason'] ?? null);
    }

    public function test_candidate_disappearing_between_enumeration_and_stat_is_a_non_event(): void
    {
        $this->configureFileOnlyBackup();

        $expired = $this->tmp.'/zedproxy-backup-20200101-000000.tar.gz';
        file_put_contents($expired, 'expired-backup');
        touch($expired, time() - 30 * 86400);

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $svc->shouldReceive('fileMtimeChecked')->andReturnUsing(
            function (string $path): int|false {
                unlink($path); // vanishes exactly between enumeration and stat

                return false;
            },
        );

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        // Already absent ⇒ deterministic non-event: complete, nothing counted.
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $log = BackupLog::latestLog();
        $this->assertSame(0, (int) ($log->metadata['cleaned'] ?? -1));
        $this->assertTrue((bool) ($log->metadata['cleanup_complete'] ?? false));
        $this->assertNull($log->metadata['cleanup_reason'] ?? null);
    }

    // ── Standalone cleanup shares the canonical-root resolver ────────────────

    public function test_standalone_cleanup_rejects_symlink_resolving_to_filesystem_root(): void
    {
        $link = $this->tmp.'/rootlink';
        symlink('/', $link);
        SiteSetting::set('backup_storage_path', $link);

        try {
            app(BackupService::class)->cleanupOld();
            $this->fail('standalone cleanup must reject a root resolving to /');
        } catch (BackupFailure $e) {
            $this->assertSame(BackupFailure::CATEGORY_CONFIG, $e->category());
            $this->assertStringContainsString('ریشه فایل‌سیستم', $e->publicMessage());
            $this->assertStringNotContainsString($this->tmp, $e->publicMessage());
        }

        // The Filament manual action surfaces it as a sanitized notification
        // instead of crashing — and never enumerates filesystem-root children.
        $admin = User::factory()->create(['is_admin' => true]);
        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->callAction('cleanupOld');
        $this->assertSame([], glob('/zedproxy-backup-*'));
    }

    public function test_standalone_cleanup_returns_zero_for_missing_directory_without_creating_it(): void
    {
        $absent = $this->tmp.'/never-created/subdir';
        SiteSetting::set('backup_storage_path', $absent);

        $outcome = app(BackupService::class)->cleanupOld();

        // Documented policy: standalone cleanup never creates the directory.
        $this->assertSame(['removed' => 0, 'complete' => true, 'reason' => null], $outcome);
        $this->assertDirectoryDoesNotExist($absent);
    }

    public function test_standalone_cleanup_and_backup_run_resolve_the_same_canonical_root(): void
    {
        $real = $this->tmp.'/realroot';
        mkdir($real, 0777, true);
        $link = $this->tmp.'/goodlink';
        symlink($real, $link);
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $link);
        SiteSetting::set('backup_include_database', 'false');
        SiteSetting::set('backup_include_storage', 'true');

        $expired = $real.'/zedproxy-backup-20200101-000000.tar.gz';
        file_put_contents($expired, 'expired-backup');
        touch($expired, time() - 30 * 86400);

        // Standalone cleanup works through the symlink on the SAME canonical
        // directory a backup run uses…
        $outcome = app(BackupService::class)->cleanupOld();
        $this->assertSame(1, $outcome['removed']);
        $this->assertTrue($outcome['complete']);
        $this->assertFileDoesNotExist($expired);

        // …and a run commits into that same canonical directory.
        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()->andReturnUsing(
            fn (string $dest) => file_put_contents($dest, 'archive-bytes'),
        );
        $result = $svc->run(BackupLog::TYPE_MANUAL);
        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertStringStartsWith((string) realpath($real).'/zedproxy-backup-', (string) $result['path']);
    }

    public function test_standalone_cleanup_with_invalid_stored_path_notifies_safely(): void
    {
        SiteSetting::set('backup_storage_path', 'relative-nonsense');

        $admin = User::factory()->create(['is_admin' => true]);
        Livewire::actingAs($admin)
            ->test(BackupSettingsPage::class)
            ->callAction('cleanupOld'); // handled: sanitized danger notification, no crash

        $this->assertDirectoryDoesNotExist(base_path('relative-nonsense'));
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
