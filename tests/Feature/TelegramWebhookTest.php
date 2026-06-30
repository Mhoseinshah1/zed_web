<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT  = '-1001234567890';
    private const ADMIN = '555000111';
    private const SECRET = 'super-secret-webhook-token';

    private function configureBot(): void
    {
        SiteSetting::set('telegram_admin_enabled', 'true');
        app(TelegramSettings::class)->storeToken('123456:TEST-TOKEN');
        SiteSetting::set('telegram_admin_chat_id', self::CHAT);
        SiteSetting::set('telegram_admin_user_ids', self::ADMIN);
        app(TelegramSettings::class)->storeWebhookSecret(self::SECRET);
    }

    private function update(string $text, ?string $fromId = self::ADMIN, ?string $chatId = self::CHAT): array
    {
        return [
            'update_id' => 1,
            'message'   => [
                'message_id' => 10,
                'from'       => ['id' => (int) $fromId, 'is_bot' => false, 'first_name' => 'Admin'],
                'chat'       => ['id' => (int) $chatId, 'type' => 'supergroup'],
                'text'       => $text,
            ],
        ];
    }

    private function callWebhook(array $update, ?string $secret = self::SECRET)
    {
        $headers = $secret !== null ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : [];
        return $this->postJson('/telegram/webhook', $update, $headers);
    }

    public function test_webhook_route_exists(): void
    {
        $this->assertNotNull(app('router')->getRoutes()->getByName('telegram.webhook'));
    }

    public function test_wrong_secret_is_forbidden(): void
    {
        $this->configureBot();
        Http::fake();

        $this->callWebhook($this->update('/status'), 'WRONG')->assertStatus(403);
        $this->callWebhook($this->update('/status'), null)->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_unauthorized_sender_is_ignored(): void
    {
        $this->configureBot();
        Http::fake();

        // Correct secret, but sender is not an allowed admin → 200, no reply.
        $this->callWebhook($this->update('/status', fromId: '999999'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_message_outside_group_is_ignored(): void
    {
        $this->configureBot();
        Http::fake();

        $this->callWebhook($this->update('/status', chatId: '-100999'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_allowed_admin_status_command_replies(): void
    {
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $this->callWebhook($this->update('/status'))->assertOk();

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'sendMessage')
                && str_contains((string) ($req['text'] ?? ''), 'وضعیت سیستم')
                && (string) $req['chat_id'] === self::CHAT;
        });
    }

    public function test_help_command_replies(): void
    {
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $this->callWebhook($this->update('/help@ZedBot'))->assertOk();
        Http::assertSent(fn ($req) => str_contains((string) ($req['text'] ?? ''), 'دستورهای بات'));
    }

    public function test_finance_today_returns_today_numbers(): void
    {
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $user = User::factory()->create();
        // Two paid orders today totalling 300,000.
        Order::factory()->create(['user_id' => $user->id, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(), 'final_price_toman' => 100000]);
        Order::factory()->create(['user_id' => $user->id, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(), 'final_price_toman' => 200000]);
        // A wallet top-up today.
        WalletTransaction::create([
            'user_id' => $user->id, 'type' => WalletTransaction::TYPE_TOPUP,
            'direction' => WalletTransaction::DIRECTION_CREDIT, 'status' => WalletTransaction::STATUS_COMPLETED,
            'amount_toman' => 50000, 'balance_after_toman' => 50000,
        ]);

        $this->callWebhook($this->update('/finance_today'))->assertOk();

        Http::assertSent(function ($req) {
            $text = (string) ($req['text'] ?? '');
            return str_contains($text, 'مالی امروز')
                && str_contains($text, number_format(300000))   // sales
                && str_contains($text, number_format(50000));   // topups
        });
    }

    public function test_backup_command_replies_when_backup_disabled(): void
    {
        // Phase 3 completed /backup; with backup disabled it tells the admin so.
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $this->callWebhook($this->update('/backup'))->assertOk();
        Http::assertSent(fn ($req) => str_contains((string) ($req['text'] ?? ''), 'غیرفعال'));
    }

    public function test_secret_is_stored_encrypted_and_hidden(): void
    {
        app(TelegramSettings::class)->storeWebhookSecret(self::SECRET);

        $raw = (string) SiteSetting::get('telegram_webhook_secret', '');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertSame(self::SECRET, Crypt::decryptString($raw));
        $this->assertSame(self::SECRET, app(TelegramSettings::class)->webhookSecret());

        // Settings page never prints the secret.
        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get('/zed-admin/telegram/settings')->getContent();
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    public function test_processing_error_returns_200_not_500(): void
    {
        $this->configureBot();
        // Telegram API blows up while replying — must still be a 200 to Telegram.
        Http::fake(fn () => throw new \RuntimeException('boom'));

        $this->callWebhook($this->update('/status'))->assertOk();
    }

    public function test_non_command_message_is_ignored(): void
    {
        $this->configureBot();
        Http::fake();

        $this->callWebhook($this->update('سلام'))->assertOk();
        Http::assertNothingSent();
    }
}
