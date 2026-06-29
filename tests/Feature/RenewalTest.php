<?php

namespace Tests\Feature;

use App\Filament\Pages\RenewalSettingsPage;
use App\Filament\Resources\RenewalPackageResource;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\WalletTransaction;
use App\Services\Marzban\MarzbanClient;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Renewals\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name'             => 'پلن تست',
            'slug'             => 'test-plan-' . uniqid(),
            'price_toman'      => 100000,
            'duration_days'    => 30,
            'traffic_gb'       => 50,
            'is_active'        => true,
            'renewal_enabled'  => true,
            'sort_order'       => 0,
        ], $overrides));
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['wallet_balance_toman' => 0], $overrides));
    }

    private function makeService(User $user, array $overrides = []): UserService
    {
        // Create a minimal plan just for FK reference — inactive so it doesn't appear as a renewable option
        $plan = Plan::create([
            'name'            => 'پلن سرویس',
            'slug'            => 'svc-plan-' . uniqid(),
            'price_toman'     => 100000,
            'duration_days'   => 30,
            'traffic_gb'      => 50,
            'is_active'       => false,
            'renewal_enabled' => false,
            'sort_order'      => 0,
        ]);
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'plan_name'        => $plan->name,
            'expires_at'       => now()->addDays(10),
        ], $overrides));
    }

    private function makeRenewalOrder(UserService $service, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_type'        => Order::TYPE_RENEWAL,
            'user_id'           => $service->user_id,
            'user_service_id'   => $service->id,
            'plan_id'           => $plan->id,
            'original_plan_id'  => $service->plan_id,
            'plan_name'         => $plan->name,
            'plan_slug'         => $plan->slug,
            'duration_days'     => $plan->effectiveRenewalDays(),
            'renewal_days'      => $plan->effectiveRenewalDays(),
            'price_toman'       => $plan->effectiveRenewalPrice(),
            'final_price_toman' => $plan->effectiveRenewalPrice(),
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now(),
        ], $overrides));
    }

    private function makePaidTx(Order $order, User $user): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id'        => $order->id,
            'user_id'         => $user->id,
            'provider'        => 'wallet',
            'status'          => PaymentTransaction::STATUS_APPROVED,
            'amount_toman'    => $order->final_price_toman,
            'payment_purpose' => 'order_payment',
            'paid_at'         => now(),
        ]);
    }

    // ── Plan model renewal helpers ────────────────────────────────────────────

    public function test_plan_effective_renewal_price_falls_back_to_plan_price(): void
    {
        $plan = $this->makePlan(['price_toman' => 120000, 'renewal_price' => null]);
        $this->assertSame(120000, $plan->effectiveRenewalPrice());
    }

    public function test_plan_effective_renewal_price_uses_renewal_price_when_set(): void
    {
        $plan = $this->makePlan(['price_toman' => 120000, 'renewal_price' => 90000]);
        $this->assertSame(90000, $plan->effectiveRenewalPrice());
    }

    public function test_plan_effective_renewal_days_falls_back_to_plan_duration(): void
    {
        $plan = $this->makePlan(['duration_days' => 30, 'renewal_duration_days' => null]);
        $this->assertSame(30, $plan->effectiveRenewalDays());
    }

    public function test_plan_effective_renewal_days_uses_renewal_duration_when_set(): void
    {
        $plan = $this->makePlan(['duration_days' => 30, 'renewal_duration_days' => 60]);
        $this->assertSame(60, $plan->effectiveRenewalDays());
    }

    public function test_plan_effective_cashback_amount_null_when_disabled(): void
    {
        $plan = $this->makePlan(['renewal_cashback_enabled' => false]);
        $this->assertNull($plan->effectiveCashbackAmount());
    }

    public function test_plan_effective_cashback_amount_percent(): void
    {
        $plan = $this->makePlan([
            'price_toman'              => 100000,
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'percent',
            'renewal_cashback_value'   => 10,
        ]);
        $this->assertSame(10000, $plan->effectiveCashbackAmount());
    }

    public function test_plan_effective_cashback_amount_fixed(): void
    {
        $plan = $this->makePlan([
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'fixed',
            'renewal_cashback_value'   => 5000,
        ]);
        $this->assertSame(5000, $plan->effectiveCashbackAmount());
    }

    public function test_plan_scope_renewable_filters_correctly(): void
    {
        $this->makePlan(['is_active' => true,  'renewal_enabled' => true]);
        $this->makePlan(['is_active' => false, 'renewal_enabled' => true]);
        $this->makePlan(['is_active' => true,  'renewal_enabled' => false]);

        $this->assertCount(1, Plan::renewable()->get());
    }

    // ── SiteSetting ───────────────────────────────────────────────────────────

    public function test_site_setting_get_returns_default_when_missing(): void
    {
        $this->assertTrue(SiteSetting::get('renewal_enabled', true));
    }

    public function test_site_setting_set_and_get_boolean(): void
    {
        SiteSetting::set('renewal_enabled', 'false');
        $this->assertFalse(SiteSetting::get('renewal_enabled', true));
    }

    // ── RenewalService::createRenewalOrder ────────────────────────────────────

    public function test_create_renewal_order_success(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();

        $order = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);

        $this->assertSame(Order::TYPE_RENEWAL, $order->order_type);
        $this->assertSame($service->id, $order->user_service_id);
        $this->assertSame($plan->id, $order->plan_id);
        $this->assertSame($service->plan_id, $order->original_plan_id);
    }

    public function test_create_renewal_order_throws_for_unlimited_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['expires_at' => null]);
        $plan    = $this->makePlan();

        $this->expectException(\InvalidArgumentException::class);
        app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
    }

    public function test_create_renewal_order_throws_for_inactive_plan(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
    }

    public function test_create_renewal_order_throws_for_non_renewable_plan(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['renewal_enabled' => false]);

        $this->expectException(\InvalidArgumentException::class);
        app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
    }

    public function test_create_renewal_order_throws_when_renewal_globally_disabled(): void
    {
        SiteSetting::set('renewal_enabled', 'false');

        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();

        $this->expectException(\InvalidArgumentException::class);
        app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
    }

    public function test_create_renewal_order_throws_when_wrong_user(): void
    {
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();

        $this->expectException(\InvalidArgumentException::class);
        app(RenewalService::class)->createRenewalOrder($service, $plan, $other);
    }

    public function test_create_renewal_order_stores_cashback_amount(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan([
            'price_toman'              => 100000,
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'percent',
            'renewal_cashback_value'   => 10,
        ]);

        $order = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);

        $this->assertSame(10000, $order->renewal_cashback_amount);
        $this->assertSame('pending', $order->renewal_cashback_status);
    }

    public function test_create_renewal_order_no_cashback_when_disabled(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['renewal_cashback_enabled' => false]);

        $order = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);

        $this->assertNull($order->renewal_cashback_amount);
        $this->assertNull($order->renewal_cashback_status);
    }

    // ── RenewalService::calculateNewExpiry ───────────────────────────────────

    public function test_calculate_new_expiry_extends_from_expires_at_when_active(): void
    {
        $user   = $this->makeUser();
        $expiry = now()->addDays(10);
        $service = $this->makeService($user, ['expires_at' => $expiry]);

        $result = app(RenewalService::class)->calculateNewExpiry($service, 30);

        $this->assertEqualsWithDelta(
            $expiry->copy()->addDays(30)->timestamp,
            $result->timestamp,
            2
        );
    }

    public function test_calculate_new_expiry_extends_from_now_when_expired(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, [
            'expires_at' => now()->subDays(5),
            'status'     => UserService::STATUS_EXPIRED,
        ]);

        $before = now();
        $result = app(RenewalService::class)->calculateNewExpiry($service, 30);

        $this->assertEqualsWithDelta(
            $before->addDays(30)->timestamp,
            $result->timestamp,
            5
        );
    }

    // ── RenewalService::applyRenewal ─────────────────────────────────────────

    public function test_apply_renewal_updates_service_expiry(): void
    {
        $user   = $this->makeUser();
        $expiry = now()->addDays(10);
        $service = $this->makeService($user, ['expires_at' => $expiry]);
        $plan    = $this->makePlan(['duration_days' => 30]);
        $order   = $this->makeRenewalOrder($service, $plan);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $service->refresh();
        $this->assertEqualsWithDelta(
            $expiry->copy()->addDays(30)->timestamp,
            $service->expires_at->timestamp,
            2
        );
        $this->assertSame(UserService::STATUS_ACTIVE, $service->status);
    }

    public function test_apply_renewal_sets_renewal_applied_at(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();
        $order   = $this->makeRenewalOrder($service, $plan);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $this->assertNotNull($order->fresh()->renewal_applied_at);
    }

    public function test_apply_renewal_is_idempotent(): void
    {
        $user    = $this->makeUser();
        $expiry  = now()->addDays(10);
        $service = $this->makeService($user, ['expires_at' => $expiry, 'remote_username' => 'test_user']);
        $plan    = $this->makePlan(['duration_days' => 30]);
        $order   = $this->makeRenewalOrder($service, $plan);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        $svc = app(RenewalService::class);
        $svc->applyRenewal($order);
        $svc->applyRenewal($order->fresh()); // duplicate

        $service->refresh();
        $this->assertEqualsWithDelta(
            $expiry->copy()->addDays(30)->timestamp,
            $service->expires_at->timestamp,
            5
        );
    }

    public function test_apply_renewal_marks_renewal_failed_when_service_missing(): void
    {
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $service = $this->makeService($user);
        $order   = $this->makeRenewalOrder($service, $plan);

        // Detach user_service_id after creation to simulate missing service
        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $order->id)
            ->update(['user_service_id' => null]);

        app(RenewalService::class)->applyRenewal($order->fresh());

        $this->assertSame(Order::STATUS_RENEWAL_FAILED, $order->fresh()->status);
    }

    public function test_apply_renewal_marks_failed_when_marzban_throws(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'test_user']);
        $plan    = $this->makePlan();
        $order   = $this->makeRenewalOrder($service, $plan);

        $panel = VpnPanel::create([
            'name'       => 'Test Panel',
            'type'       => VpnPanel::TYPE_MARZBAN,
            'url'        => 'https://test.example.com',
            'is_active'  => true,
            'is_default' => true,
        ]);
        $service->update(['vpn_panel_id' => $panel->id]);

        $this->mock(MarzbanClient::class)
            ->shouldReceive('updateUser')
            ->andThrow(new \Exception('Connection failed'));

        app(RenewalService::class)->applyRenewal($order);

        $this->assertSame(Order::STATUS_RENEWAL_FAILED, $order->fresh()->status);
    }

    // ── Cashback ─────────────────────────────────────────────────────────────

    public function test_apply_renewal_credits_percent_cashback_to_wallet(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan([
            'price_toman'              => 100000,
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'percent',
            'renewal_cashback_value'   => 10,
        ]);
        $order = $this->makeRenewalOrder($service, $plan, [
            'renewal_cashback_amount' => 10000,
            'renewal_cashback_status' => 'pending',
        ]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $this->assertSame(10000, $user->fresh()->wallet_balance_toman);

        $tx = WalletTransaction::where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_RENEWAL_CASHBACK)
            ->first();
        $this->assertNotNull($tx);
        $this->assertSame(10000, $tx->amount_toman);
        $this->assertSame('کش‌بک تمدید سرویس', $tx->description);
    }

    public function test_apply_renewal_credits_fixed_cashback_to_wallet(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan([
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'fixed',
            'renewal_cashback_value'   => 5000,
        ]);
        $order = $this->makeRenewalOrder($service, $plan, [
            'renewal_cashback_amount' => 5000,
            'renewal_cashback_status' => 'pending',
        ]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $this->assertSame(5000, $user->fresh()->wallet_balance_toman);
        $this->assertSame('credited', $order->fresh()->renewal_cashback_status);
    }

    public function test_duplicate_ipn_does_not_credit_cashback_twice(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'test_user']);
        $plan    = $this->makePlan([
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'fixed',
            'renewal_cashback_value'   => 5000,
        ]);
        $order = $this->makeRenewalOrder($service, $plan, [
            'renewal_cashback_amount' => 5000,
            'renewal_cashback_status' => 'pending',
        ]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        $svc = app(RenewalService::class);
        $svc->applyRenewal($order);
        $svc->applyRenewal($order->fresh()); // duplicate

        $txCount = WalletTransaction::where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_RENEWAL_CASHBACK)
            ->count();
        $this->assertSame(1, $txCount);
        $this->assertSame(5000, $user->fresh()->wallet_balance_toman);
    }

    public function test_cashback_appears_in_wallet_transactions(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan([
            'renewal_cashback_enabled' => true,
            'renewal_cashback_type'    => 'fixed',
            'renewal_cashback_value'   => 3000,
        ]);
        $order = $this->makeRenewalOrder($service, $plan, [
            'renewal_cashback_amount' => 3000,
            'renewal_cashback_status' => 'pending',
        ]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $tx = WalletTransaction::where('user_id', $user->id)
            ->where('type', WalletTransaction::TYPE_RENEWAL_CASHBACK)
            ->first();

        $this->assertNotNull($tx);
        $this->assertSame(WalletTransaction::DIRECTION_CREDIT, $tx->direction);
        $this->assertSame(WalletTransaction::STATUS_COMPLETED, $tx->status);
    }

    // ── MarkOrderAsPaidService routing ───────────────────────────────────────

    public function test_mark_order_paid_routes_renewal_to_renewal_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();

        $order = Order::create([
            'order_type'        => Order::TYPE_RENEWAL,
            'user_id'           => $user->id,
            'user_service_id'   => $service->id,
            'plan_id'           => $plan->id,
            'original_plan_id'  => $service->plan_id,
            'plan_name'         => $plan->name,
            'plan_slug'         => $plan->slug,
            'duration_days'     => 30,
            'renewal_days'      => 30,
            'price_toman'       => 100000,
            'final_price_toman' => 100000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_AWAITING_PAYMENT,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);
        $tx = $this->makePaidTx($order, $user);
        $tx->update(['status' => PaymentTransaction::STATUS_PENDING]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertNotNull($order->fresh()->renewal_applied_at);
        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_duplicate_ipn_does_not_renew_twice(): void
    {
        $user   = $this->makeUser();
        $expiry = now()->addDays(10);
        $service = $this->makeService($user, ['expires_at' => $expiry, 'remote_username' => 'test_user']);
        $plan    = $this->makePlan(['duration_days' => 30]);
        $order   = $this->makeRenewalOrder($service, $plan);
        $tx      = $this->makePaidTx($order, $user);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        $svc = app(MarkOrderAsPaidService::class);
        $svc->markPaid($order, $tx);
        $svc->markPaid($order->fresh(), $tx->fresh()); // duplicate

        $service->refresh();
        $this->assertEqualsWithDelta(
            $expiry->copy()->addDays(30)->timestamp,
            $service->expires_at->timestamp,
            5
        );
    }

    public function test_renewal_does_not_create_new_user_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();
        $order   = $this->makeRenewalOrder($service, $plan);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        $before = UserService::count();
        app(RenewalService::class)->applyRenewal($order);
        $this->assertSame($before, UserService::count());
    }

    // ── Routes ───────────────────────────────────────────────────────────────

    public function test_renewal_routes_exist(): void
    {
        $routes = collect(\Route::getRoutes())->map->getName()->filter()->values()->toArray();

        $this->assertContains('dashboard.services.renew', $routes);
        $this->assertContains('dashboard.services.renew.submit', $routes);
    }

    // ── Dashboard controllers ─────────────────────────────────────────────────

    public function test_renewal_page_shows_active_renewable_plans(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);

        $this->makePlan(['name' => 'فعال و تمدیدپذیر', 'slug' => 'active-ok', 'is_active' => true,  'renewal_enabled' => true]);
        $this->makePlan(['name' => 'غیرفعال',           'slug' => 'inactive',  'is_active' => false, 'renewal_enabled' => true]);
        $this->makePlan(['name' => 'تمدید غیرفعال',     'slug' => 'no-renew',  'is_active' => true,  'renewal_enabled' => false]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service));

        $response->assertStatus(200);
        $response->assertSee('فعال و تمدیدپذیر');
        $response->assertDontSee('غیرفعال');
        $response->assertDontSee('تمدید غیرفعال');
    }

    public function test_renewal_page_hides_non_renewable_plans(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);

        // Only create a non-renewable plan
        $this->makePlan(['is_active' => true, 'renewal_enabled' => false]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service));

        $response->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_user_cannot_renew_another_users_service(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $service = $this->makeService($owner);

        $this->actingAs($other)
            ->get(route('dashboard.services.renew', $service))
            ->assertStatus(403);
    }

    public function test_renewal_page_redirects_for_unlimited_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['expires_at' => null]);
        $this->makePlan();

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_renewal_page_redirects_when_globally_disabled(): void
    {
        SiteSetting::set('renewal_enabled', 'false');

        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $this->makePlan();

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_renewal_submit_creates_order_and_redirects_to_payment(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();

        $response = $this->actingAs($user)
            ->post(route('dashboard.services.renew.submit', $service), ['plan_id' => $plan->id]);

        $order = Order::where('order_type', Order::TYPE_RENEWAL)->latest()->first();
        $this->assertNotNull($order);
        $response->assertRedirect(route('dashboard.orders.pay', $order));
    }

    public function test_renewal_submit_rejects_inactive_plan(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('dashboard.services.renew.submit', $service), ['plan_id' => $plan->id])
            ->assertStatus(404);
    }

    public function test_renewal_submit_rejects_non_renewable_plan(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['renewal_enabled' => false]);

        $this->actingAs($user)
            ->post(route('dashboard.services.renew.submit', $service), ['plan_id' => $plan->id])
            ->assertStatus(404);
    }

    public function test_renewal_submit_forbidden_for_wrong_user(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $service = $this->makeService($owner);
        $plan    = $this->makePlan();

        $this->actingAs($other)
            ->post(route('dashboard.services.renew.submit', $service), ['plan_id' => $plan->id])
            ->assertStatus(403);
    }

    public function test_wallet_renewal_payment_applies_renewal(): void
    {
        $user    = $this->makeUser(['wallet_balance_toman' => 200000]);
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['price_toman' => 100000]);

        $order = app(RenewalService::class)->createRenewalOrder($service, $plan, $user);
        $tx    = $this->makePaidTx($order, $user);
        $tx->update(['status' => PaymentTransaction::STATUS_PENDING]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->renewal_applied_at);
    }

    public function test_discount_message_visible_on_renew_page(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $this->makePlan();

        $response = $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service));

        $response->assertSee('کد تخفیف برای تمدید سرویس در حال حاضر فعال نیست.');
    }

    // ── Financial report ─────────────────────────────────────────────────────

    public function test_financial_report_renewal_methods_exist(): void
    {
        $report = app(\App\Filament\Pages\FinancialReport::class);
        $this->assertTrue(method_exists($report, 'getRenewalOrdersRange'));
        $this->assertTrue(method_exists($report, 'getRenewalSalesRange'));
        $this->assertTrue(method_exists($report, 'getRenewalFailedCount'));
        $this->assertTrue(method_exists($report, 'getNewServiceOrdersRange'));
        $this->assertTrue(method_exists($report, 'getNewServiceSalesRange'));
        $this->assertTrue(method_exists($report, 'getRenewalCashbackRange'));
    }

    public function test_financial_report_counts_renewal_as_sales(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan(['price_toman' => 100000]);
        $this->makeRenewalOrder($service, $plan);

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $report->dateFrom = now()->subDay()->format('Y-m-d');
        $report->dateTo   = now()->addDay()->format('Y-m-d');

        $this->assertSame(1, $report->getRenewalOrdersRange());
        $this->assertSame(100000, $report->getRenewalSalesRange());
    }

    public function test_financial_report_counts_new_service_separately(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['price_toman' => 80000]);

        Order::create([
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'plan_slug'         => $plan->slug,
            'duration_days'     => 30,
            'price_toman'       => 80000,
            'final_price_toman' => 80000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_COMPLETED,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now(),
        ]);

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $report->dateFrom = now()->subDay()->format('Y-m-d');
        $report->dateTo   = now()->addDay()->format('Y-m-d');

        $this->assertSame(1, $report->getNewServiceOrdersRange());
        $this->assertSame(80000, $report->getNewServiceSalesRange());
        $this->assertSame(0, $report->getRenewalOrdersRange());
    }

    // ── Admin navigation ─────────────────────────────────────────────────────

    public function test_renewal_settings_page_navigation_group(): void
    {
        $ref  = new \ReflectionClass(RenewalSettingsPage::class);
        $prop = $ref->getProperty('navigationGroup');
        $prop->setAccessible(true);

        $instance = $ref->newInstanceWithoutConstructor();
        $this->assertSame('کاربران و سفارش‌ها', $prop->getValue($instance));
    }

    public function test_renewal_package_resource_hidden_from_navigation(): void
    {
        $this->assertFalse(RenewalPackageResource::shouldRegisterNavigation());
    }
}
