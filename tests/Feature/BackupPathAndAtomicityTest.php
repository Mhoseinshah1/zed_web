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
 * Covers: absolute-only path validation shared by Filament and runtime,
 * fail-closed behavior for invalid values inserted directly into the
 * database, verified private work directories, temp-name builds with one
 * atomic rename commitment, guaranteed temp cleanup on every exit path,
 * preservation of previously committed backups on new failures, and
 * sanitized (canary-free) operator-facing error messages.
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
        @exec('rm -rf '.escapeshellarg($this->tmp));
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

    // ── Successful lifecycle ─────────────────────────────────────────────────

    public function test_unencrypted_success_commits_final_archive_and_cleans_work_dir(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
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
    }

    public function test_encrypted_success_commits_only_the_encrypted_artifact(): void
    {
        if (! $this->hasTar() || ! $this->hasOpenssl()) {
            $this->markTestSkipped('tar/openssl not available');
        }
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_encrypt_enabled', 'true');
        app(BackupSettings::class)->storePassword('Test-Backup-Pass');

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);

        $encrypted = glob($this->tmp.'/zedproxy-backup-*.tar.gz.enc');
        $this->assertCount(1, $encrypted);
        $this->assertGreaterThan(0, filesize($encrypted[0]));

        // No committed plaintext archive, no leftover work dir.
        $this->assertSame([], glob($this->tmp.'/zedproxy-backup-*.tar.gz'));
        $this->assertSame([], glob($this->tmp.'/.work_*'));
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
        $this->assertStringStartsWith($this->tmp.'/zedproxy-backup-', (string) $result['path']);
        $this->assertSame([], glob($this->tmp.'/.work_*'));
    }

    // ── Failure injection at each lifecycle boundary ─────────────────────────

    public function test_archive_failure_cleans_work_dir_and_preserves_previous_backup(): void
    {
        $this->configureBot();
        $this->configureFileOnlyBackup();

        $previous = $this->tmp.'/zedproxy-backup-20240101-000000.tar.gz';
        file_put_contents($previous, 'previously-committed-backup');

        $svc = $this->partialService();
        $svc->shouldReceive('createArchive')->once()
            ->andThrow(BackupFailure::archive('tar failed: CANARY_TAR_DETAIL /work/dir/path'));

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
            ->andThrow(BackupFailure::encryption('openssl enc failed: CANARY_OPENSSL_DETAIL'));

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
            ->andThrow(BackupFailure::commit('rename to final backup name failed: CANARY_FS_DETAIL'));

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

    public function test_dump_failure_detail_reaches_server_log_but_no_operator_channel(): void
    {
        Log::spy();
        $this->configureBot();
        $this->configureFileOnlyBackup();
        SiteSetting::set('backup_include_database', 'true');

        $canary = 'password authentication failed for user "CANARY_DB_USER"';
        $svc = $this->partialService();
        $svc->shouldReceive('dumpDatabase')->once()
            ->andThrow(BackupFailure::dump('pg_dump failed: '.$canary));

        $result = $svc->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('پایگاه داده', (string) $result['error']);

        // Canary must not reach the result, the BackupLog, or Telegram…
        $this->assertStringNotContainsString('CANARY_DB_USER', (string) $result['error']);
        $this->assertStringNotContainsString('CANARY_DB_USER', (string) BackupLog::latestLog()->error);
        foreach ($this->notificationMessages() as $message) {
            $this->assertStringNotContainsString('CANARY_DB_USER', $message);
        }

        // …while the server log received the full technical detail.
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $msg, array $ctx = []) => str_contains((string) ($ctx['detail'] ?? ''), 'CANARY_DB_USER'),
        )->once();
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
