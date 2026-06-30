<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramAdminMessageJob;
use App\Models\SiteSetting;
use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use App\Models\User;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush(); // throttle uses the cache
    }

    private function configureBot(): void
    {
        SiteSetting::set('telegram_admin_enabled', 'true');
        app(TelegramSettings::class)->storeToken('123456:TEST-TOKEN');
        SiteSetting::set('telegram_admin_chat_id', '-1001234567890');
        TelegramAdminTopic::seedDefaults();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_settings_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/telegram/settings')
            ->assertSuccessful()
            ->assertSee('تنظیمات بات');
    }

    public function test_token_is_stored_encrypted_and_never_shown(): void
    {
        app(TelegramSettings::class)->storeToken('SECRET-TOKEN-123');

        $raw = (string) SiteSetting::get('telegram_bot_token', '');
        $this->assertNotSame('SECRET-TOKEN-123', $raw);                 // stored encrypted
        $this->assertSame('SECRET-TOKEN-123', Crypt::decryptString($raw));
        $this->assertSame('SECRET-TOKEN-123', app(TelegramSettings::class)->token());

        // The plaintext token must not appear on the settings page.
        $html = $this->actingAs($this->admin())->get('/zed-admin/telegram/settings')->getContent();
        $this->assertStringNotContainsString('SECRET-TOKEN-123', $html);
    }

    public function test_topic_mapping_saves_thread_id(): void
    {
        TelegramAdminTopic::seedDefaults();
        $topic = TelegramAdminTopic::findByKey('payments');
        $topic->update(['message_thread_id' => 42]);

        $this->assertSame(42, $topic->fresh()->message_thread_id);
    }

    public function test_send_queues_job_and_includes_thread_id(): void
    {
        Queue::fake();
        $this->configureBot();
        TelegramAdminTopic::findByKey('payments')->update(['message_thread_id' => 99]);

        $log = app(TelegramAdminNotifier::class)->event('payment_success', [
            'user' => 'علی', 'order' => 'ZP-1', 'amount' => '100,000', 'method' => 'درگاه',
        ]);

        Queue::assertPushed(SendTelegramAdminMessageJob::class);
        $row = TelegramAdminNotificationLog::where('event_key', 'payment_success')->first();
        $this->assertNotNull($row);
        $this->assertSame(TelegramAdminNotificationLog::STATUS_PENDING, $row->status);
        $this->assertSame(99, $row->message_thread_id);
        $this->assertSame('payments', $row->topic_key);
    }

    public function test_job_calls_api_with_thread_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]], 200)]);
        $this->configureBot();
        TelegramAdminTopic::findByKey('payments')->update(['message_thread_id' => 77]);

        app(TelegramAdminNotifier::class)->event('payment_success', ['user' => 'x', 'order' => 'ZP-2', 'amount' => '1', 'method' => 'm']);
        $log = TelegramAdminNotificationLog::where('event_key', 'payment_success')->firstOrFail();

        (new SendTelegramAdminMessageJob($log->id))->handle(app(\App\Services\Telegram\TelegramClient::class), app(TelegramSettings::class));

        $log->refresh();
        $this->assertSame(TelegramAdminNotificationLog::STATUS_SENT, $log->status);
        $this->assertSame(555, $log->telegram_message_id);
        Http::assertSent(fn ($req) => ($req['message_thread_id'] ?? null) === 77 && str_contains($req->url(), 'sendMessage'));
    }

    public function test_disabled_category_does_not_send(): void
    {
        Queue::fake();
        $this->configureBot();
        app(TelegramSettings::class)->setCategoryEnabled('payments', false);

        app(TelegramAdminNotifier::class)->event('payment_success', ['user' => 'a', 'order' => 'b', 'amount' => '1', 'method' => 'm']);

        Queue::assertNothingPushed();
        $log = TelegramAdminNotificationLog::where('event_key', 'payment_success')->firstOrFail();
        $this->assertSame(TelegramAdminNotificationLog::STATUS_MUTED, $log->status);
    }

    public function test_inactive_topic_does_not_send(): void
    {
        Queue::fake();
        $this->configureBot();
        TelegramAdminTopic::findByKey('payments')->update(['is_active' => false]);

        app(TelegramAdminNotifier::class)->event('payment_success', ['user' => 'a', 'order' => 'b', 'amount' => '1', 'method' => 'm']);

        Queue::assertNothingPushed();
        $this->assertSame(
            TelegramAdminNotificationLog::STATUS_MUTED,
            TelegramAdminNotificationLog::where('event_key', 'payment_success')->firstOrFail()->status,
        );
    }

    public function test_disabled_bot_logs_skipped(): void
    {
        Queue::fake();
        TelegramAdminTopic::seedDefaults(); // bot NOT enabled / no token

        app(TelegramAdminNotifier::class)->event('order_paid', ['user' => 'a', 'order' => 'b', 'plan' => 'p', 'amount' => '1']);

        Queue::assertNothingPushed();
        $this->assertSame(
            TelegramAdminNotificationLog::STATUS_SKIPPED,
            TelegramAdminNotificationLog::where('event_key', 'order_paid')->firstOrFail()->status,
        );
    }

    public function test_noisy_alert_is_throttled(): void
    {
        Queue::fake();
        $this->configureBot();

        // Same panel_down event for the same related repeated → second is muted.
        app(TelegramAdminNotifier::class)->send('panel_down', 'panels', 't', 'پنل قطع شد', 7);
        app(TelegramAdminNotifier::class)->send('panel_down', 'panels', 't', 'پنل قطع شد', 7);

        $logs = TelegramAdminNotificationLog::where('event_key', 'panel_down')->orderBy('id')->get();
        $this->assertSame(TelegramAdminNotificationLog::STATUS_PENDING, $logs[0]->status);
        $this->assertSame(TelegramAdminNotificationLog::STATUS_MUTED, $logs[1]->status);
        Queue::assertPushed(SendTelegramAdminMessageJob::class, 1);
    }

    public function test_telegram_failure_does_not_break_business_flow(): void
    {
        // API throws on every call; the notifier must swallow it.
        Http::fake(fn () => throw new \RuntimeException('network down'));
        $this->configureBot();

        // event() must never throw even when everything about Telegram fails.
        app(TelegramAdminNotifier::class)->event('order_paid', ['user' => 'a', 'order' => 'b', 'plan' => 'p', 'amount' => '1']);

        // The business-side log row still recorded the attempt.
        $this->assertDatabaseHas('telegram_admin_notification_logs', ['event_key' => 'order_paid']);
        $this->assertTrue(true); // reached here → no exception leaked
    }

    public function test_log_records_failed_when_api_errors(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
        $this->configureBot();

        app(TelegramAdminNotifier::class)->event('order_paid', ['user' => 'a', 'order' => 'b', 'plan' => 'p', 'amount' => '1']);
        $log = TelegramAdminNotificationLog::where('event_key', 'order_paid')->firstOrFail();

        try {
            (new SendTelegramAdminMessageJob($log->id))->handle(app(\App\Services\Telegram\TelegramClient::class), app(TelegramSettings::class));
        } catch (\Throwable $e) {
            (new SendTelegramAdminMessageJob($log->id))->failed($e);
        }

        $log->refresh();
        $this->assertSame(TelegramAdminNotificationLog::STATUS_FAILED, $log->status);
        $this->assertNotNull($log->error);
        // The error message must never leak the token.
        $this->assertStringNotContainsString('123456:TEST-TOKEN', (string) $log->error);
    }

    public function test_user_provided_text_is_escaped(): void
    {
        $this->configureBot();
        [$title, $message] = app(\App\Services\Telegram\TelegramTemplates::class)
            ->render('ticket_created', ['user' => '<b>hax</b>', 'ticket' => 'T-1', 'subject' => 'a<script>']);

        $this->assertStringContainsString('&lt;b&gt;hax&lt;/b&gt;', $message);
        $this->assertStringNotContainsString('<script>', $message);
    }

    public function test_resources_load_without_token_visible(): void
    {
        $this->configureBot();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/zed-admin/telegram-topics')->assertSuccessful();
        $this->actingAs($admin)->get('/zed-admin/telegram-templates')->assertSuccessful();
        $html = $this->actingAs($admin)->get('/zed-admin/telegram-notification-logs')->assertSuccessful()->getContent();
        $this->assertStringNotContainsString('123456:TEST-TOKEN', $html);
    }
}
