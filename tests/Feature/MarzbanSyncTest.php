<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Marzban\UserServiceSyncService;
use App\Services\Orders\OrderApplyRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarzbanSyncTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1_073_741_824;

    private function panel(): VpnPanel
    {
        return VpnPanel::create([
            'name'       => 'Test Marzban',
            'type'       => VpnPanel::TYPE_MARZBAN,
            'base_url'   => 'https://mz.test',
            'username'   => 'admin',
            'password'   => 'secret',
            'is_active'  => true,
            'is_default' => true,
        ]);
    }

    private function service(User $user, VpnPanel $panel, array $overrides = []): UserService
    {
        $plan = Plan::create([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'price_toman' => 1000,
            'duration_days' => 30, 'traffic_gb' => 10, 'is_active' => true, 'sort_order' => 0,
        ]);
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'plan_name'        => 'p',
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'vpn_panel_id'     => $panel->id,
            'remote_username'  => 'zpx_user',
            'traffic_total_gb' => 10,
            'traffic_used_gb'  => 0,
            'expires_at'       => now()->addDays(20),
        ], $overrides));
    }

    private function fakeMarzban(array $user, int $userStatus = 200): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/*'      => Http::response($user, $userStatus),
            '*/api/system'      => Http::response(['version' => '0.5.2'], 200),
        ]);
    }

    private function userPayload(): array
    {
        return [
            'username'         => 'zpx_user',
            'status'           => 'active',
            'used_traffic'     => 3 * self::GB,
            'data_limit'       => 25 * self::GB,
            'expire'           => now()->addDays(40)->timestamp,
            'subscription_url' => 'https://mz.test/sub/abc',
            'links'            => ['vless://link1'],
        ];
    }

    // ── Sync service ─────────────────────────────────────────────────────────

    public function test_sync_command_exists(): void
    {
        Http::fake();
        $this->artisan('zedproxy:sync-marzban-services', ['--limit' => 1])->assertExitCode(0);
    }

    public function test_sync_service_updates_traffic_data_limit_expire_status(): void
    {
        $this->fakeMarzban($this->userPayload());
        $service = $this->service(User::factory()->create(), $this->panel(), ['status' => UserService::STATUS_DISABLED]);

        app(UserServiceSyncService::class)->syncService($service);
        $service->refresh();

        $this->assertSame(UserService::SYNC_SYNCED, $service->sync_status);
        $this->assertSame(3.0, (float) $service->traffic_used_gb);
        $this->assertSame(25, $service->traffic_total_gb);
        $this->assertSame('active', $service->marzban_status);
        $this->assertSame(3 * self::GB, $service->marzban_used_traffic);
        // disabled → active reflected from Marzban
        $this->assertSame(UserService::STATUS_ACTIVE, $service->status);
        $this->assertNotNull($service->last_synced_at);
    }

    public function test_sync_failure_saves_sync_error(): void
    {
        $this->fakeMarzban([], userStatus: 500);
        $service = $this->service(User::factory()->create(), $this->panel());

        app(UserServiceSyncService::class)->syncService($service);
        $service->refresh();

        $this->assertSame(UserService::SYNC_FAILED, $service->sync_status);
        $this->assertNotNull($service->sync_error);
    }

    public function test_not_found_marzban_user_marks_not_found(): void
    {
        $this->fakeMarzban([], userStatus: 404);
        $service = $this->service(User::factory()->create(), $this->panel());

        app(UserServiceSyncService::class)->syncService($service);

        $this->assertSame(UserService::SYNC_NOT_FOUND, $service->fresh()->sync_status);
    }

    public function test_sync_does_not_overwrite_with_zero_data_limit(): void
    {
        $payload = $this->userPayload();
        $payload['data_limit'] = 0; // unlimited / unknown
        $this->fakeMarzban($payload);

        $service = $this->service(User::factory()->create(), $this->panel(), ['traffic_total_gb' => 10]);
        app(UserServiceSyncService::class)->syncService($service);

        // Good local value (10) must be kept, not overwritten with 0.
        $this->assertSame(10, $service->fresh()->traffic_total_gb);
    }

    // ── Lightweight dashboard behavior ───────────────────────────────────────

    public function test_service_list_does_not_call_marzban(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->service($user, $this->panel());

        $this->actingAs($user)->get(route('dashboard.services'))->assertStatus(200);
        Http::assertNothingSent();
    }

    public function test_service_detail_does_not_sync_when_fresh(): void
    {
        Http::fake();
        $user    = User::factory()->create();
        $service = $this->service($user, $this->panel(), ['last_synced_at' => now()->subSeconds(30)]);

        $this->actingAs($user)->get(route('dashboard.services.show', $service))->assertStatus(200);
        Http::assertNothingSent();
    }

    public function test_service_detail_syncs_when_stale(): void
    {
        $this->fakeMarzban($this->userPayload());
        $user    = User::factory()->create();
        $service = $this->service($user, $this->panel(), ['last_synced_at' => now()->subMinutes(2)]);

        $this->actingAs($user)->get(route('dashboard.services.show', $service))->assertStatus(200);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/user/'));
    }

    // ── Manual refresh ───────────────────────────────────────────────────────

    public function test_manual_refresh_blocked_within_cooldown(): void
    {
        Http::fake();
        $user    = User::factory()->create();
        $service = $this->service($user, $this->panel(), ['last_manual_sync_at' => now()->subSeconds(30)]);

        $this->actingAs($user)
            ->post(route('dashboard.services.refresh', $service))
            ->assertSessionHas('error', 'برای بروزرسانی مجدد 1 دقیقه صبر کنید.');

        Http::assertNothingSent();
    }

    public function test_user_can_refresh_own_service(): void
    {
        $this->fakeMarzban($this->userPayload());
        $user    = User::factory()->create();
        $service = $this->service($user, $this->panel(), ['last_manual_sync_at' => null]);

        $this->actingAs($user)
            ->post(route('dashboard.services.refresh', $service))
            ->assertSessionHas('success');

        $this->assertNotNull($service->fresh()->last_manual_sync_at);
    }

    public function test_user_cannot_refresh_another_users_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = $this->service($owner, $this->panel());

        $this->actingAs($other)->post(route('dashboard.services.refresh', $service))->assertStatus(403);
    }

    // ── Background sync ──────────────────────────────────────────────────────

    public function test_background_sync_respects_batch_size(): void
    {
        $this->fakeMarzban($this->userPayload());
        $user  = User::factory()->create();
        $panel = $this->panel();
        for ($i = 0; $i < 5; $i++) {
            $this->service($user, $panel, ['sync_status' => UserService::SYNC_FAILED, 'remote_username' => "u{$i}"]);
        }

        $count = app(UserServiceSyncService::class)->syncFailedServices(2);
        $this->assertSame(2, $count);
    }

    // ── Admin monitoring page ────────────────────────────────────────────────

    public function test_admin_monitoring_page_does_not_sync_all_services(): void
    {
        Http::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $user  = User::factory()->create();
        $panel = $this->panel();
        $this->service($user, $panel);
        $this->service($user, $panel, ['remote_username' => 'another']);

        $this->actingAs($admin)->get('/zed-admin/marzban-monitors')->assertStatus(200);
        Http::assertNothingSent();
    }

    // ── Panel health ─────────────────────────────────────────────────────────

    public function test_panel_health_check_updates_status_online(): void
    {
        $panel = $this->panel();
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok'], 200),
            '*/api/system'      => Http::response(['version' => '0.5.2'], 200),
        ]);

        $this->artisan('zedproxy:check-marzban-panels')->assertExitCode(0);

        $this->assertSame(VpnPanel::HEALTH_ONLINE, $panel->fresh()->health_status);
        $this->assertNotNull($panel->fresh()->last_health_checked_at);
    }

    public function test_panel_health_check_marks_offline_on_error(): void
    {
        $panel = $this->panel();
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok'], 200),
            '*/api/system'      => Http::response('err', 500),
        ]);

        $this->artisan('zedproxy:check-marzban-panels')->assertExitCode(0);

        $this->assertSame(VpnPanel::HEALTH_OFFLINE, $panel->fresh()->health_status);
        $this->assertNotNull($panel->fresh()->health_error);
    }

    // ── Failed operations + retry ────────────────────────────────────────────

    private function order(User $user, string $type, string $status, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_type'        => $type,
            'user_id'           => $user->id,
            'plan_name'         => 'p',
            'price_toman'       => 1000,
            'final_price_toman' => 1000,
            'discount_toman'    => 0,
            'status'            => $status,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now(),
        ], $overrides));
    }

    public function test_failed_operation_page_lists_failed_operations(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user  = User::factory()->create();
        $o1 = $this->order($user, Order::TYPE_RENEWAL, Order::STATUS_RENEWAL_FAILED);
        $o2 = $this->order($user, Order::TYPE_EXTRA_TRAFFIC, Order::STATUS_ADDON_FAILED);
        $o3 = $this->order($user, Order::TYPE_NEW_SERVICE, Order::STATUS_PROVISIONING_FAILED);

        $this->actingAs($admin)->get('/zed-admin/failed-operations')
            ->assertStatus(200)
            ->assertSee($o1->order_number)
            ->assertSee($o2->order_number)
            ->assertSee($o3->order_number);
    }

    public function test_retry_new_service_is_idempotent(): void
    {
        $user  = User::factory()->create();
        $order = $this->order($user, Order::TYPE_NEW_SERVICE, Order::STATUS_PROVISIONING_FAILED, [
            'plan_id' => Plan::create(['name' => 'p', 'slug' => 'p-' . uniqid(), 'price_toman' => 1000, 'duration_days' => 30, 'traffic_gb' => 10, 'is_active' => true, 'sort_order' => 0])->id,
        ]);
        // Service already provisioned & active for this order.
        UserService::create([
            'user_id' => $user->id, 'order_id' => $order->id, 'plan_name' => 'p',
            'status' => UserService::STATUS_ACTIVE, 'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username' => 'zpx_user',
        ]);

        $before = UserService::count();
        $applied = app(OrderApplyRetryService::class)->retry($order);

        $this->assertTrue($applied);
        $this->assertSame($before, UserService::count()); // no duplicate service
    }

    public function test_retry_renewal_is_idempotent(): void
    {
        $user  = User::factory()->create();
        $order = $this->order($user, Order::TYPE_RENEWAL, Order::STATUS_RENEWAL_FAILED, [
            'renewal_applied_at' => now(),
        ]);

        $applied = app(OrderApplyRetryService::class)->retry($order);
        $this->assertTrue($applied); // already applied → no-op success
    }

    public function test_retry_extra_traffic_is_idempotent(): void
    {
        $user  = User::factory()->create();
        $order = $this->order($user, Order::TYPE_EXTRA_TRAFFIC, Order::STATUS_ADDON_FAILED, [
            'addon_applied_at' => now(),
        ]);

        $this->assertTrue(app(OrderApplyRetryService::class)->retry($order));
    }

    public function test_retry_extra_time_is_idempotent(): void
    {
        $user  = User::factory()->create();
        $order = $this->order($user, Order::TYPE_EXTRA_TIME, Order::STATUS_ADDON_FAILED, [
            'addon_applied_at' => now(),
        ]);

        $this->assertTrue(app(OrderApplyRetryService::class)->retry($order));
    }

    public function test_duplicate_retry_does_not_apply_twice(): void
    {
        $this->fakeMarzban($this->userPayload());
        $user    = User::factory()->create();
        $panel   = $this->panel();
        $service = $this->service($user, $panel, ['traffic_total_gb' => 20, 'remote_username' => 'zpx_user']);

        // A paid extra-traffic order not yet applied.
        $order = $this->order($user, Order::TYPE_EXTRA_TRAFFIC, Order::STATUS_ADDON_FAILED, [
            'user_service_id'  => $service->id,
            'extra_traffic_gb' => 10,
            'new_data_limit'   => 30 * self::GB,
        ]);

        $svc = app(OrderApplyRetryService::class);
        $svc->retry($order);
        $firstTotal = $service->fresh()->traffic_total_gb;

        $svc->retry($order->fresh()); // duplicate
        $this->assertSame($firstTotal, $service->fresh()->traffic_total_gb);
    }
}
