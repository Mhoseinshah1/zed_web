<?php

namespace Tests\Feature;

use App\Filament\Pages\ExtraAddonSettingsPage;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Addons\ServiceAddonService;
use App\Services\Marzban\MarzbanClient;
use App\Services\Orders\MarkOrderAsPaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAddonTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES_PER_GB = 1_073_741_824;

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        // Configure add-on pricing so the feature is enabled by default in tests.
        SiteSetting::set('extra_traffic_price_per_gb', 1000);
        SiteSetting::set('extra_time_price_per_day', 2000);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['wallet_balance_toman' => 0], $overrides));
    }

    private function makeService(User $user, array $overrides = []): UserService
    {
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
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 8,
            'expires_at'       => now()->addDays(10),
        ], $overrides));
    }

    private function makeTrafficOrder(UserService $service, int $gb, array $overrides = []): Order
    {
        $price = $gb * 1000;
        return Order::create(array_merge([
            'order_type'          => Order::TYPE_EXTRA_TRAFFIC,
            'user_id'             => $service->user_id,
            'user_service_id'     => $service->id,
            'plan_name'           => $service->plan_name,
            'extra_traffic_gb'    => $gb,
            'unit_price'          => 1000,
            'original_data_limit' => (int) ($service->traffic_total_gb * self::BYTES_PER_GB),
            'new_data_limit'      => (int) (($service->traffic_total_gb + $gb) * self::BYTES_PER_GB),
            'price_toman'         => $price,
            'final_price_toman'   => $price,
            'discount_toman'      => 0,
            'status'              => Order::STATUS_PAID,
            'payment_status'      => Order::PAYMENT_PAID,
            'paid_at'             => now(),
        ], $overrides));
    }

    private function makeTimeOrder(UserService $service, int $days, array $overrides = []): Order
    {
        $price = $days * 2000;
        return Order::create(array_merge([
            'order_type'         => Order::TYPE_EXTRA_TIME,
            'user_id'            => $service->user_id,
            'user_service_id'    => $service->id,
            'plan_name'          => $service->plan_name,
            'extra_time_days'    => $days,
            'unit_price'         => 2000,
            'original_expire_at' => $service->expires_at,
            'price_toman'        => $price,
            'final_price_toman'  => $price,
            'discount_toman'     => 0,
            'status'             => Order::STATUS_PAID,
            'payment_status'     => Order::PAYMENT_PAID,
            'paid_at'            => now(),
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

    // ── Routes ───────────────────────────────────────────────────────────────

    public function test_extra_traffic_routes_exist(): void
    {
        $routes = collect(\Route::getRoutes())->map->getName()->filter()->values()->toArray();
        $this->assertContains('dashboard.services.extra-traffic', $routes);
        $this->assertContains('dashboard.services.extra-traffic.submit', $routes);
    }

    public function test_extra_time_routes_exist(): void
    {
        $routes = collect(\Route::getRoutes())->map->getName()->filter()->values()->toArray();
        $this->assertContains('dashboard.services.extra-time', $routes);
        $this->assertContains('dashboard.services.extra-time.submit', $routes);
    }

    // ── Admin settings ───────────────────────────────────────────────────────

    public function test_admin_can_set_min_max_price_per_gb(): void
    {
        SiteSetting::set('extra_traffic_min_gb', 5);
        SiteSetting::set('extra_traffic_max_gb', 200);
        SiteSetting::set('extra_traffic_price_per_gb', 1500);

        $addon = app(ServiceAddonService::class);
        $this->assertSame(5, $addon->minGb());
        $this->assertSame(200, $addon->maxGb());
        $this->assertSame(1500, $addon->pricePerGb());
    }

    public function test_admin_can_set_min_max_price_per_day(): void
    {
        SiteSetting::set('extra_time_min_days', 3);
        SiteSetting::set('extra_time_max_days', 60);
        SiteSetting::set('extra_time_price_per_day', 2500);

        $addon = app(ServiceAddonService::class);
        $this->assertSame(3, $addon->minDays());
        $this->assertSame(60, $addon->maxDays());
        $this->assertSame(2500, $addon->pricePerDay());
    }

    public function test_admin_settings_page_navigation_registered(): void
    {
        $this->assertSame('سرویس‌ها', ExtraAddonSettingsPage::getNavigationGroup());
    }

    // ── Dashboard pages ──────────────────────────────────────────────────────

    public function test_extra_traffic_page_shows_input_not_plan_selection(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $response = $this->actingAs($user)->get(route('dashboard.services.extra-traffic', $service));

        $response->assertStatus(200);
        $response->assertSee('مقدار حجم اضافه');
        $response->assertSee('name="amount_gb"', false);
        $response->assertDontSee('انتخاب پلن');
    }

    public function test_extra_time_page_shows_input_not_plan_selection(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $response = $this->actingAs($user)->get(route('dashboard.services.extra-time', $service));

        $response->assertStatus(200);
        $response->assertSee('تعداد روز اضافه');
        $response->assertSee('name="amount_days"', false);
        $response->assertDontSee('انتخاب پلن');
    }

    public function test_user_cannot_buy_addon_for_another_users_service(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $service = $this->makeService($owner);

        $this->actingAs($other)
            ->get(route('dashboard.services.extra-traffic', $service))
            ->assertStatus(403);

        $this->actingAs($other)
            ->get(route('dashboard.services.extra-time', $service))
            ->assertStatus(403);
    }

    public function test_extra_traffic_page_redirects_for_unlimited_traffic(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['traffic_total_gb' => null, 'remote_username' => 'u1']);

        $this->actingAs($user)
            ->get(route('dashboard.services.extra-traffic', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_extra_time_page_redirects_for_service_without_expiry(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['expires_at' => null, 'remote_username' => 'u1']);

        $this->actingAs($user)
            ->get(route('dashboard.services.extra-time', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_missing_price_disables_traffic_purchase_safely(): void
    {
        SiteSetting::set('extra_traffic_price_per_gb', '');

        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $this->actingAs($user)
            ->get(route('dashboard.services.extra-traffic', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    // ── Submit / order creation ──────────────────────────────────────────────

    public function test_submit_extra_traffic_creates_order_and_redirects_to_payment(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $response = $this->actingAs($user)
            ->post(route('dashboard.services.extra-traffic.submit', $service), ['amount_gb' => 20]);

        $order = Order::where('order_type', Order::TYPE_EXTRA_TRAFFIC)->latest()->first();
        $this->assertNotNull($order);
        $this->assertSame(20, $order->extra_traffic_gb);
        $this->assertSame(20000, $order->final_price_toman);
        $this->assertSame($service->id, $order->user_service_id);
        $response->assertRedirect(route('dashboard.orders.show', $order));
    }

    public function test_submit_extra_time_creates_order_and_redirects_to_payment(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $response = $this->actingAs($user)
            ->post(route('dashboard.services.extra-time.submit', $service), ['amount_days' => 7]);

        $order = Order::where('order_type', Order::TYPE_EXTRA_TIME)->latest()->first();
        $this->assertNotNull($order);
        $this->assertSame(7, $order->extra_time_days);
        $this->assertSame(14000, $order->final_price_toman);
        $response->assertRedirect(route('dashboard.orders.show', $order));
    }

    public function test_amount_below_min_is_rejected(): void
    {
        SiteSetting::set('extra_traffic_min_gb', 5);
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $this->actingAs($user)
            ->post(route('dashboard.services.extra-traffic.submit', $service), ['amount_gb' => 2])
            ->assertSessionHasErrors('amount_gb');

        $this->assertSame(0, Order::where('order_type', Order::TYPE_EXTRA_TRAFFIC)->count());
    }

    public function test_amount_above_max_is_rejected(): void
    {
        SiteSetting::set('extra_traffic_max_gb', 50);
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $this->actingAs($user)
            ->post(route('dashboard.services.extra-traffic.submit', $service), ['amount_gb' => 100])
            ->assertSessionHasErrors('amount_gb');
    }

    public function test_create_traffic_order_throws_for_unlimited_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['traffic_total_gb' => null]);

        $this->expectException(\InvalidArgumentException::class);
        app(ServiceAddonService::class)->createExtraTrafficOrder($service, 10, $user);
    }

    public function test_create_time_order_throws_for_service_without_expiry(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['expires_at' => null]);

        $this->expectException(\InvalidArgumentException::class);
        app(ServiceAddonService::class)->createExtraTimeOrder($service, 7, $user);
    }

    // ── Apply behavior ───────────────────────────────────────────────────────

    public function test_extra_traffic_increases_data_limit_without_resetting_used(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['traffic_total_gb' => 20, 'traffic_used_gb' => 8, 'remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTraffic($order);

        $service->refresh();
        $this->assertSame(40, $service->traffic_total_gb); // 20 + 20
        $this->assertSame(8, $service->traffic_used_gb);   // unchanged
        $this->assertNotNull($order->fresh()->addon_applied_at);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_extra_traffic_marzban_payload_uses_correct_bytes(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['traffic_total_gb' => 20, 'remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20);

        $expectedBytes = 40 * self::BYTES_PER_GB;

        $this->mock(MarzbanClient::class)
            ->shouldReceive('updateUser')
            ->once()
            ->withArgs(function ($username, $payload) use ($expectedBytes) {
                return $username === 'u1'
                    && isset($payload['data_limit'])
                    && (int) $payload['data_limit'] === (int) $expectedBytes
                    && ! isset($payload['expire']);
            })
            ->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTraffic($order);
    }

    public function test_extra_time_extends_from_current_expiry_if_active(): void
    {
        $user    = $this->makeUser();
        $expiry  = now()->addDays(10);
        $service = $this->makeService($user, ['expires_at' => $expiry, 'remote_username' => 'u1']);
        $order   = $this->makeTimeOrder($service, 7);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTime($order);

        $service->refresh();
        $this->assertEqualsWithDelta(
            $expiry->copy()->addDays(7)->timestamp,
            $service->expires_at->timestamp,
            5
        );
    }

    public function test_extra_time_extends_from_now_if_expired_and_allowed(): void
    {
        SiteSetting::set('extra_addon_apply_to_expired_services', 'true');

        $user    = $this->makeUser();
        $service = $this->makeService($user, [
            'status'      => UserService::STATUS_EXPIRED,
            'expires_at'  => now()->subDays(5),
            'remote_username' => 'u1',
        ]);
        $order = $this->makeTimeOrder($service, 7);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTime($order);

        $service->refresh();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $service->expires_at->timestamp, 5);
        $this->assertSame(UserService::STATUS_ACTIVE, $service->status);
    }

    public function test_extra_time_marzban_payload_updates_expire_only(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);
        $order   = $this->makeTimeOrder($service, 7);

        $this->mock(MarzbanClient::class)
            ->shouldReceive('updateUser')
            ->once()
            ->withArgs(fn ($username, $payload) => isset($payload['expire']) && ! isset($payload['data_limit']))
            ->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTime($order);
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_apply_extra_traffic_is_idempotent(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['traffic_total_gb' => 20, 'remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        $svc = app(ServiceAddonService::class);
        $svc->applyExtraTraffic($order);
        $svc->applyExtraTraffic($order->fresh()); // duplicate

        $this->assertSame(40, $service->fresh()->traffic_total_gb); // not 60
    }

    public function test_duplicate_ipn_does_not_apply_addon_twice(): void
    {
        $user    = $this->makeUser(['wallet_balance_toman' => 100000]);
        $service = $this->makeService($user, ['traffic_total_gb' => 20, 'remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20);
        $tx      = $this->makePaidTx($order, $user);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        $svc = app(MarkOrderAsPaidService::class);
        $svc->markPaid($order, $tx);
        $svc->markPaid($order->fresh(), $tx->fresh()); // duplicate IPN

        $this->assertSame(40, $service->fresh()->traffic_total_gb);
    }

    public function test_mark_order_paid_routes_extra_traffic_to_addon_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['traffic_total_gb' => 20, 'remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20, [
            'status'         => Order::STATUS_AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'paid_at'        => null,
        ]);
        $tx = $this->makePaidTx($order, $user);
        $tx->update(['status' => PaymentTransaction::STATUS_PENDING]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertNotNull($order->fresh()->addon_applied_at);
        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertSame(40, $service->fresh()->traffic_total_gb);
    }

    public function test_addon_does_not_create_new_user_service(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        $before = UserService::count();
        app(ServiceAddonService::class)->applyExtraTraffic($order);
        $this->assertSame($before, UserService::count());
    }

    public function test_addon_does_not_create_new_marzban_user(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);
        $order   = $this->makeTimeOrder($service, 7);

        // Only updateUser is permitted; createUser must never be called.
        $mock = $this->mock(MarzbanClient::class);
        $mock->shouldReceive('updateUser')->andReturn([]);
        $mock->shouldNotReceive('createUser');

        app(ServiceAddonService::class)->applyExtraTime($order);
    }

    // ── Failure handling ─────────────────────────────────────────────────────

    public function test_marzban_failure_marks_addon_failed_and_keeps_payment(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1', 'traffic_total_gb' => 20]);
        $order   = $this->makeTrafficOrder($service, 20);

        $this->mock(MarzbanClient::class)
            ->shouldReceive('updateUser')
            ->andThrow(new \Exception('Connection failed'));

        app(ServiceAddonService::class)->applyExtraTraffic($order);

        $order->refresh();
        $this->assertSame(Order::STATUS_ADDON_FAILED, $order->status);
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status); // payment untouched
        $this->assertNull($order->addon_applied_at);
        $this->assertNotNull($order->addon_apply_failed_reason);
        $this->assertSame(20, $service->fresh()->traffic_total_gb); // not increased
    }

    public function test_admin_retry_applies_failed_addon(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1', 'traffic_total_gb' => 20]);
        $order   = $this->makeTrafficOrder($service, 20, ['status' => Order::STATUS_ADDON_FAILED]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTraffic($order);

        $this->assertSame(40, $service->fresh()->traffic_total_gb);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    // ── Wallet payment ───────────────────────────────────────────────────────

    public function test_wallet_extra_traffic_payment_applies(): void
    {
        $user    = $this->makeUser(['wallet_balance_toman' => 100000]);
        $service = $this->makeService($user, ['traffic_total_gb' => 20, 'remote_username' => 'u1']);
        $order   = $this->makeTrafficOrder($service, 20, [
            'status'         => Order::STATUS_AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'paid_at'        => null,
        ]);
        $tx = $this->makePaidTx($order, $user);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertSame(40, $service->fresh()->traffic_total_gb);
    }

    public function test_wallet_extra_time_payment_applies(): void
    {
        $user    = $this->makeUser(['wallet_balance_toman' => 100000]);
        $expiry  = now()->addDays(10);
        $service = $this->makeService($user, ['expires_at' => $expiry, 'remote_username' => 'u1']);
        $order   = $this->makeTimeOrder($service, 7, [
            'status'         => Order::STATUS_AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'paid_at'        => null,
        ]);
        $tx = $this->makePaidTx($order, $user);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->once()->andReturn([]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertEqualsWithDelta($expiry->copy()->addDays(7)->timestamp, $service->fresh()->expires_at->timestamp, 5);
    }

    // ── Financial report ─────────────────────────────────────────────────────

    public function test_financial_report_counts_addons_as_sales(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $this->makeTrafficOrder($service, 20); // 20000 toman, paid
        $this->makeTimeOrder($service, 7);     // 14000 toman, paid

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $report->dateFrom = now()->subDay()->format('Y-m-d');
        $report->dateTo   = now()->addDay()->format('Y-m-d');

        $this->assertSame(20000, $report->getExtraTrafficSalesRange());
        $this->assertSame(1, $report->getExtraTrafficOrdersRange());
        $this->assertSame(14000, $report->getExtraTimeSalesRange());
        $this->assertSame(1, $report->getExtraTimeOrdersRange());
        $this->assertGreaterThanOrEqual(34000, $report->getSalesRange());
    }

    public function test_extra_traffic_amount_validation_rejects_non_integer(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'u1']);

        $this->actingAs($user)
            ->post(route('dashboard.services.extra-traffic.submit', $service), ['amount_gb' => 'abc'])
            ->assertSessionHasErrors('amount_gb');
    }
}
