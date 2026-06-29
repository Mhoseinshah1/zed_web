<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Services\Addons\ServiceAddonService;
use App\Services\Discounts\DiscountService;
use App\Services\Marzban\MarzbanClient;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\PaymentService;
use App\Services\Renewals\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers discount support across every real purchase flow:
 * new service, renewal, extra traffic and extra time.
 */
class DiscountAllFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('extra_traffic_price_per_gb', 1000);
        SiteSetting::set('extra_time_price_per_day', 2000);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['wallet_balance_toman' => 0], $attrs));
    }

    private function makePlan(int $price = 100000): Plan
    {
        return Plan::create([
            'name'            => 'پلن تخفیف',
            'slug'            => 'disc-' . uniqid(),
            'price_toman'     => $price,
            'duration_days'   => 30,
            'traffic_gb'      => 50,
            'is_active'       => true,
            'renewal_enabled' => true,
            'sort_order'      => 0,
        ]);
    }

    private function makeService(User $user, array $overrides = []): UserService
    {
        $plan = $this->makePlan();
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 5,
            'expires_at'       => now()->addDays(10),
        ], $overrides));
    }

    private function code(array $attrs = []): DiscountCode
    {
        return DiscountCode::create(array_merge([
            'code'                 => 'SAVE10',
            'type'                 => DiscountCode::TYPE_PERCENT,
            'value'                => 10,
            'is_active'            => true,
            'per_user_usage_limit' => 5,
        ], $attrs));
    }

    private function paidTx(Order $order, User $user): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id'        => $order->id,
            'user_id'         => $user->id,
            'provider'        => 'wallet',
            'status'          => PaymentTransaction::STATUS_PENDING,
            'amount_toman'    => $order->final_price_toman,
            'payment_purpose' => 'order_payment',
        ]);
    }

    // ── Discount applies on every order type ─────────────────────────────────

    public function test_discount_applies_to_new_service_order(): void
    {
        $code  = $this->code();
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = Order::create([
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => 100000,
            'final_price_toman' => 100000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);

        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $this->assertSame(10000, $order->discount_toman);
        $this->assertSame(90000, $order->final_price_toman);
        $this->assertSame('SAVE10', $order->discount_code);
    }

    public function test_discount_applies_to_renewal_order(): void
    {
        $code    = $this->code();
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(100000);

        $order = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $this->assertSame(Order::TYPE_RENEWAL, $order->order_type);
        $this->assertSame(10000, $order->discount_toman);
        $this->assertSame(90000, $order->final_price_toman);
    }

    public function test_discount_applies_to_extra_traffic_order(): void
    {
        $code    = $this->code();
        $user    = $this->makeUser();
        $service = $this->makeService($user);

        $order = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        // 20 GB * 1000 = 20000; 10% off = 2000
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $this->assertSame(Order::TYPE_EXTRA_TRAFFIC, $order->order_type);
        $this->assertSame(2000, $order->discount_toman);
        $this->assertSame(18000, $order->final_price_toman);
    }

    public function test_discount_applies_to_extra_time_order(): void
    {
        $code    = $this->code();
        $user    = $this->makeUser();
        $service = $this->makeService($user);

        $order = app(ServiceAddonService::class)->createExtraTimeOrder($service, 10, $user);
        // 10 days * 2000 = 20000; 10% off = 2000
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $this->assertSame(Order::TYPE_EXTRA_TIME, $order->order_type);
        $this->assertSame(2000, $order->discount_toman);
        $this->assertSame(18000, $order->final_price_toman);
    }

    // ── allowed_order_types restriction ──────────────────────────────────────

    public function test_allowed_order_types_restriction_blocks_other_types(): void
    {
        $code    = $this->code(['allowed_order_types' => [Order::TYPE_NEW_SERVICE]]);
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(100000);

        $order  = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
        $result = app(DiscountService::class)->validateCode($user, $order, 'SAVE10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('این نوع خرید', $result['message']);
    }

    public function test_allowed_order_types_allows_listed_type(): void
    {
        $code    = $this->code(['allowed_order_types' => [Order::TYPE_EXTRA_TRAFFIC]]);
        $user    = $this->makeUser();
        $service = $this->makeService($user);

        $order  = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        $result = app(DiscountService::class)->validateCode($user, $order, 'SAVE10');

        $this->assertTrue($result['valid']);
    }

    public function test_empty_allowed_order_types_works_for_all_types(): void
    {
        $code    = $this->code(['allowed_order_types' => null]);
        $user    = $this->makeUser();
        $service = $this->makeService($user);

        $order  = app(ServiceAddonService::class)->createExtraTimeOrder($service, 10, $user);
        $result = app(DiscountService::class)->validateCode($user, $order, 'SAVE10');

        $this->assertTrue($result['valid']);
    }

    public function test_allowed_plan_ids_checks_addon_target_service_plan(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        // Restrict the code to a DIFFERENT plan than the service's plan.
        $otherPlan = $this->makePlan();
        $code      = $this->code(['allowed_plan_ids' => [$otherPlan->id]]);

        $order  = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        $result = app(DiscountService::class)->validateCode($user, $order, 'SAVE10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('پلن', $result['message']);
    }

    // ── Wallet top-up exclusion ──────────────────────────────────────────────

    public function test_discount_does_not_apply_to_wallet_topup(): void
    {
        // Wallet top-ups are never Orders, so no discount path exists for them.
        // A code restricted to real purchase types must reject a top-up "type".
        $code = $this->code(['allowed_order_types' => [
            Order::TYPE_NEW_SERVICE,
            Order::TYPE_RENEWAL,
            Order::TYPE_EXTRA_TRAFFIC,
            Order::TYPE_EXTRA_TIME,
        ]]);

        $this->assertFalse($code->allowsOrderType('wallet_topup'));
        $this->assertTrue($code->allowsOrderType(Order::TYPE_NEW_SERVICE));
    }

    // ── Payment uses final_amount ────────────────────────────────────────────

    public function test_wallet_deducts_final_amount_after_discount(): void
    {
        $code    = $this->code(); // 10%
        $user    = $this->makeUser(['wallet_balance_toman' => 100000]);
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $order = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');
        $this->assertSame(18000, $order->final_price_toman);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(PaymentService::class)->payWithWallet($order->fresh(), $user->fresh());

        // Wallet deducted final amount (18000), not the original 20000.
        $this->assertSame(82000, $user->fresh()->wallet_balance_toman);
        $this->assertDatabaseHas('wallet_transactions', [
            'order_id'     => $order->id,
            'amount_toman' => 18000,
        ]);
    }

    public function test_payment_transaction_amount_uses_final_amount(): void
    {
        $code  = $this->code();
        $user  = $this->makeUser(['wallet_balance_toman' => 500000]);
        $plan  = $this->makePlan(100000);
        $order = Order::create([
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => 100000,
            'final_price_toman' => 100000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);

        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $tx = app(PaymentService::class)->payWithWallet($order->fresh(), $user->fresh());

        $this->assertSame(90000, (int) $tx->amount_toman);
    }

    // ── Redemption lifecycle ─────────────────────────────────────────────────

    public function test_redemption_reserved_on_apply_then_used_after_payment(): void
    {
        $code    = $this->code();
        $user    = $this->makeUser(['wallet_balance_toman' => 100000]);
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $order = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $this->assertDatabaseHas('discount_redemptions', [
            'order_id' => $order->id,
            'status'   => DiscountRedemption::STATUS_RESERVED,
        ]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);
        $tx = $this->paidTx($order->fresh(), $user);

        app(MarkOrderAsPaidService::class)->markPaid($order->fresh(), $tx);

        $this->assertDatabaseHas('discount_redemptions', [
            'order_id' => $order->id,
            'status'   => DiscountRedemption::STATUS_USED,
        ]);
    }

    public function test_duplicate_callback_does_not_double_count_discount_usage(): void
    {
        $code    = $this->code();
        $user    = $this->makeUser(['wallet_balance_toman' => 100000]);
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $order = app(ServiceAddonService::class)->createExtraTimeOrder($service, 10, $user);
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);
        $tx = $this->paidTx($order->fresh(), $user);

        $svc = app(MarkOrderAsPaidService::class);
        $svc->markPaid($order->fresh(), $tx);
        $svc->markPaid($order->fresh(), $tx->fresh()); // duplicate IPN

        $usedCount = DiscountRedemption::where('order_id', $order->id)
            ->where('status', DiscountRedemption::STATUS_USED)
            ->count();
        $this->assertSame(1, $usedCount);
    }

    // ── Financial report gross / discount / net ──────────────────────────────

    public function test_financial_report_shows_gross_discount_net(): void
    {
        $this->code();
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(100000);

        // One paid renewal order with a 10000 discount.
        $order = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
        $order = app(DiscountService::class)->applyToOrder($user, $order, 'SAVE10');
        $order->update([
            'payment_status' => Order::PAYMENT_PAID,
            'status'         => Order::STATUS_PAID,
            'paid_at'        => now(),
        ]);

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $report->dateFrom = now()->subDay()->format('Y-m-d');
        $report->dateTo   = now()->addDay()->format('Y-m-d');

        $this->assertSame(100000, $report->getGrossSalesRange());
        $this->assertSame(10000, $report->getOrderDiscountsRange());
        $this->assertSame(90000, $report->getNetSalesRange());
        $this->assertSame($report->getGrossSalesRange() - $report->getOrderDiscountsRange(), $report->getNetSalesRange());
    }

    public function test_wallet_topup_not_counted_as_sales(): void
    {
        $user = $this->makeUser();

        // A wallet top-up credits the wallet but creates no Order → no sales.
        \App\Models\WalletTransaction::create([
            'user_id'              => $user->id,
            'type'                 => \App\Models\WalletTransaction::TYPE_TOPUP,
            'direction'            => \App\Models\WalletTransaction::DIRECTION_CREDIT,
            'amount_toman'         => 500000,
            'balance_before_toman' => 0,
            'balance_after_toman'  => 500000,
            'status'               => \App\Models\WalletTransaction::STATUS_COMPLETED,
        ]);

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $report->dateFrom = now()->subDay()->format('Y-m-d');
        $report->dateTo   = now()->addDay()->format('Y-m-d');

        $this->assertSame(0, $report->getNetSalesRange());
        $this->assertSame(0, $report->getGrossSalesRange());
    }
}
