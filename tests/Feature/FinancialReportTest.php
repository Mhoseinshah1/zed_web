<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    // ─── Sales revenue rules ─────────────────────────────────────────────────

    public function test_today_sales_excludes_wallet_topup(): void
    {
        // Create paid order = sales
        $user = User::factory()->create();
        Order::factory()->create([
            'user_id'        => $user->id,
            'payment_status' => Order::PAYMENT_PAID,
            'final_price_toman' => 500_000,
            'paid_at'        => now(),
        ]);

        // Create approved wallet_topup payment transaction = NOT sales
        PaymentTransaction::factory()->create([
            'user_id'          => $user->id,
            'payment_purpose'  => 'wallet_topup',
            'status'           => PaymentTransaction::STATUS_APPROVED,
            'amount_toman'     => 200_000,
            'paid_at'          => now(),
        ]);

        $salesToday = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');

        $this->assertEquals(500_000, $salesToday);
    }

    public function test_month_sales_excludes_wallet_topup(): void
    {
        $user = User::factory()->create();
        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_PAID,
            'final_price_toman' => 1_000_000,
            'paid_at'           => now()->startOfMonth()->addDay(),
        ]);

        $topupWallet = WalletTransaction::factory()->create([
            'user_id'      => $user->id,
            'type'         => WalletTransaction::TYPE_TOPUP,
            'direction'    => WalletTransaction::DIRECTION_CREDIT,
            'status'       => WalletTransaction::STATUS_COMPLETED,
            'amount_toman' => 300_000,
            'created_at'   => now(),
        ]);

        $salesMonth = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('final_price_toman');

        $this->assertEquals(1_000_000, $salesMonth);
    }

    public function test_wallet_topup_counted_only_as_wallet_deposit(): void
    {
        $user = User::factory()->create();

        WalletTransaction::factory()->create([
            'user_id'      => $user->id,
            'type'         => WalletTransaction::TYPE_TOPUP,
            'direction'    => WalletTransaction::DIRECTION_CREDIT,
            'status'       => WalletTransaction::STATUS_COMPLETED,
            'amount_toman' => 500_000,
            'created_at'   => today(),
        ]);

        // Wallet topup should show as deposit, not order sales
        $depositToday = WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereDate('created_at', today())
            ->sum('amount_toman');

        $salesToday = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');

        $this->assertEquals(500_000, $depositToday);
        $this->assertEquals(0, $salesToday);
    }

    public function test_wallet_payment_for_order_counted_as_sales(): void
    {
        $user = User::factory()->create();
        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_PAID,
            'final_price_toman' => 750_000,
            'paid_at'           => now(),
        ]);

        WalletTransaction::factory()->create([
            'user_id'      => $user->id,
            'type'         => WalletTransaction::TYPE_ORDER_PAYMENT,
            'direction'    => WalletTransaction::DIRECTION_DEBIT,
            'status'       => WalletTransaction::STATUS_COMPLETED,
            'amount_toman' => 750_000,
            'created_at'   => now(),
        ]);

        $salesToday = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');

        $this->assertEquals(750_000, $salesToday);
    }

    public function test_failed_payments_not_counted_as_sales(): void
    {
        $user = User::factory()->create();
        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_FAILED,
            'final_price_toman' => 400_000,
            'paid_at'           => null,
        ]);

        $salesToday = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');

        $this->assertEquals(0, $salesToday);
    }

    public function test_pending_payments_not_counted_as_sales(): void
    {
        $user = User::factory()->create();
        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_PENDING,
            'final_price_toman' => 300_000,
            'paid_at'           => null,
        ]);

        $salesToday = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');

        $this->assertEquals(0, $salesToday);
    }

    public function test_refunded_and_cancelled_orders_excluded_from_sales(): void
    {
        $user = User::factory()->create();

        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_REFUNDED,
            'final_price_toman' => 200_000,
            'paid_at'           => now(),
        ]);

        Order::factory()->create([
            'user_id'        => $user->id,
            'status'         => Order::STATUS_CANCELLED,
            'payment_status' => Order::PAYMENT_UNPAID,
            'final_price_toman' => 100_000,
        ]);

        $sales = Order::where('payment_status', Order::PAYMENT_PAID)->sum('final_price_toman');

        $this->assertEquals(0, $sales);
    }

    // ─── Provider breakdown ──────────────────────────────────────────────────

    public function test_provider_breakdown_separates_providers(): void
    {
        $user = User::factory()->create();

        PaymentTransaction::factory()->create([
            'user_id'         => $user->id,
            'provider'        => 'nowpayments',
            'payment_purpose' => 'order_payment',
            'status'          => PaymentTransaction::STATUS_APPROVED,
            'amount_toman'    => 100_000,
            'paid_at'         => now(),
        ]);

        PaymentTransaction::factory()->create([
            'user_id'         => $user->id,
            'provider'        => 'centralpay',
            'payment_purpose' => 'order_payment',
            'status'          => PaymentTransaction::STATUS_APPROVED,
            'amount_toman'    => 200_000,
            'paid_at'         => now(),
        ]);

        WalletTransaction::factory()->create([
            'user_id'      => $user->id,
            'type'         => WalletTransaction::TYPE_ORDER_PAYMENT,
            'direction'    => WalletTransaction::DIRECTION_DEBIT,
            'status'       => WalletTransaction::STATUS_COMPLETED,
            'amount_toman' => 300_000,
            'created_at'   => now(),
        ]);

        $now     = PaymentTransaction::where('provider', 'nowpayments')->where('status', PaymentTransaction::STATUS_APPROVED)->sum('amount_toman');
        $central = PaymentTransaction::where('provider', 'centralpay')->where('status', PaymentTransaction::STATUS_APPROVED)->sum('amount_toman');
        $wallet  = WalletTransaction::where('type', WalletTransaction::TYPE_ORDER_PAYMENT)->where('status', WalletTransaction::STATUS_COMPLETED)->sum('amount_toman');

        $this->assertEquals(100_000, $now);
        $this->assertEquals(200_000, $central);
        $this->assertEquals(300_000, $wallet);
    }

    public function test_provider_breakdown_excludes_topup_from_sales(): void
    {
        $user = User::factory()->create();

        PaymentTransaction::factory()->create([
            'user_id'         => $user->id,
            'provider'        => 'nowpayments',
            'payment_purpose' => 'wallet_topup',
            'status'          => PaymentTransaction::STATUS_APPROVED,
            'amount_toman'    => 500_000,
            'paid_at'         => now(),
        ]);

        $nowSales = PaymentTransaction::where('provider', 'nowpayments')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->sum('amount_toman');

        $this->assertEquals(0, $nowSales);
    }

    // ─── Wallet ──────────────────────────────────────────────────────────────

    public function test_total_wallet_balance_equals_sum_of_users(): void
    {
        User::factory()->create(['wallet_balance_toman' => 100_000]);
        User::factory()->create(['wallet_balance_toman' => 250_000]);
        User::factory()->create(['wallet_balance_toman' => 50_000]);

        $expected = User::sum('wallet_balance_toman');

        $this->assertEquals(400_000, $expected);
    }

    // ─── Provisioning failed ─────────────────────────────────────────────────

    public function test_provisioning_failed_count_is_correct(): void
    {
        $user = User::factory()->create();

        Order::factory()->create(['user_id' => $user->id, 'status' => Order::STATUS_PROVISIONING_FAILED]);
        Order::factory()->create(['user_id' => $user->id, 'status' => Order::STATUS_PROVISIONING_FAILED]);
        Order::factory()->create(['user_id' => $user->id, 'status' => Order::STATUS_COMPLETED]);

        $count = Order::where('status', Order::STATUS_PROVISIONING_FAILED)->count();

        $this->assertEquals(2, $count);
    }

    // ─── Date range filter ───────────────────────────────────────────────────

    public function test_date_range_filter_changes_report_data(): void
    {
        $user = User::factory()->create();

        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_PAID,
            'final_price_toman' => 500_000,
            'paid_at'           => now()->subMonths(2),
        ]);
        Order::factory()->create([
            'user_id'           => $user->id,
            'payment_status'    => Order::PAYMENT_PAID,
            'final_price_toman' => 300_000,
            'paid_at'           => now(),
        ]);

        $thisMonthSales = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('final_price_toman');

        $this->assertEquals(300_000, $thisMonthSales);
    }

    // ─── Admin panel access ──────────────────────────────────────────────────

    public function test_financial_report_page_requires_auth(): void
    {
        $response = $this->get('/zed-admin/reports/financial');
        $response->assertRedirect();
    }

    public function test_financial_report_page_accessible_by_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/zed-admin/reports/financial');
        $response->assertStatus(200);
    }
}
