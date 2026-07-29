<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\SupportTicket;
use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\WalletTransaction;
use App\Services\Telegram\DailyReportService;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The daily admin report is the number an operator reads at a glance and then
 * acts on — "did we take money yesterday", "are panels down", "did the backup
 * run". A silently wrong figure is worse than no report: it manufactures
 * confidence. It had no test coverage at all.
 *
 * Three classes of property are pinned here.
 *
 * **Which rows count.** Every figure is a filtered aggregate, and each filter
 * is a place a wrong row can slip in: an unpaid order in the sales total, a
 * pending top-up counted as completed, a debit counted as a credit.
 *
 * **The day boundary.** "Today" figures use `paid_at >= startOfDay()`. A row a
 * second before midnight must not count and a row a second after must, or the
 * report silently double-counts or under-counts at the exact moment an operator
 * is most likely to read it. `Carbon::setTestNow` fixes the clock so the
 * boundary is asserted, not assumed.
 *
 * **What it must never contain.** The report goes to a Telegram group. It is
 * "safe summaries / numbers only" by design, so a user's name, email, phone or
 * an order number appearing in it is a privacy defect.
 *
 * `send()` is additionally required never to throw: the daily scheduler must
 * not be taken down by a reporting failure.
 */
class DailyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the notifier throttle is cache-backed
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function report(): DailyReportService
    {
        return app(DailyReportService::class);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['wallet_balance_toman' => 0]);
    }

    private function paidOrder(User $user, int $amount, ?string $paidAt = null): Order
    {
        $plan = Plan::factory()->create(['price_toman' => $amount, 'is_active' => true]);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => $amount,
            'final_price_toman' => $amount,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at' => $paidAt ?? now(),
        ]);
    }

    private function unpaidOrder(User $user, int $amount): Order
    {
        $plan = Plan::factory()->create(['price_toman' => $amount, 'is_active' => true]);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => $amount,
            'final_price_toman' => $amount,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);
    }

    private function walletCredit(User $user, int $amount, string $type, string $status, ?string $createdAt = null): WalletTransaction
    {
        $tx = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman' => $amount,
            'balance_before_toman' => 0,
            'balance_after_toman' => $amount,
            'status' => $status,
        ]);

        if ($createdAt !== null) {
            $tx->forceFill(['created_at' => $createdAt])->save();
        }

        return $tx;
    }

    private function failedPayment(User $user, ?string $createdAt = null): PaymentTransaction
    {
        $tx = PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'status' => PaymentTransaction::STATUS_FAILED,
            'amount_toman' => 100000,
            'gateway_amount' => 100000,
            'gateway_currency' => 'TOMAN',
        ]);

        if ($createdAt !== null) {
            $tx->forceFill(['created_at' => $createdAt])->save();
        }

        return $tx;
    }

    // ── Sales ──────────────────────────────────────────────────────────────

    public function test_sales_counts_only_orders_paid_today(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();

        $this->paidOrder($user, 150000, '2026-07-28 09:00:00');
        $this->paidOrder($user, 250000, '2026-07-28 11:30:00');
        $this->paidOrder($user, 999000, '2026-07-27 23:00:00'); // yesterday
        $this->unpaidOrder($user, 777000);                      // never paid

        // A refunded/reverted order: it still carries today's `paid_at`, so the
        // day filter alone lets it through. Only the payment_status filter
        // keeps it out of revenue — without this row the test passes even with
        // that filter removed.
        $reverted = $this->paidOrder($user, 888000, '2026-07-28 10:00:00');
        $reverted->update(['payment_status' => Order::PAYMENT_UNPAID]);

        $text = $this->report()->buildText();

        $this->assertStringContainsString('💵 فروش: '.number_format(400000).' تومان', $text);
        $this->assertStringContainsString('✅ سفارش پرداخت‌شده: 2', $text);
        $this->assertStringNotContainsString(number_format(999000), $text);
        $this->assertStringNotContainsString(number_format(777000), $text);
        $this->assertStringNotContainsString(number_format(888000), $text);
    }

    public function test_the_sales_day_boundary_is_exact_at_midnight(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();

        // One second before today, and the very first second of today.
        $this->paidOrder($user, 500000, '2026-07-27 23:59:59');
        $this->paidOrder($user, 111000, '2026-07-28 00:00:00');

        $text = $this->report()->buildText();

        $this->assertStringContainsString('💵 فروش: '.number_format(111000).' تومان', $text);
        $this->assertStringContainsString('✅ سفارش پرداخت‌شده: 1', $text);
    }

    public function test_an_order_marked_paid_without_a_paid_at_is_not_counted(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();
        $plan = Plan::factory()->create(['price_toman' => 300000, 'is_active' => true]);

        // A data defect the report must not silently absorb into revenue.
        Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => 300000,
            'final_price_toman' => 300000,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at' => null,
        ]);

        $text = $this->report()->buildText();

        $this->assertStringContainsString('💵 فروش: 0 تومان', $text);
        $this->assertStringContainsString('✅ سفارش پرداخت‌شده: 0', $text);
    }

    // ── Failed payments ────────────────────────────────────────────────────

    public function test_failed_payments_counts_only_todays_failures(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();

        $this->failedPayment($user, '2026-07-28 08:00:00');
        $this->failedPayment($user, '2026-07-28 09:00:00');
        $this->failedPayment($user, '2026-07-27 22:00:00'); // yesterday

        // A successful payment must never be counted as a failure.
        PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'status' => PaymentTransaction::STATUS_APPROVED,
            'amount_toman' => 100000,
            'gateway_amount' => 100000,
            'gateway_currency' => 'TOMAN',
        ]);

        $this->assertStringContainsString('❌ پرداخت ناموفق: 2', $this->report()->buildText());
    }

    // ── Wallet top-ups ─────────────────────────────────────────────────────

    public function test_topups_count_only_completed_topup_credits_from_today(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();

        $this->walletCredit($user, 200000, WalletTransaction::TYPE_TOPUP, WalletTransaction::STATUS_COMPLETED, '2026-07-28 10:00:00');
        $this->walletCredit($user, 300000, WalletTransaction::TYPE_TOPUP, WalletTransaction::STATUS_COMPLETED, '2026-07-28 11:00:00');

        // Must NOT count: yesterday, a non-completed top-up, and a refund
        // (a credit, but not a top-up).
        $this->walletCredit($user, 900000, WalletTransaction::TYPE_TOPUP, WalletTransaction::STATUS_COMPLETED, '2026-07-27 20:00:00');
        $this->walletCredit($user, 800000, WalletTransaction::TYPE_TOPUP, 'pending', '2026-07-28 10:30:00');
        $this->walletCredit($user, 700000, WalletTransaction::TYPE_REFUND, WalletTransaction::STATUS_COMPLETED, '2026-07-28 10:45:00');

        $text = $this->report()->buildText();

        $this->assertStringContainsString('👛 شارژ کیف پول: '.number_format(500000).' تومان', $text);
        foreach ([900000, 800000, 700000] as $excluded) {
            $this->assertStringNotContainsString(number_format($excluded), $text);
        }
    }

    // ── Growth counters ────────────────────────────────────────────────────

    public function test_new_users_services_and_tickets_count_only_today(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        $yesterday = $this->makeUser();
        $yesterday->forceFill(['created_at' => '2026-07-27 10:00:00'])->save();

        $today = $this->makeUser();
        $today->forceFill(['created_at' => '2026-07-28 10:00:00'])->save();

        UserService::create([
            'service_number' => 'dr-svc-today',
            'user_id' => $today->id,
            'plan_name' => 'p',
            'status' => UserService::STATUS_ACTIVE,
            'traffic_total_gb' => 10,
            'duration_days' => 30,
        ]);
        UserService::create([
            'service_number' => 'dr-svc-old',
            'user_id' => $yesterday->id,
            'plan_name' => 'p',
            'status' => UserService::STATUS_ACTIVE,
            'traffic_total_gb' => 10,
            'duration_days' => 30,
        ])->forceFill(['created_at' => '2026-07-26 10:00:00'])->save();

        SupportTicket::create([
            'user_id' => $today->id,
            'subject' => 'today ticket',
            'status' => SupportTicket::STATUS_OPEN,
        ]);
        SupportTicket::create([
            'user_id' => $yesterday->id,
            'subject' => 'old ticket',
            'status' => SupportTicket::STATUS_CLOSED,
        ])->forceFill(['created_at' => '2026-07-20 10:00:00'])->save();

        $text = $this->report()->buildText();

        $this->assertStringContainsString('👥 کاربر جدید: 1', $text);
        $this->assertStringContainsString('🚀 سرویس جدید: 1', $text);
        $this->assertStringContainsString('🎫 تیکت جدید: 1', $text);
    }

    public function test_open_tickets_is_a_backlog_not_a_daily_figure(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();

        // Open statuses from BEFORE today still count — this is the backlog an
        // operator has to clear, not a count of today's arrivals.
        foreach ([
            SupportTicket::STATUS_OPEN,
            SupportTicket::STATUS_WAITING_ADMIN,
            SupportTicket::STATUS_WAITING_USER,
            SupportTicket::STATUS_ANSWERED,
        ] as $i => $status) {
            SupportTicket::create([
                'user_id' => $user->id,
                'subject' => 'backlog '.$i,
                'status' => $status,
            ])->forceFill(['created_at' => '2026-07-01 10:00:00'])->save();
        }

        // Closed never counts as open.
        SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'done',
            'status' => SupportTicket::STATUS_CLOSED,
        ])->forceFill(['created_at' => '2026-07-01 10:00:00'])->save();

        $text = $this->report()->buildText();

        $this->assertStringContainsString('🎫 تیکت جدید: 0 (باز: 4)', $text);
    }

    public function test_failed_operations_covers_every_failure_status(): void
    {
        $this->travelTo('2026-07-28 12:00:00');
        $user = $this->makeUser();

        foreach ([
            Order::STATUS_PROVISIONING_FAILED,
            Order::STATUS_RENEWAL_FAILED,
            Order::STATUS_ADDON_FAILED,
            Order::STATUS_FAILED,
        ] as $status) {
            $plan = Plan::factory()->create(['price_toman' => 1000, 'is_active' => true]);
            Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'price_toman' => 1000,
                'final_price_toman' => 1000,
                'status' => $status,
                'payment_status' => Order::PAYMENT_UNPAID,
            ]);
        }

        // A healthy order must not be counted as a failed operation.
        $this->paidOrder($user, 5000);

        $this->assertStringContainsString('⚠️ عملیات ناموفق: 4', $this->report()->buildText());
    }

    public function test_offline_panels_counts_only_active_offline_panels(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        $this->makePanel('down', true, VpnPanel::HEALTH_OFFLINE);
        $this->makePanel('up', true, VpnPanel::HEALTH_ONLINE);
        // A panel an operator has deliberately taken out of rotation is not an
        // incident — alerting on it trains people to ignore the report.
        $this->makePanel('retired', false, VpnPanel::HEALTH_OFFLINE);

        $this->assertStringContainsString('🖥 پنل آفلاین: 1', $this->report()->buildText());
    }

    private function makePanel(string $name, bool $active, string $health): VpnPanel
    {
        return VpnPanel::create([
            'name' => 'dr-panel-'.$name,
            'type' => 'marzban',
            'base_url' => 'https://panel-'.$name.'.example.com',
            'username' => 'u',
            'password' => 'p',
            'is_active' => $active,
            'health_status' => $health,
        ]);
    }

    // ── Backup line ────────────────────────────────────────────────────────

    public function test_the_backup_line_reports_no_backup_at_all(): void
    {
        $this->assertStringContainsString('💾 آخرین بکاپ: —', $this->report()->buildText());
    }

    public function test_the_backup_line_reports_a_successful_backup_with_its_size(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        BackupLog::create([
            'type' => BackupLog::TYPE_SCHEDULED,
            'status' => BackupLog::STATUS_SUCCESS,
            'file_path' => '/tmp/backup.tar.gz',
            'file_size' => 5 * 1048576,
        ]);

        $text = $this->report()->buildText();

        $this->assertStringContainsString('💾 آخرین بکاپ: 🟢 موفق (5 MB)', $text);
    }

    public function test_the_backup_line_reports_a_failed_backup(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        BackupLog::create([
            'type' => BackupLog::TYPE_SCHEDULED,
            'status' => BackupLog::STATUS_FAILED,
            'file_path' => '/tmp/backup.tar.gz',
            'file_size' => 0,
        ]);

        $text = $this->report()->buildText();

        $this->assertStringContainsString('💾 آخرین بکاپ: 🔴 ناموفق', $text);
        $this->assertStringNotContainsString('🟢 موفق', $text);
    }

    public function test_the_backup_line_reflects_the_latest_run_not_the_first(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        BackupLog::create([
            'type' => BackupLog::TYPE_SCHEDULED,
            'status' => BackupLog::STATUS_SUCCESS,
            'file_path' => '/tmp/old.tar.gz',
            'file_size' => 9 * 1048576,
        ])->forceFill(['created_at' => '2026-07-20 03:00:00', 'updated_at' => '2026-07-20 03:00:00'])->save();

        BackupLog::create([
            'type' => BackupLog::TYPE_SCHEDULED,
            'status' => BackupLog::STATUS_FAILED,
            'file_path' => '/tmp/new.tar.gz',
            'file_size' => 0,
        ]);

        $text = $this->report()->buildText();

        // Reporting a stale success while the most recent run failed is the
        // exact way a backup outage goes unnoticed.
        $this->assertStringContainsString('🔴 ناموفق', $text);
        $this->assertStringNotContainsString('9 MB', $text);
    }

    // ── Privacy ────────────────────────────────────────────────────────────

    public function test_the_report_is_numbers_only_and_leaks_no_personal_data(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        $user = $this->makeUser([
            'name' => 'Zahra Testperson',
            'username' => 'zahra_unique_handle',
            'email' => 'zahra.unique@example.com',
        ]);
        $order = $this->paidOrder($user, 150000);
        SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'my card number stopped working',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $text = $this->report()->buildText();

        foreach ([
            'Zahra Testperson',
            'zahra_unique_handle',
            'zahra.unique@example.com',
            'my card number stopped working',
            (string) $order->order_number,
            '/tmp/backup.tar.gz',
        ] as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $text, 'the daily report must never carry personal or path data');
            }
        }
    }

    // ── Delivery ───────────────────────────────────────────────────────────

    public function test_send_delivers_the_report_into_the_daily_report_topic(): void
    {
        Queue::fake();
        SiteSetting::set('telegram_admin_enabled', 'true');
        app(TelegramSettings::class)->storeToken('123456:TEST-TOKEN');
        SiteSetting::set('telegram_admin_chat_id', '-1001234567890');
        TelegramAdminTopic::seedDefaults();

        $this->travelTo('2026-07-28 12:00:00');
        $this->paidOrder($this->makeUser(), 150000);

        $this->report()->send();

        $log = TelegramAdminNotificationLog::where('event_key', 'daily_report')->latest('id')->first();

        $this->assertNotNull($log, 'the daily report must produce a delivery record');
        $this->assertSame('daily_report', $log->topic_key);
        $this->assertStringContainsString('💵 فروش: '.number_format(150000), (string) $log->message);
    }

    public function test_send_never_throws_when_delivery_fails(): void
    {
        // The daily scheduler runs this alongside other work. A reporting
        // failure must be logged and swallowed, never propagated.
        $this->mock(TelegramAdminNotifier::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('telegram is down'));
        });

        $this->report()->send();

        $this->assertTrue(true, 'send() returned normally despite a throwing notifier');
    }

    public function test_the_report_renders_on_a_completely_empty_system(): void
    {
        $this->travelTo('2026-07-28 12:00:00');

        $text = $this->report()->buildText();

        // Every figure must be a real zero, not a blank or a null-coalesced gap.
        foreach ([
            '💵 فروش: 0 تومان',
            '✅ سفارش پرداخت‌شده: 0',
            '❌ پرداخت ناموفق: 0',
            '👛 شارژ کیف پول: 0 تومان',
            '👥 کاربر جدید: 0',
            '🚀 سرویس جدید: 0',
            '🎫 تیکت جدید: 0 (باز: 0)',
            '⚠️ عملیات ناموفق: 0',
            '🖥 پنل آفلاین: 0',
            '💾 آخرین بکاپ: —',
        ] as $line) {
            $this->assertStringContainsString($line, $text);
        }
    }
}
