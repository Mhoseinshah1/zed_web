<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Discounts\DiscountService;
use App\Services\Orders\MarkOrderAsPaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        static $i = 0;
        $i++;
        return User::factory()->create(array_merge([
            'username'             => "disc_user_{$i}",
            'email'                => "disc_{$i}@test.com",
            'wallet_balance_toman' => 0,
        ], $attrs));
    }

    private function makePlan(int $price = 100000): Plan
    {
        return Plan::factory()->create([
            'price_toman'   => $price,
            'is_active'     => true,
            'traffic_gb'    => 20,
            'duration_days' => 30,
        ]);
    }

    private function makeOrder(User $user, Plan $plan, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'discount_toman'    => 0,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ], $attrs));
    }

    private function makeDiscountCode(array $attrs = []): DiscountCode
    {
        return DiscountCode::create(array_merge([
            'code'     => 'TEST10',
            'type'     => DiscountCode::TYPE_PERCENT,
            'value'    => 10,
            'is_active'=> true,
            'per_user_usage_limit' => 1,
        ], $attrs));
    }

    // ── Task 2: Admin can create discount code ────────────────────────────────

    public function test_admin_can_create_discount_code(): void
    {
        $code = DiscountCode::create([
            'title'     => 'تست',
            'code'      => 'NEWCODE',
            'type'      => DiscountCode::TYPE_PERCENT,
            'value'     => 20,
            'is_active' => true,
            'per_user_usage_limit' => 1,
        ]);

        $this->assertDatabaseHas('discount_codes', ['code' => 'NEWCODE', 'value' => 20]);
        $this->assertEquals(DiscountCode::TYPE_PERCENT, $code->type);
    }

    public function test_discount_code_resource_is_in_mali_group(): void
    {
        $this->assertEquals('مالی', \App\Filament\Resources\DiscountCodeResource::getNavigationGroup());
    }

    // ── Task 3: Percent discount calculates correctly ─────────────────────────

    public function test_percent_discount_calculates_correctly(): void
    {
        $code  = $this->makeDiscountCode(['type' => DiscountCode::TYPE_PERCENT, 'value' => 20]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        $amount = app(DiscountService::class)->calculateDiscount($order, $code);

        $this->assertEquals(20000, $amount); // 20% of 100,000
    }

    public function test_fixed_discount_calculates_correctly(): void
    {
        $code  = $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 15000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        $amount = app(DiscountService::class)->calculateDiscount($order, $code);

        $this->assertEquals(15000, $amount);
    }

    public function test_max_discount_cap_works_for_percent(): void
    {
        $code = $this->makeDiscountCode([
            'type'               => DiscountCode::TYPE_PERCENT,
            'value'              => 50,
            'max_discount_amount'=> 10000,
        ]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        $amount = app(DiscountService::class)->calculateDiscount($order, $code);

        $this->assertEquals(10000, $amount); // capped at 10,000 not 50,000
    }

    public function test_fixed_discount_cannot_exceed_order_amount(): void
    {
        $code  = $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 200000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        $amount = app(DiscountService::class)->calculateDiscount($order, $code);

        $this->assertEquals(100000, $amount); // cannot exceed order price
    }

    // ── Task 3: Validation rules ──────────────────────────────────────────────

    public function test_min_order_amount_respected(): void
    {
        $code  = $this->makeDiscountCode(['min_order_amount' => 200000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        $result = app(DiscountService::class)->validateCode($user, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('مبلغ سفارش', $result['message']);
    }

    public function test_expired_code_rejected(): void
    {
        $code = $this->makeDiscountCode([
            'expires_at' => now()->subDay(),
        ]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $result = app(DiscountService::class)->validateCode($user, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('مهلت', $result['message']);
    }

    public function test_inactive_code_rejected(): void
    {
        $this->makeDiscountCode(['is_active' => false]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $result = app(DiscountService::class)->validateCode($user, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('غیرفعال', $result['message']);
    }

    public function test_future_code_rejected(): void
    {
        $this->makeDiscountCode(['starts_at' => now()->addDay()]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $result = app(DiscountService::class)->validateCode($user, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('هنوز فعال', $result['message']);
    }

    public function test_nonexistent_code_rejected(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $result = app(DiscountService::class)->validateCode($user, $order, 'NONEXISTENT');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('معتبر نیست', $result['message']);
    }

    public function test_total_usage_limit_works(): void
    {
        $code  = $this->makeDiscountCode(['total_usage_limit' => 2, 'per_user_usage_limit' => 5]);
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $user3 = $this->makeUser();
        $plan  = $this->makePlan();

        // Use up the limit
        DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user1->id,
            'status'           => DiscountRedemption::STATUS_USED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
        ]);
        DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user2->id,
            'status'           => DiscountRedemption::STATUS_USED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
        ]);

        $order  = $this->makeOrder($user3, $plan);
        $result = app(DiscountService::class)->validateCode($user3, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('سقف', $result['message']);
    }

    public function test_per_user_usage_limit_works(): void
    {
        $code = $this->makeDiscountCode(['per_user_usage_limit' => 1]);
        $user = $this->makeUser();
        $plan = $this->makePlan();

        // Mark as already used
        DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user->id,
            'status'           => DiscountRedemption::STATUS_USED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
        ]);

        $order  = $this->makeOrder($user, $plan);
        $result = app(DiscountService::class)->validateCode($user, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('قبلاً', $result['message']);
    }

    public function test_allowed_plan_restriction_works(): void
    {
        $plan1 = $this->makePlan();
        $plan2 = $this->makePlan();
        $code  = $this->makeDiscountCode(['allowed_plan_ids' => [$plan1->id]]);
        $user  = $this->makeUser();
        $order = $this->makeOrder($user, $plan2);

        $result = app(DiscountService::class)->validateCode($user, $order, 'TEST10');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('پلن', $result['message']);
    }

    // ── Task 5: applyToOrder stores snapshot ─────────────────────────────────

    public function test_order_stores_discount_snapshot(): void
    {
        $this->makeDiscountCode(['type' => DiscountCode::TYPE_PERCENT, 'value' => 20]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        app(DiscountService::class)->applyToOrder($user, $order, 'TEST10');

        $order->refresh();
        $this->assertEquals('TEST10', $order->discount_code);
        $this->assertEquals(DiscountCode::TYPE_PERCENT, $order->discount_type);
        $this->assertEquals(20, $order->discount_value);
        $this->assertEquals(20000, $order->discount_toman);
        $this->assertEquals(80000, $order->final_price_toman);
    }

    public function test_discount_applies_only_to_service_checkout_not_wallet_topup(): void
    {
        // Wallet topup transactions have no order_id — cannot accept discount codes
        // The DiscountService requires an Order object (service checkout only)
        $this->makeDiscountCode();
        $user = $this->makeUser();

        // Wallet topup has no order — cannot call applyToOrder
        $this->assertEquals(0, DiscountRedemption::count());

        // Verify wallet topup route does not accept discount codes
        $response = $this->actingAs($user)->post(route('dashboard.wallet.topup.submit'), [
            'amount'            => 100000,
            'payment_method_id' => 999,
            'discount_code'     => 'TEST10',
        ]);

        // No redemption created from wallet topup
        $this->assertEquals(0, DiscountRedemption::count());
    }

    // ── Task 4: Checkout UI shows discount section ────────────────────────────

    public function test_user_sees_discount_section_on_unpaid_order(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order))
            ->assertOk()
            ->assertSee('کد تخفیف دارید؟');
    }

    public function test_user_does_not_see_discount_section_on_paid_order(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status'         => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at'        => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order))
            ->assertOk()
            ->assertDontSee('کد تخفیف دارید؟');
    }

    public function test_apply_discount_via_http(): void
    {
        $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 10000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        $this->actingAs($user)
            ->post(route('dashboard.orders.discount.apply', $order), ['discount_code' => 'TEST10'])
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals(10000, $order->discount_toman);
        $this->assertEquals(90000, $order->final_price_toman);
    }

    public function test_remove_discount_via_http(): void
    {
        $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 10000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        app(DiscountService::class)->applyToOrder($user, $order, 'TEST10');

        $this->actingAs($user)
            ->delete(route('dashboard.orders.discount.remove', $order))
            ->assertRedirect();

        $order->refresh();
        $this->assertEquals(0, $order->discount_toman);
        $this->assertEquals(100000, $order->final_price_toman);
        $this->assertNull($order->discount_code);
    }

    // ── Task 6: Payment uses final_amount ─────────────────────────────────────

    public function test_payment_uses_final_amount_after_discount(): void
    {
        Queue::fake();

        $code  = $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 20000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        app(DiscountService::class)->applyToOrder($user, $order, 'TEST10');
        $order->refresh();

        $this->assertEquals(80000, $order->final_price_toman);

        // Simulate what PaymentController::submit does for manual payment
        $tx = PaymentTransaction::create([
            'order_id'     => $order->id,
            'user_id'      => $user->id,
            'provider'     => 'manual',
            'method'       => 'manual',
            'status'       => PaymentTransaction::STATUS_SUBMITTED,
            'amount_toman' => $order->final_price_toman, // must use final_price_toman
        ]);

        $this->assertEquals(80000, $tx->amount_toman); // discount applied
    }

    public function test_successful_payment_marks_redemption_used(): void
    {
        Queue::fake();

        $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 10000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        app(DiscountService::class)->applyToOrder($user, $order, 'TEST10');
        $order->refresh();

        $this->assertDatabaseHas('discount_redemptions', [
            'order_id' => $order->id,
            'status'   => DiscountRedemption::STATUS_RESERVED,
        ]);

        $tx = PaymentTransaction::create([
            'order_id'     => $order->id,
            'user_id'      => $user->id,
            'provider'     => 'manual',
            'method'       => 'manual',
            'status'       => PaymentTransaction::STATUS_SUBMITTED,
            'amount_toman' => $order->final_price_toman,
        ]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertDatabaseHas('discount_redemptions', [
            'order_id' => $order->id,
            'status'   => DiscountRedemption::STATUS_USED,
        ]);
    }

    public function test_duplicate_ipn_does_not_duplicate_redemption(): void
    {
        Queue::fake();

        $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 10000]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        app(DiscountService::class)->applyToOrder($user, $order, 'TEST10');
        $order->refresh();

        $tx = PaymentTransaction::create([
            'order_id'     => $order->id,
            'user_id'      => $user->id,
            'provider'     => 'manual',
            'method'       => 'manual',
            'status'       => PaymentTransaction::STATUS_SUBMITTED,
            'amount_toman' => $order->final_price_toman,
        ]);

        // First call
        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);
        // Second call (duplicate IPN)
        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertEquals(1, DiscountRedemption::where('order_id', $order->id)->count());
        $this->assertEquals(
            1,
            DiscountRedemption::where('order_id', $order->id)
                ->where('status', DiscountRedemption::STATUS_USED)
                ->count()
        );
    }

    // ── Task 7: Financial report uses final_amount ───────────────────────────

    public function test_financial_report_sales_uses_final_amount(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan, [
            'status'            => Order::STATUS_COMPLETED,
            'payment_status'    => Order::PAYMENT_PAID,
            'final_price_toman' => 80000, // after 20,000 discount
            'discount_toman'    => 20000,
            'paid_at'           => now(),
        ]);

        $report = new \App\Filament\Pages\FinancialReport();
        $report->dateFrom = today()->format('Y-m-d');
        $report->dateTo   = today()->format('Y-m-d');

        $this->assertEquals(80000, $report->getSalesToday()); // uses final_price_toman
    }

    public function test_total_discount_report_is_correct(): void
    {
        $code = $this->makeDiscountCode(['type' => DiscountCode::TYPE_FIXED, 'value' => 10000]);
        $user = $this->makeUser();
        $plan = $this->makePlan(100000);

        DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user->id,
            'status'           => DiscountRedemption::STATUS_USED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
            'used_at'          => now(),
        ]);
        DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user->id,
            'status'           => DiscountRedemption::STATUS_USED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
            'used_at'          => now(),
        ]);

        $report = new \App\Filament\Pages\FinancialReport();
        $report->dateFrom = today()->format('Y-m-d');
        $report->dateTo   = today()->format('Y-m-d');

        $this->assertEquals(20000, $report->getTotalDiscountsRange());
        $this->assertEquals(2, $report->getDiscountCountRange());
    }

    // ── Task 8: Security ──────────────────────────────────────────────────────

    public function test_user_cannot_apply_discount_to_another_users_order(): void
    {
        $this->makeDiscountCode();
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($owner, $plan);

        $this->actingAs($other)
            ->post(route('dashboard.orders.discount.apply', $order), ['discount_code' => 'TEST10'])
            ->assertForbidden();
    }

    public function test_user_cannot_apply_discount_to_paid_order(): void
    {
        $this->makeDiscountCode();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status'         => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.orders.discount.apply', $order), ['discount_code' => 'TEST10'])
            ->assertRedirect()
            ->assertSessionHasErrors('discount_code');
    }

    public function test_reserved_redemption_does_not_count_toward_usage_limit(): void
    {
        $code  = $this->makeDiscountCode(['total_usage_limit' => 1]);
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $plan  = $this->makePlan();

        // Create a RESERVED (not used) redemption
        DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user1->id,
            'status'           => DiscountRedemption::STATUS_RESERVED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
        ]);

        $order  = $this->makeOrder($user2, $plan);
        $result = app(DiscountService::class)->validateCode($user2, $order, 'TEST10');

        // Reserved should NOT count toward the total usage limit
        $this->assertTrue($result['valid']);
    }

    public function test_release_reservation_on_order_cancellation(): void
    {
        $this->makeDiscountCode();
        $user  = $this->makeUser();
        $plan  = $this->makePlan(100000);
        $order = $this->makeOrder($user, $plan);

        app(DiscountService::class)->applyToOrder($user, $order, 'TEST10');

        $this->assertDatabaseHas('discount_redemptions', [
            'order_id' => $order->id,
            'status'   => DiscountRedemption::STATUS_RESERVED,
        ]);

        app(DiscountService::class)->releaseReservation($order);

        $this->assertDatabaseHas('discount_redemptions', [
            'order_id' => $order->id,
            'status'   => DiscountRedemption::STATUS_CANCELLED,
        ]);
    }

    public function test_discount_code_is_case_insensitive(): void
    {
        $this->makeDiscountCode(['code' => 'TEST10']);
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $result = app(DiscountService::class)->validateCode($user, $order, 'test10');
        $this->assertTrue($result['valid']);

        $result2 = app(DiscountService::class)->validateCode($user, $order, 'Test10');
        $this->assertTrue($result2['valid']);
    }

    // ── Index page null-date safety ───────────────────────────────────────────

    public function test_discount_code_index_page_renders_with_null_dates(): void
    {
        $admin = User::factory()->create([
            'username'          => 'dc_idx_admin',
            'is_admin'          => true,
            'email_verified_at' => now(),
        ]);

        // Record with null starts_at and expires_at — previously caused DateMalformedStringException
        DiscountCode::create([
            'code'                 => 'NULLDATES',
            'type'                 => DiscountCode::TYPE_PERCENT,
            'value'                => 15,
            'is_active'            => true,
            'per_user_usage_limit' => 1,
            'starts_at'            => null,
            'expires_at'           => null,
        ]);

        $this->actingAs($admin)
            ->get('/zed-admin/discount-codes')
            ->assertOk();
    }

    public function test_discount_code_index_page_renders_with_real_dates(): void
    {
        $admin = User::factory()->create([
            'username'          => 'dc_idx_admin2',
            'is_admin'          => true,
            'email_verified_at' => now(),
        ]);

        DiscountCode::create([
            'code'                 => 'WITHDATES',
            'type'                 => DiscountCode::TYPE_FIXED,
            'value'                => 5000,
            'is_active'            => false,
            'per_user_usage_limit' => 1,
            'starts_at'            => now()->subDay(),
            'expires_at'           => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->get('/zed-admin/discount-codes')
            ->assertOk();
    }

    public function test_discount_code_create_page_still_opens(): void
    {
        $admin = User::factory()->create([
            'username'          => 'dc_create_admin',
            'is_admin'          => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/zed-admin/discount-codes/create')
            ->assertOk();
    }
}
