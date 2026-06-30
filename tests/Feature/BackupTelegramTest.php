<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Jobs\SendTelegramAdminMessageJob;
use App\Models\BackupLog;
use App\Models\SiteSetting;
use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupTelegramTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
        $this->tmp = sys_get_temp_dir() . '/zpbk_' . uniqid();
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function configureBot(): void
    {
        SiteSetting::set('telegram_admin_enabled', 'true');
        app(TelegramSettings::class)->storeToken('123456:TEST-TOKEN');
        SiteSetting::set('telegram_admin_chat_id', '-1001234567890');
        SiteSetting::set('telegram_admin_user_ids', '555000111');
        TelegramAdminTopic::seedDefaults();
    }

    // ── Part 0: topic creation bug ───────────────────────────────────────────

    public function test_create_forum_topic_returns_thread_id(): void
    {
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_thread_id' => 321, 'name' => 'پرداخت‌ها']], 200)]);

        $res = app(TelegramClient::class)->createForumTopic('پرداخت‌ها');
        $this->assertSame(321, $res['message_thread_id']);
    }

    public function test_auto_create_topics_saves_thread_ids(): void
    {
        $this->configureBot();
        TelegramAdminTopic::query()->update(['message_thread_id' => null]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_thread_id' => 50]], 200)]);

        \Livewire\Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(\App\Filament\Pages\TelegramSettingsPage::class)
            ->callAction('autoCreateTopics');

        $this->assertSame(50, TelegramAdminTopic::findByKey('sales')->message_thread_id);
    }

    public function test_no_rights_error_throws_descriptive_exception(): void
    {
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'not enough rights to manage topics'], 400)]);

        $this->expectException(\RuntimeException::class);
        app(TelegramClient::class)->createForumTopic('x');
    }

    // ── Part 1/2: backup ─────────────────────────────────────────────────────

    public function test_backup_excludes_sensitive_files(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
        $src = $this->tmp . '/src';
        @mkdir($src, 0777, true);
        file_put_contents($src . '/.env', 'APP_KEY=secret');
        file_put_contents($src . '/server.key', 'PRIVATE');
        file_put_contents($src . '/normal.txt', 'hello');

        $archive = $this->tmp . '/out.tar.gz';
        app(BackupService::class)->createArchive($archive, [$src], app(BackupService::class)->excludePatterns());

        $list = [];
        exec('tar -tzf ' . escapeshellarg($archive), $list);
        $joined = implode("\n", $list);

        $this->assertStringContainsString('normal.txt', $joined);
        $this->assertStringNotContainsString('.env', $joined);
        $this->assertStringNotContainsString('server.key', $joined);
    }

    public function test_backup_success_sends_notification(): void
    {
        if (! $this->hasTar()) {
            $this->markTestSkipped('tar not available');
        }
        $this->configureBot();
        Queue::fake();

        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $this->tmp);
        SiteSetting::set('backup_include_database', 'false'); // no pg_dump in tests
        SiteSetting::set('backup_include_storage', 'true');

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_SUCCESS, $result['status']);
        $this->assertSame(BackupLog::STATUS_SUCCESS, BackupLog::latestLog()->status);
        $this->assertDatabaseHas('telegram_admin_notification_logs', ['event_key' => 'backup_success']);
    }

    public function test_backup_failure_sends_notification(): void
    {
        $this->configureBot();
        Queue::fake();

        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_storage_path', $this->tmp);
        // No sources selected → backup fails.
        SiteSetting::set('backup_include_database', 'false');
        SiteSetting::set('backup_include_storage', 'false');
        SiteSetting::set('backup_include_uploads', 'false');
        SiteSetting::set('backup_include_project_files', 'false');

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupLog::STATUS_FAILED, $result['status']);
        $this->assertDatabaseHas('telegram_admin_notification_logs', ['event_key' => 'backup_failed']);
    }

    public function test_file_over_limit_is_not_sent(): void
    {
        SiteSetting::set('backup_send_file_to_telegram', 'true');
        SiteSetting::set('backup_max_telegram_file_size_mb', '1');
        $service = app(BackupService::class);

        $this->assertTrue($service->fitsTelegramLimit(500 * 1024));        // 500KB ≤ 1MB
        $this->assertFalse($service->fitsTelegramLimit(2 * 1048576));      // 2MB  > 1MB
    }

    public function test_backup_password_is_encrypted_and_hidden(): void
    {
        app(BackupSettings::class)->storePassword('S3cret-Pass');

        $raw = (string) SiteSetting::get('backup_password', '');
        $this->assertNotSame('S3cret-Pass', $raw);
        $this->assertSame('S3cret-Pass', Crypt::decryptString($raw));
        $this->assertSame('S3cret-Pass', app(BackupSettings::class)->password());

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get('/zed-admin/backup/settings')->getContent();
        $this->assertStringNotContainsString('S3cret-Pass', $html);
    }

    // ── Part 3/4: commands + router ──────────────────────────────────────────

    public function test_daily_report_command_sends_report(): void
    {
        $this->configureBot();
        Queue::fake();

        $this->artisan('zedproxy:telegram-daily-report')->assertExitCode(0);

        $this->assertDatabaseHas('telegram_admin_notification_logs', ['event_key' => 'daily_report']);
    }

    public function test_backup_command_report_only(): void
    {
        $this->configureBot();
        Queue::fake();
        BackupLog::create(['type' => 'manual', 'status' => 'success', 'file_size' => 1024]);

        $this->artisan('zedproxy:backup --report-only')->assertExitCode(0);
        $this->assertDatabaseHas('telegram_admin_notification_logs', ['event_key' => 'backup_status']);
    }

    public function test_slash_backup_starts_backup_for_allowed_admin(): void
    {
        $this->configureBot();
        SiteSetting::set('telegram_admin_chat_id', '-1001234567890');
        SiteSetting::set('backup_enabled', 'true');
        app(TelegramSettings::class)->storeWebhookSecret('whsecret');
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $update = [
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 555000111],
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
                'text' => '/backup',
            ],
        ];

        $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->assertOk();

        Queue::assertPushed(RunBackupJob::class);
    }

    private function hasTar(): bool
    {
        return Process::run(['bash', '-lc', 'command -v tar'])->successful();
    }
}
