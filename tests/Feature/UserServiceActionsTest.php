<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\SiteText;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class UserServiceActionsTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(array $attrs = []): User
    {
        self::$seq++;
        return User::factory()->create(array_merge([
            'username' => 'usact_' . self::$seq,
            'email'    => 'usact_' . self::$seq . '@ex.com',
        ], $attrs));
    }

    private function makePlan(): Plan
    {
        return Plan::factory()->create([
            'name'          => 'Test Plan',
            'price_toman'   => 50000,
            'is_active'     => true,
            'traffic_gb'    => 20,
            'duration_days' => 30,
        ]);
    }

    private function makeOrder(User $user, Plan $plan): Order
    {
        return Order::create([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
        ]);
    }

    private function makePanel(): VpnPanel
    {
        return VpnPanel::create([
            'name'       => 'Main Marzban',
            'type'       => VpnPanel::TYPE_MARZBAN,
            'base_url'   => 'https://panel.example.com',
            'username'   => 'admin',
            'password'   => 'secret',
            'is_active'  => true,
            'is_default' => true,
        ]);
    }

    private function makeActiveService(User $user, VpnPanel $panel, string $remoteUsername = 'zpx_user_abc'): UserService
    {
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        return UserService::create([
            'user_id'           => $user->id,
            'order_id'          => $order->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'vpn_panel_id'      => $panel->id,
            'traffic_total_gb'  => 20,
            'traffic_used_gb'   => 5,
            'duration_days'     => 30,
            'status'            => UserService::STATUS_ACTIVE,
            'provision_status'  => UserService::PROVISION_PROVISIONED,
            'remote_username'   => $remoteUsername,
            'subscription_link' => 'https://panel.example.com/sub/OLDTOKEN/',
            'config_link'       => 'vless://old-config',
        ]);
    }

    private function fakeToken(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
        ]);
    }

    private function fakeMarzbanUser(string $username): array
    {
        return [
            'username'         => $username,
            'status'           => 'active',
            'used_traffic'     => 1_073_741_824,
            'data_limit'       => 21_474_836_480,
            'expire'           => now()->addDays(30)->timestamp,
            'subscription_url' => 'https://panel.example.com/sub/NEWTOKEN/',
            'links'            => ['vless://new-config'],
            'proxies'          => ['vless' => ['id' => 'some-uuid']],
        ];
    }

    private function enableSetting(string $key): void
    {
        SiteText::updateOrCreate(['key' => $key], [
            'group'      => 'services',
            'label'      => $key,
            'value'      => 'true',
            'type'       => 'boolean',
            'is_public'  => false,
            'sort_order' => 0,
        ]);
    }

    private function disableSetting(string $key): void
    {
        SiteText::updateOrCreate(['key' => $key], [
            'group'      => 'services',
            'label'      => $key,
            'value'      => 'false',
            'type'       => 'boolean',
            'is_public'  => false,
            'sort_order' => 0,
        ]);
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_sync(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel);

        $this->post(route('dashboard.services.sync', $service))
            ->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_revoke_subscription(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel);

        $this->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect(route('login'));
    }

    // ── Ownership guard ───────────────────────────────────────────────────────

    public function test_user_cannot_sync_another_users_service(): void
    {
        $panel   = $this->makePanel();
        $owner   = $this->makeUser();
        $other   = $this->makeUser();
        $service = $this->makeActiveService($owner, $panel, 'zpx_owner');

        $this->actingAs($other)
            ->post(route('dashboard.services.sync', $service))
            ->assertForbidden();
    }

    public function test_user_cannot_revoke_another_users_subscription(): void
    {
        $panel   = $this->makePanel();
        $owner   = $this->makeUser();
        $other   = $this->makeUser();
        $service = $this->makeActiveService($owner, $panel, 'zpx_owner2');

        $this->actingAs($other)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertForbidden();
    }

    public function test_user_cannot_reset_traffic_of_another_user(): void
    {
        $this->enableSetting('services.allow_user_reset_traffic');

        $panel   = $this->makePanel();
        $owner   = $this->makeUser();
        $other   = $this->makeUser();
        $service = $this->makeActiveService($owner, $panel, 'zpx_owner3');

        $this->actingAs($other)
            ->post(route('dashboard.services.reset-traffic', $service))
            ->assertForbidden();
    }

    // ── Sync ─────────────────────────────────────────────────────────────────

    public function test_user_can_sync_own_active_service(): void
    {
        $this->enableSetting('services.allow_user_sync_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_sync_usr';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'        => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}"   => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.sync', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertNotNull($service->last_synced_at);
        $this->assertGreaterThan(0, $service->traffic_used_gb);
    }

    public function test_sync_updates_subscription_link_and_config_link(): void
    {
        $this->enableSetting('services.allow_user_sync_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_sync_links';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)->post(route('dashboard.services.sync', $service));

        $service->refresh();
        $this->assertStringContainsString('NEWTOKEN', $service->subscription_link);
        $this->assertEquals('vless://new-config', $service->config_link);
    }

    public function test_sync_when_disabled_returns_error(): void
    {
        $this->disableSetting('services.allow_user_sync_service');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_sync_dis');

        $this->actingAs($user)
            ->post(route('dashboard.services.sync', $service))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_sync_creates_provision_log_on_success(): void
    {
        $this->enableSetting('services.allow_user_sync_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_sync_log';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)->post(route('dashboard.services.sync', $service));

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'user_marzban_sync',
            'status'          => 'success',
        ]);
    }

    public function test_sync_api_failure_does_not_crash_and_logs_failed(): void
    {
        $this->enableSetting('services.allow_user_sync_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_sync_fail';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Bad credentials'], 401),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.sync', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'user_marzban_sync',
            'status'          => 'failed',
        ]);
    }

    // ── Revoke subscription ───────────────────────────────────────────────────

    public function test_user_can_revoke_own_subscription_when_setting_enabled(): void
    {
        $this->enableSetting('services.allow_user_revoke_subscription');
        RateLimiter::clear('revoke-sub:1:1'); // ensure no leftover rate limit

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_revoke_usr';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'                   => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/revoke_sub"   => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertStringContainsString('NEWTOKEN', $service->subscription_link);
    }

    public function test_revoke_updates_config_link(): void
    {
        $this->enableSetting('services.allow_user_revoke_subscription');
        RateLimiter::clear("revoke-sub:2:2");

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_revoke_cfg';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'                 => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/revoke_sub" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)->post(route('dashboard.services.revoke-subscription', $service));

        $service->refresh();
        $this->assertEquals('vless://new-config', $service->config_link);
    }

    public function test_revoke_blocked_when_setting_disabled(): void
    {
        $this->disableSetting('services.allow_user_revoke_subscription');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_revoke_dis');

        $this->actingAs($user)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $service->refresh();
        $this->assertStringContainsString('OLDTOKEN', $service->subscription_link);
    }

    public function test_revoke_rate_limited_after_first_request(): void
    {
        $this->enableSetting('services.allow_user_revoke_subscription');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_rl_usr';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'                 => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/revoke_sub" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $rateKey = "revoke-sub:{$service->id}:{$user->id}";
        RateLimiter::clear($rateKey);

        // First request succeeds
        $this->actingAs($user)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Second request is rate-limited
        $this->actingAs($user)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        RateLimiter::clear($rateKey);
    }

    public function test_revoke_creates_provision_log(): void
    {
        $this->enableSetting('services.allow_user_revoke_subscription');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_revoke_log';
        $service  = $this->makeActiveService($user, $panel, $username);

        $rateKey = "revoke-sub:{$service->id}:{$user->id}";
        RateLimiter::clear($rateKey);

        Http::fake([
            '*/api/admin/token'                 => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/revoke_sub" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)->post(route('dashboard.services.revoke-subscription', $service));

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'user_marzban_revoke_subscription',
            'status'          => 'success',
        ]);

        RateLimiter::clear($rateKey);
    }

    // ── Reset traffic ─────────────────────────────────────────────────────────

    public function test_reset_traffic_hidden_when_setting_disabled(): void
    {
        $this->disableSetting('services.allow_user_reset_traffic');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_reset_dis');

        $this->actingAs($user)
            ->post(route('dashboard.services.reset-traffic', $service))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_reset_traffic_works_when_setting_enabled(): void
    {
        $this->enableSetting('services.allow_user_reset_traffic');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_reset_usr';
        $service  = $this->makeActiveService($user, $panel, $username);

        $this->assertGreaterThan(0, $service->traffic_used_gb);

        Http::fake([
            '*/api/admin/token'               => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/reset"    => Http::response(null, 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.reset-traffic', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertEquals(0, $service->traffic_used_gb);
    }

    public function test_reset_traffic_creates_provision_log(): void
    {
        $this->enableSetting('services.allow_user_reset_traffic');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_reset_log';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'            => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/reset" => Http::response(null, 200),
        ]);

        $this->actingAs($user)->post(route('dashboard.services.reset-traffic', $service));

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'user_marzban_reset_traffic',
            'status'          => 'success',
        ]);
    }

    // ── Disable / Enable ──────────────────────────────────────────────────────

    public function test_disable_blocked_when_setting_disabled(): void
    {
        $this->disableSetting('services.allow_user_disable_service');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_dis_set');

        $this->actingAs($user)
            ->post(route('dashboard.services.disable', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
    }

    public function test_disable_works_when_setting_enabled(): void
    {
        $this->enableSetting('services.allow_user_disable_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_dis_usr';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'       => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}"  => Http::response(array_merge($this->fakeMarzbanUser($username), ['status' => 'disabled']), 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.disable', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_DISABLED, $service->status);
    }

    public function test_enable_blocked_when_setting_disabled(): void
    {
        $this->disableSetting('services.allow_user_enable_service');

        $panel = $this->makePanel();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 0,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_DISABLED,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username'  => 'zpx_en_set',
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.enable', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_DISABLED, $service->status);
    }

    public function test_enable_works_when_setting_enabled(): void
    {
        $this->enableSetting('services.allow_user_enable_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_en_usr';
        $plan     = $this->makePlan();
        $order    = $this->makeOrder($user, $plan);

        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 0,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_DISABLED,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username'  => $username,
        ]);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.enable', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
    }

    // ── Dangerous admin-only actions are not available in user panel ──────────

    public function test_no_route_exists_for_delete_remote(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel);

        $this->actingAs($user)
            ->post("/dashboard/services/{$service->id}/delete")
            ->assertStatus(404);
    }

    public function test_no_route_exists_for_recreate_remote(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel);

        $this->actingAs($user)
            ->post("/dashboard/services/{$service->id}/recreate")
            ->assertStatus(404);
    }

    public function test_no_route_exists_for_clear_local_links(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel);

        $this->actingAs($user)
            ->post("/dashboard/services/{$service->id}/clear-links")
            ->assertStatus(404);
    }

    // ── Show page: subscription link and QR section ──────────────────────────

    public function test_service_detail_shows_subscription_link_and_qr_when_active(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_show_sub');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('لینک اشتراک (Subscription)')
            ->assertSee('https://panel.example.com/sub/OLDTOKEN/')
            ->assertSee('کپی لینک اشتراک');
    }

    public function test_service_detail_shows_config_link_when_present(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_show_cfg');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('لینک کانفیگ مستقیم')
            ->assertSee('کپی لینک کانفیگ');
    }

    public function test_service_detail_shows_management_section_for_active_service(): void
    {
        $this->enableSetting('services.allow_user_sync_service');
        $this->enableSetting('services.allow_user_revoke_subscription');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_show_mgmt');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('مدیریت سرویس')
            ->assertSee('بروزرسانی وضعیت سرویس')
            ->assertSee('تغییر لینک اشتراک');
    }

    public function test_service_detail_hides_reset_traffic_when_setting_disabled(): void
    {
        $this->disableSetting('services.allow_user_reset_traffic');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_hide_reset');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertDontSee('ریست ترافیک');
    }

    public function test_service_detail_shows_reset_traffic_when_setting_enabled(): void
    {
        $this->enableSetting('services.allow_user_reset_traffic');

        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_show_reset');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('ریست ترافیک');
    }

    public function test_service_detail_does_not_expose_admin_only_content(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_no_admin');

        $response = $this->actingAs($user)
            ->get(route('dashboard.services.show', $service));

        $response->assertOk();
        $response->assertDontSee('حذف از مرزبان');
        $response->assertDontSee('ساخت دوباره در مرزبان');
        $response->assertDontSee('پاک کردن لینک‌های محلی');
        $response->assertDontSee('admin_notes');
        $response->assertDontSee('vpn_panel_id');
        $response->assertDontSee('secret'); // panel password
    }

    // ── API failure does not crash user page ──────────────────────────────────

    public function test_api_failure_on_revoke_returns_error_not_exception(): void
    {
        $this->enableSetting('services.allow_user_revoke_subscription');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_revoke_err';
        $service  = $this->makeActiveService($user, $panel, $username);

        $rateKey = "revoke-sub:{$service->id}:{$user->id}";
        RateLimiter::clear($rateKey);

        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Bad credentials'], 401),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        // Subscription link unchanged
        $service->refresh();
        $this->assertStringContainsString('OLDTOKEN', $service->subscription_link);

        RateLimiter::clear($rateKey);
    }

    public function test_api_failure_on_sync_returns_error_not_exception(): void
    {
        $this->enableSetting('services.allow_user_sync_service');

        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_sync_err';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Bad credentials'], 401),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.sync', $service))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
