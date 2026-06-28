<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VpnPanelUserToggleTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        self::$seq++;
        return User::factory()->create([
            'username' => 'toggle_' . self::$seq,
            'email'    => 'toggle_' . self::$seq . '@ex.com',
        ]);
    }

    private function makePanel(array $overrides = []): VpnPanel
    {
        return VpnPanel::create(array_merge([
            'name'       => 'Toggle Panel',
            'type'       => VpnPanel::TYPE_MARZBAN,
            'base_url'   => 'https://panel.example.com',
            'username'   => 'admin',
            'password'   => 'secret',
            'is_active'  => true,
            'is_default' => true,
        ], $overrides));
    }

    private function makeActiveService(User $user, VpnPanel $panel, string $remoteUsername = 'zpx_tgl'): UserService
    {
        $plan  = Plan::factory()->create([
            'name'          => 'Toggle Plan',
            'price_toman'   => 50000,
            'is_active'     => true,
            'traffic_gb'    => 20,
            'duration_days' => 30,
        ]);
        $order = Order::create([
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
            'subscription_link' => 'https://panel.example.com/sub/TOKEN/',
            'config_link'       => 'vless://cfg',
            'last_synced_at'    => now(), // prevent auto-sync on show
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
            'proxies'          => ['vless' => ['id' => 'uuid']],
        ];
    }

    // ── Panel model: toggle defaults ──────────────────────────────────────────

    public function test_panel_has_correct_default_toggle_values(): void
    {
        $panel = $this->makePanel();

        $this->assertTrue($panel->allow_user_sync_service);
        $this->assertTrue($panel->allow_user_revoke_subscription);
        $this->assertFalse($panel->allow_user_reset_traffic);
        $this->assertFalse($panel->allow_user_disable_service);
        $this->assertFalse($panel->allow_user_enable_service);
        $this->assertTrue($panel->allow_user_view_subscription_qr);
        $this->assertTrue($panel->allow_user_view_config_qr);
        $this->assertTrue($panel->allow_user_copy_subscription_link);
        $this->assertTrue($panel->allow_user_copy_config_link);
        $this->assertTrue($panel->allow_user_view_all_config_links);
    }

    public function test_panel_toggles_can_be_updated(): void
    {
        $panel = $this->makePanel();
        $panel->update([
            'allow_user_sync_service'        => false,
            'allow_user_reset_traffic'       => true,
            'allow_user_disable_service'     => true,
        ]);

        $panel->refresh();
        $this->assertFalse($panel->allow_user_sync_service);
        $this->assertTrue($panel->allow_user_reset_traffic);
        $this->assertTrue($panel->allow_user_disable_service);
    }

    public function test_panel_toggles_are_cast_as_boolean(): void
    {
        $panel = $this->makePanel(['allow_user_reset_traffic' => true]);
        $this->assertIsBool($panel->allow_user_reset_traffic);
        $this->assertTrue($panel->allow_user_reset_traffic);
    }

    // ── Per-panel sync toggle ─────────────────────────────────────────────────

    public function test_sync_blocked_when_panel_toggle_off(): void
    {
        $panel   = $this->makePanel(['allow_user_sync_service' => false]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_sync_off');

        $this->actingAs($user)
            ->post(route('dashboard.services.sync', $service))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_sync_allowed_when_panel_toggle_on(): void
    {
        $panel    = $this->makePanel(['allow_user_sync_service' => true]);
        $user     = $this->makeUser();
        $username = 'zpx_tgl_sync_on';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.sync', $service))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ── Per-panel revoke toggle ───────────────────────────────────────────────

    public function test_revoke_blocked_when_panel_toggle_off(): void
    {
        $panel   = $this->makePanel(['allow_user_revoke_subscription' => false]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_rvk_off');

        $this->actingAs($user)
            ->post(route('dashboard.services.revoke-subscription', $service))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ── Per-panel reset-traffic toggle ────────────────────────────────────────

    public function test_reset_traffic_blocked_when_panel_toggle_off(): void
    {
        $panel   = $this->makePanel(['allow_user_reset_traffic' => false]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_rst_off');

        $this->actingAs($user)
            ->post(route('dashboard.services.reset-traffic', $service))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_reset_traffic_allowed_when_panel_toggle_on(): void
    {
        $panel    = $this->makePanel(['allow_user_reset_traffic' => true]);
        $user     = $this->makeUser();
        $username = 'zpx_tgl_rst_on';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'            => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}/reset" => Http::response(null, 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.reset-traffic', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertEquals(0, $service->traffic_used_gb);
    }

    // ── Per-panel disable toggle ──────────────────────────────────────────────

    public function test_disable_blocked_when_panel_toggle_off(): void
    {
        $panel   = $this->makePanel(['allow_user_disable_service' => false]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_dis_off');

        $this->actingAs($user)
            ->post(route('dashboard.services.disable', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
    }

    public function test_disable_allowed_when_panel_toggle_on(): void
    {
        $panel    = $this->makePanel(['allow_user_disable_service' => true]);
        $user     = $this->makeUser();
        $username = 'zpx_tgl_dis_on';
        $service  = $this->makeActiveService($user, $panel, $username);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response(array_merge($this->fakeMarzbanUser($username), ['status' => 'disabled']), 200),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.disable', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_DISABLED, $service->status);
    }

    // ── Per-panel enable toggle ───────────────────────────────────────────────

    public function test_enable_blocked_when_panel_toggle_off(): void
    {
        $panel = $this->makePanel(['allow_user_enable_service' => false]);
        $user  = $this->makeUser();
        $plan  = Plan::factory()->create(['name' => 'P', 'price_toman' => 1, 'is_active' => true, 'traffic_gb' => 10, 'duration_days' => 10]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'price_toman' => 1, 'final_price_toman' => 1, 'traffic_gb' => 10, 'duration_days' => 10,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $service = UserService::create([
            'user_id' => $user->id, 'order_id' => $order->id, 'plan_id' => $plan->id,
            'plan_name' => $plan->name, 'vpn_panel_id' => $panel->id,
            'traffic_total_gb' => 10, 'traffic_used_gb' => 0, 'duration_days' => 10,
            'status' => UserService::STATUS_DISABLED,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username' => 'zpx_tgl_en_off',
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.services.enable', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $service->refresh();
        $this->assertEquals(UserService::STATUS_DISABLED, $service->status);
    }

    // ── Auto-sync on service detail view ─────────────────────────────────────

    public function test_auto_sync_fires_when_last_synced_at_is_null(): void
    {
        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_autosync_null';
        $plan     = Plan::factory()->create(['name' => 'P', 'price_toman' => 1, 'is_active' => true, 'traffic_gb' => 10, 'duration_days' => 10]);
        $order    = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'price_toman' => 1, 'final_price_toman' => 1, 'traffic_gb' => 10, 'duration_days' => 10,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $service  = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 10,
            'traffic_used_gb'  => 2,
            'duration_days'    => 10,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username'  => $username,
            'subscription_link' => 'https://panel.example.com/sub/OLD/',
            'last_synced_at'   => null, // triggers auto-sync
        ]);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk();

        $service->refresh();
        $this->assertNotNull($service->last_synced_at);
        $this->assertStringContainsString('NEWTOKEN', $service->subscription_link);
    }

    public function test_auto_sync_fires_when_last_synced_at_is_old(): void
    {
        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_autosync_old';
        $plan     = Plan::factory()->create(['name' => 'P', 'price_toman' => 1, 'is_active' => true, 'traffic_gb' => 10, 'duration_days' => 10]);
        $order    = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'price_toman' => 1, 'final_price_toman' => 1, 'traffic_gb' => 10, 'duration_days' => 10,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $service  = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 10,
            'traffic_used_gb'  => 2,
            'duration_days'    => 10,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username'  => $username,
            'subscription_link' => 'https://panel.example.com/sub/OLD/',
            'last_synced_at'   => now()->subMinutes(5), // 5 minutes ago → triggers
        ]);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk();

        $service->refresh();
        $this->assertStringContainsString('NEWTOKEN', $service->subscription_link);
    }

    public function test_auto_sync_skipped_when_last_synced_at_is_recent(): void
    {
        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_autosync_skip';
        $service  = $this->makeActiveService($user, $panel, $username); // last_synced_at = now()

        // No HTTP mock — if auto-sync fires, it will throw
        Http::fake([]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk();

        // Sub link unchanged (no sync happened)
        $service->refresh();
        $this->assertStringContainsString('TOKEN', $service->subscription_link);
    }

    public function test_auto_sync_failure_shows_warning_but_does_not_crash(): void
    {
        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_autosync_fail';
        $plan     = Plan::factory()->create(['name' => 'P', 'price_toman' => 1, 'is_active' => true, 'traffic_gb' => 10, 'duration_days' => 10]);
        $order    = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'price_toman' => 1, 'final_price_toman' => 1, 'traffic_gb' => 10, 'duration_days' => 10,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $service  = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 10,
            'traffic_used_gb'  => 2,
            'duration_days'    => 10,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username'  => $username,
            'last_synced_at'   => null, // triggers auto-sync
        ]);

        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Bad credentials'], 401),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('بروزرسانی خودکار اطلاعات سرویس');
    }

    public function test_auto_sync_creates_provision_log_on_success(): void
    {
        $panel    = $this->makePanel();
        $user     = $this->makeUser();
        $username = 'zpx_autosync_log';
        $plan     = Plan::factory()->create(['name' => 'P', 'price_toman' => 1, 'is_active' => true, 'traffic_gb' => 10, 'duration_days' => 10]);
        $order    = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'price_toman' => 1, 'final_price_toman' => 1, 'traffic_gb' => 10, 'duration_days' => 10,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
        ]);
        $service  = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 10,
            'traffic_used_gb'  => 2,
            'duration_days'    => 10,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'remote_username'  => $username,
            'last_synced_at'   => null,
        ]);

        Http::fake([
            '*/api/admin/token'      => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            "*/api/user/{$username}" => Http::response($this->fakeMarzbanUser($username), 200),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk();

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'user_auto_sync_on_view',
            'status'          => 'success',
        ]);
    }

    // ── Authorization: show page denies other user ────────────────────────────

    public function test_show_page_denies_other_user(): void
    {
        $panel   = $this->makePanel();
        $owner   = $this->makeUser();
        $other   = $this->makeUser();
        $service = $this->makeActiveService($owner, $panel, 'zpx_tgl_own');

        $this->actingAs($other)
            ->get(route('dashboard.services.show', $service))
            ->assertForbidden();
    }

    // ── Show page: panel-toggle-gated UI elements ─────────────────────────────

    public function test_show_page_hides_sync_button_when_panel_toggle_off(): void
    {
        $panel   = $this->makePanel(['allow_user_sync_service' => false]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_show_sync');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertDontSee('بروزرسانی وضعیت سرویس');
    }

    public function test_show_page_shows_sync_button_when_panel_toggle_on(): void
    {
        $panel   = $this->makePanel(['allow_user_sync_service' => true]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_show_sync_on');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('بروزرسانی وضعیت سرویس');
    }

    public function test_show_page_shows_reset_traffic_when_toggle_on(): void
    {
        $panel   = $this->makePanel(['allow_user_reset_traffic' => true]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_show_rst');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('ریست ترافیک');
    }

    public function test_show_page_hides_reset_traffic_when_toggle_off(): void
    {
        $panel   = $this->makePanel(['allow_user_reset_traffic' => false]);
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_tgl_hide_rst');

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertDontSee('ریست ترافیک');
    }
}
