<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Referrals\CommissionService;
use App\Services\Referrals\ReferralService;
use App\Services\Referrals\ReferralSettings;
use App\Services\Referrals\RepresentativeService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralCommissionTest extends TestCase
{
    use RefreshDatabase;

    private function setMode(string $mode): void
    {
        SiteSetting::set('referral_mode', $mode);
    }

    private function setCommission(string $type = 'percent', int $value = 10): void
    {
        SiteSetting::set('default_commission_type', $type);
        SiteSetting::set('default_commission_value', (string) $value);
    }

    private function paidOrder(User $buyer, string $type = Order::TYPE_NEW_SERVICE, int $price = 100000, int $final = 100000, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_type'        => $type,
            'user_id'           => $buyer->id,
            'plan_name'         => 'p',
            'price_toman'       => $price,
            'final_price_toman' => $final,
            'discount_toman'    => $price - $final,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now(),
        ], $attrs));
    }

    // ── Referral code ────────────────────────────────────────────────────────

    public function test_referral_code_generated_for_users(): void
    {
        $user = User::factory()->create();
        $this->assertNotEmpty($user->referral_code);
        $this->assertSame(8, strlen($user->referral_code));
    }

    public function test_referral_code_is_unique(): void
    {
        $codes = collect(range(1, 25))->map(fn () => User::factory()->create()->referral_code);
        $this->assertSame($codes->count(), $codes->unique()->count());
    }

    // ── Referral link / session ──────────────────────────────────────────────

    public function test_referral_link_stores_ref_in_session(): void
    {
        $referrer = User::factory()->create();
        $this->get('/register?ref=' . $referrer->referral_code);
        $this->assertEquals($referrer->referral_code, session('referral_code'));
    }

    // ── Modes ────────────────────────────────────────────────────────────────

    public function test_all_users_mode_accepts_normal_user_code(): void
    {
        $this->setMode(ReferralSettings::MODE_ALL_USERS);
        $referrer = User::factory()->create();

        $this->assertNotNull(app(ReferralService::class)->resolveReferrer($referrer->referral_code));
    }

    public function test_representatives_only_mode_rejects_normal_user_code(): void
    {
        $this->setMode(ReferralSettings::MODE_REPRESENTATIVES);
        $referrer = User::factory()->create(); // not a representative

        $this->assertNull(app(ReferralService::class)->resolveReferrer($referrer->referral_code));
    }

    public function test_representatives_only_mode_accepts_approved_representative(): void
    {
        $this->setMode(ReferralSettings::MODE_REPRESENTATIVES);
        $rep = User::factory()->create([
            'is_representative' => true,
            'representative_status' => User::REP_APPROVED,
        ]);

        $this->assertNotNull(app(ReferralService::class)->resolveReferrer($rep->referral_code));
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function test_new_user_registers_with_valid_ref_code(): void
    {
        $this->setMode(ReferralSettings::MODE_ALL_USERS);
        $referrer = User::factory()->create();

        $this->post('/register', [
            'name' => 'Ref User', 'username' => 'refuser', 'email' => 'ref@example.com',
            'phone' => '09120001234', 'password' => 'password123', 'password_confirmation' => 'password123',
            'ref' => $referrer->referral_code,
        ]);

        $this->assertDatabaseHas('users', ['username' => 'refuser', 'referred_by_user_id' => $referrer->id]);
    }

    public function test_invalid_ref_code_does_not_crash_registration(): void
    {
        $this->post('/register', [
            'name' => 'NoRef', 'username' => 'noref', 'email' => 'noref@example.com',
            'phone' => '09120009999', 'password' => 'password123', 'password_confirmation' => 'password123',
            'ref' => 'NONEXISTENT',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['username' => 'noref', 'referred_by_user_id' => null]);
    }

    public function test_user_cannot_refer_themselves(): void
    {
        $user = User::factory()->create();
        app(ReferralService::class)->attachReferrer($user, $user->referral_code);
        $this->assertNull($user->fresh()->referred_by_user_id);
    }

    public function test_referred_by_is_not_overwritten(): void
    {
        $first  = User::factory()->create();
        $second = User::factory()->create();
        $user   = User::factory()->create(['referred_by_user_id' => $first->id]);

        app(ReferralService::class)->attachReferrer($user, $second->referral_code);
        $this->assertSame($first->id, $user->fresh()->referred_by_user_id);
    }

    // ── Representative request flow ───────────────────────────────────────────

    public function test_representative_request_flow_works(): void
    {
        SiteSetting::set('representative_system_enabled', 'true');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('dashboard.representative.request'), ['message' => 'می‌خواهم نماینده شوم'])
            ->assertRedirect();

        $this->assertSame(User::REP_PENDING, $user->fresh()->representative_status);
        $this->assertDatabaseHas('representative_requests', ['user_id' => $user->id, 'status' => 'pending']);
    }

    public function test_auto_approve_representative(): void
    {
        SiteSetting::set('representative_system_enabled', 'true');
        SiteSetting::set('auto_approve_representatives', 'true');
        $user = User::factory()->create();

        app(RepresentativeService::class)->request($user, 'x');
        $this->assertTrue($user->fresh()->isApprovedRepresentative());
    }

    public function test_admin_can_approve_and_reject_representative(): void
    {
        $svc = app(RepresentativeService::class);
        $a   = User::factory()->create(['representative_status' => User::REP_PENDING]);
        $svc->approve($a);
        $this->assertTrue($a->fresh()->isApprovedRepresentative());

        $b = User::factory()->create(['representative_status' => User::REP_PENDING]);
        $svc->reject($b, 'no');
        $this->assertSame(User::REP_REJECTED, $b->fresh()->representative_status);
    }

    // ── Commission creation per order type ────────────────────────────────────

    private function buyerWithReferrer(): array
    {
        $this->setMode(ReferralSettings::MODE_ALL_USERS);
        $this->setCommission('percent', 10);
        $referrer = User::factory()->create(['wallet_balance_toman' => 0]);
        $buyer    = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        return [$referrer, $buyer];
    }

    public function test_paid_new_service_creates_commission(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer, Order::TYPE_NEW_SERVICE, 100000, 100000);

        $c = app(CommissionService::class)->recordForOrder($order);

        $this->assertNotNull($c);
        $this->assertSame(10000, $c->commission_amount);
        $this->assertSame(Commission::STATUS_CREDITED, $c->status);
        $this->assertSame(10000, $referrer->fresh()->wallet_balance_toman);
    }

    public function test_paid_renewal_creates_commission(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer, Order::TYPE_RENEWAL);
        $this->assertNotNull(app(CommissionService::class)->recordForOrder($order));
    }

    public function test_paid_extra_traffic_creates_commission(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer, Order::TYPE_EXTRA_TRAFFIC);
        $this->assertNotNull(app(CommissionService::class)->recordForOrder($order));
    }

    public function test_paid_extra_time_creates_commission(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer, Order::TYPE_EXTRA_TIME);
        $this->assertNotNull(app(CommissionService::class)->recordForOrder($order));
    }

    public function test_commission_uses_final_amount_after_discount_when_enabled(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        SiteSetting::set('commission_after_discount', 'true');
        $order = $this->paidOrder($buyer, Order::TYPE_NEW_SERVICE, 100000, 80000); // 20k discount

        $c = app(CommissionService::class)->recordForOrder($order);
        $this->assertSame(8000, $c->commission_amount); // 10% of 80,000
    }

    public function test_commission_uses_original_amount_when_after_discount_disabled(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        SiteSetting::set('commission_after_discount', 'false');
        $order = $this->paidOrder($buyer, Order::TYPE_NEW_SERVICE, 100000, 80000);

        $c = app(CommissionService::class)->recordForOrder($order);
        $this->assertSame(10000, $c->commission_amount); // 10% of 100,000
    }

    // ── Not commissionable / guards ───────────────────────────────────────────

    public function test_wallet_topup_does_not_create_commission(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer, 'wallet_topup');
        $this->assertNull(app(CommissionService::class)->recordForOrder($order));
        $this->assertSame(0, Commission::count());
    }

    public function test_representatives_only_blocks_commission_for_non_representative(): void
    {
        $this->setMode(ReferralSettings::MODE_REPRESENTATIVES);
        $this->setCommission('percent', 10);
        $referrer = User::factory()->create(); // not a rep
        $buyer    = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $order    = $this->paidOrder($buyer);

        $this->assertNull(app(CommissionService::class)->recordForOrder($order));
    }

    public function test_duplicate_callback_does_not_duplicate_commission(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer, Order::TYPE_NEW_SERVICE);

        $svc = app(CommissionService::class);
        $svc->recordForOrder($order);
        $svc->recordForOrder($order->fresh()); // duplicate IPN

        $this->assertSame(1, Commission::where('order_id', $order->id)->count());
        $this->assertSame(10000, $referrer->fresh()->wallet_balance_toman); // credited once
    }

    public function test_commission_recorded_via_mark_order_paid(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $buyer->update(['wallet_balance_toman' => 0]);
        $order = $this->paidOrder($buyer, Order::TYPE_NEW_SERVICE, 100000, 100000, [
            'status' => Order::STATUS_AWAITING_PAYMENT, 'payment_status' => Order::PAYMENT_UNPAID, 'paid_at' => null,
        ]);
        $tx = PaymentTransaction::create([
            'order_id' => $order->id, 'user_id' => $buyer->id, 'provider' => 'wallet',
            'status' => PaymentTransaction::STATUS_PENDING, 'amount_toman' => 100000, 'payment_purpose' => 'order_payment',
        ]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertSame(1, Commission::where('order_id', $order->id)->count());
        $this->assertSame(10000, $referrer->fresh()->wallet_balance_toman);
    }

    // ── Wallet credit + notification ──────────────────────────────────────────

    public function test_representative_wallet_credited_once_with_notification(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer);

        app(CommissionService::class)->recordForOrder($order);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'      => $referrer->id,
            'type'         => \App\Models\WalletTransaction::TYPE_REPRESENTATIVE_COMMISSION,
            'amount_toman' => 10000,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $referrer->id,
            'type'    => \App\Models\Notification::TYPE_COMMISSION_CREDITED,
        ]);
    }

    public function test_commission_appears_in_admin_resource(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer);
        app(CommissionService::class)->recordForOrder($order);

        $this->actingAs($admin)->get('/zed-admin/commissions')->assertStatus(200);
    }

    // ── Dashboards ────────────────────────────────────────────────────────────

    public function test_representative_can_see_referral_dashboard(): void
    {
        $this->setMode(ReferralSettings::MODE_REPRESENTATIVES);
        $rep = User::factory()->create(['is_representative' => true, 'representative_status' => User::REP_APPROVED]);

        $this->actingAs($rep)->get(route('dashboard.representative'))
            ->assertStatus(200)
            ->assertSee($rep->referral_code);
    }

    public function test_normal_user_sees_code_only_in_all_users_mode(): void
    {
        $this->setMode(ReferralSettings::MODE_ALL_USERS);
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard.representative'))
            ->assertStatus(200)
            ->assertSee($user->referral_code);

        $this->setMode(ReferralSettings::MODE_REPRESENTATIVES);
        $this->actingAs($user)->get(route('dashboard.representative'))
            ->assertStatus(200)
            ->assertDontSee($user->referral_code);
    }

    // ── Financial report ──────────────────────────────────────────────────────

    public function test_financial_report_includes_commission_totals(): void
    {
        [$referrer, $buyer] = $this->buyerWithReferrer();
        $order = $this->paidOrder($buyer);
        app(CommissionService::class)->recordForOrder($order);

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $report->dateFrom = now()->subDay()->format('Y-m-d');
        $report->dateTo   = now()->addDay()->format('Y-m-d');

        $this->assertSame(10000, $report->getCommissionsRange());
    }
}
