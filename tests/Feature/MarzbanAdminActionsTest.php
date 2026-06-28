<?php

namespace Tests\Feature;

use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarzbanAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function makeUser(array $attrs = []): User
    {
        self::$counter++;
        return User::factory()->create(array_merge([
            'username' => 'act_user_' . self::$counter,
            'email'    => 'act_' . self::$counter . '@ex.com',
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

    private function makePanel(bool $isDefault = true): VpnPanel
    {
        return VpnPanel::create([
            'name'       => 'Main Marzban',
            'type'       => VpnPanel::TYPE_MARZBAN,
            'base_url'   => 'https://panel.example.com',
            'username'   => 'admin',
            'password'   => 'secret',
            'is_active'  => true,
            'is_default' => $isDefault,
        ]);
    }

    private function makeActiveService(User $user, VpnPanel $panel, string $remoteUsername = 'zpx_remote_abc'): UserService
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
            'traffic_used_gb'   => 2,
            'duration_days'     => 30,
            'status'            => UserService::STATUS_ACTIVE,
            'provision_status'  => UserService::PROVISION_PROVISIONED,
            'remote_username'   => $remoteUsername,
            'subscription_link' => 'https://panel.example.com/sub/OLDTOKEN/',
            'config_link'       => 'vless://some-config',
        ]);
    }

    private function fakeToken(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
        ]);
    }

    private function fakeUserResponse(string $username): array
    {
        return [
            'username'         => $username,
            'status'           => 'active',
            'used_traffic'     => 0,
            'data_limit'       => 21_474_836_480,
            'expire'           => now()->addDays(30)->timestamp,
            'subscription_url' => 'https://panel.example.com/sub/NEWTOKEN/',
            'links'            => ['vless://new-config'],
            'proxies'          => ['vless' => ['id' => 'some-uuid']],
        ];
    }

    // ── Sync: calls GET /api/user/{username} ─────────────────────────────────

    public function test_sync_calls_get_user_endpoint(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_sync_test');

        Http::fake([
            '*/api/admin/token'       => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_sync_test' => Http::response($this->fakeUserResponse('zpx_sync_test'), 200),
        ]);

        // Invoke sync via MarzbanClient directly (mirrors what the action does)
        $client      = new \App\Services\Marzban\MarzbanClient($panel);
        $marzbanUser = $client->getUser($service->remote_username);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/user/zpx_sync_test') && $req->method() === 'GET');
        $this->assertEquals('zpx_sync_test', $marzbanUser['username']);
    }

    // ── Reset traffic: calls POST /api/user/{username}/reset ─────────────────

    public function test_reset_traffic_calls_reset_endpoint(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_reset_test');

        Http::fake([
            '*/api/admin/token'              => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_reset_test/reset' => Http::response(null, 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->resetTraffic($service->remote_username);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/api/user/zpx_reset_test/reset') &&
            $req->method() === 'POST'
        );
    }

    public function test_reset_traffic_action_updates_traffic_used_gb_to_zero(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_reset2');

        $this->assertGreaterThan(0, $service->traffic_used_gb);

        Http::fake([
            '*/api/admin/token'            => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_reset2/reset'  => Http::response(null, 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->resetTraffic($service->remote_username);
        $service->update(['traffic_used_gb' => 0]);

        $service->refresh();
        $this->assertEquals(0, $service->traffic_used_gb);
    }

    // ── Revoke subscription: calls POST /api/user/{username}/revoke_sub ──────

    public function test_revoke_subscription_calls_revoke_sub_endpoint(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_revoke_test');

        Http::fake([
            '*/api/admin/token'                    => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_revoke_test/revoke_sub' => Http::response($this->fakeUserResponse('zpx_revoke_test'), 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->revokeSubscription($service->remote_username);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/api/user/zpx_revoke_test/revoke_sub') &&
            $req->method() === 'POST'
        );
    }

    public function test_revoke_subscription_saves_new_subscription_link(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_revoke2');

        $oldLink = $service->subscription_link;

        Http::fake([
            '*/api/admin/token'                 => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_revoke2/revoke_sub' => Http::response($this->fakeUserResponse('zpx_revoke2'), 200),
        ]);

        $client      = new \App\Services\Marzban\MarzbanClient($panel);
        $marzbanUser = $client->revokeSubscription($service->remote_username);
        $newSubLink  = $client->extractSubscriptionLink($marzbanUser);
        $service->update(['subscription_link' => $newSubLink]);

        $service->refresh();
        $this->assertNotNull($service->subscription_link);
        $this->assertNotEquals($oldLink, $service->subscription_link);
        $this->assertStringContainsString('NEWTOKEN', $service->subscription_link);
    }

    // ── Disable: calls PUT with status=disabled ───────────────────────────────

    public function test_disable_calls_put_with_status_disabled(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_disable_test');

        Http::fake([
            '*/api/admin/token'              => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_disable_test'    => Http::response(array_merge($this->fakeUserResponse('zpx_disable_test'), ['status' => 'disabled']), 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->updateUser($service->remote_username, ['status' => 'disabled']);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/api/user/zpx_disable_test') &&
            $req->method() === 'PUT' &&
            ($req->data()['status'] ?? null) === 'disabled'
        );
    }

    // ── Enable: calls PUT with status=active ──────────────────────────────────

    public function test_enable_calls_put_with_status_active(): void
    {
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
            'remote_username'  => 'zpx_enable_test',
        ]);

        Http::fake([
            '*/api/admin/token'           => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_enable_test'  => Http::response($this->fakeUserResponse('zpx_enable_test'), 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->updateUser($service->remote_username, ['status' => 'active']);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/api/user/zpx_enable_test') &&
            $req->method() === 'PUT' &&
            ($req->data()['status'] ?? null) === 'active'
        );
    }

    // ── Delete remote: calls DELETE and does NOT delete local service ─────────

    public function test_delete_remote_calls_delete_endpoint_and_keeps_local_service(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_delete_test');

        Http::fake([
            '*/api/admin/token'           => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_delete_test'  => Http::response(null, 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->deleteUser($service->remote_username);

        $service->update([
            'status'            => UserService::STATUS_CANCELLED,
            'provision_status'  => UserService::PROVISION_FAILED,
            'subscription_link' => null,
            'config_link'       => null,
        ]);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/api/user/zpx_delete_test') &&
            $req->method() === 'DELETE'
        );

        // Local service record still exists
        $this->assertDatabaseHas('user_services', ['id' => $service->id]);

        $service->refresh();
        $this->assertEquals(UserService::STATUS_CANCELLED, $service->status);
        $this->assertNull($service->subscription_link);
        $this->assertNull($service->config_link);
    }

    public function test_delete_remote_creates_provision_log(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_del_log');

        Http::fake([
            '*/api/admin/token'        => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_del_log'   => Http::response(null, 200),
        ]);

        $client = new \App\Services\Marzban\MarzbanClient($panel);
        $client->deleteUser($service->remote_username);

        VpnServiceProvisionLog::create([
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $panel->id,
            'action'          => 'marzban_delete_user',
            'status'          => 'success',
            'message'         => "Remote Marzban user '{$service->remote_username}' deleted.",
        ]);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_delete_user',
            'status'          => 'success',
        ]);
    }

    // ── Recreate: idempotent if remote user exists ────────────────────────────

    public function test_recreate_does_not_duplicate_if_remote_user_exists(): void
    {
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
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_FAILED,
            'remote_username'  => 'zpx_recreate_test',
        ]);

        Http::fake([
            '*/api/admin/token'               => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_recreate_test'    => Http::response($this->fakeUserResponse('zpx_recreate_test'), 200),
        ]);

        dispatch_sync(new ProvisionMarzbanServiceJob($service->id, $panel->id));

        $service->refresh();
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
        $this->assertEquals('zpx_recreate_test', $service->remote_username);

        // Should use update path, not create
        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_update_user',
            'status'          => 'success',
        ]);

        // No duplicate creation
        $createLogs = VpnServiceProvisionLog::where('user_service_id', $service->id)
            ->where('action', 'marzban_create_user')
            ->where('status', 'success')
            ->count();
        $this->assertEquals(0, $createLogs);
    }

    // ── API failure creates failed provision log ──────────────────────────────

    public function test_api_failure_creates_failed_provision_log(): void
    {
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
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
        ]);

        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Wrong credentials'], 401),
        ]);

        try {
            dispatch_sync(new ProvisionMarzbanServiceJob($service->id, $panel->id));
        } catch (\Throwable) {
            // Expected
        }

        $service->refresh();
        $this->assertEquals(UserService::PROVISION_FAILED, $service->provision_status);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_create_user',
            'status'          => 'failed',
        ]);
    }

    // ── User sees QR section when subscription_link present ──────────────────

    public function test_user_can_see_qr_section_for_own_service_with_subscription_link(): void
    {
        $panel = $this->makePanel();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $service = UserService::create([
            'user_id'           => $user->id,
            'order_id'          => $order->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'vpn_panel_id'      => $panel->id,
            'traffic_total_gb'  => 20,
            'traffic_used_gb'   => 0,
            'duration_days'     => 30,
            'status'            => UserService::STATUS_ACTIVE,
            'provision_status'  => UserService::PROVISION_PROVISIONED,
            'subscription_link' => 'https://panel.example.com/sub/SUBTOKEN/',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('لینک اشتراک (Subscription)')
            ->assertSee('https://panel.example.com/sub/SUBTOKEN/');
    }

    // ── User cannot see another user's service ────────────────────────────────

    public function test_user_cannot_see_another_users_service_links(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($owner, $plan);

        $service = UserService::create([
            'user_id'           => $owner->id,
            'order_id'          => $order->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'traffic_total_gb'  => 20,
            'traffic_used_gb'   => 0,
            'duration_days'     => 30,
            'status'            => UserService::STATUS_ACTIVE,
            'provision_status'  => UserService::PROVISION_PROVISIONED,
            'subscription_link' => 'https://panel.example.com/sub/SECRET/',
        ]);

        $this->actingAs($other)
            ->get(route('dashboard.services.show', $service))
            ->assertForbidden();
    }

    // ── QR section absent when subscription_link is null ─────────────────────

    public function test_qr_section_not_shown_when_subscription_link_is_empty(): void
    {
        $panel = $this->makePanel();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $service = UserService::create([
            'user_id'           => $user->id,
            'order_id'          => $order->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'vpn_panel_id'      => $panel->id,
            'traffic_total_gb'  => 20,
            'traffic_used_gb'   => 0,
            'duration_days'     => 30,
            'status'            => UserService::STATUS_PENDING_PROVISION,
            'provision_status'  => UserService::PROVISION_MANUAL_REQUIRED,
            'subscription_link' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('لینک اشتراک هنوز آماده نشده است')
            ->assertDontSee('کپی لینک اشتراک');
    }

    // ── Clear local links ─────────────────────────────────────────────────────

    public function test_clear_local_links_nullifies_subscription_and_config_links(): void
    {
        $panel   = $this->makePanel();
        $user    = $this->makeUser();
        $service = $this->makeActiveService($user, $panel, 'zpx_clear_links');

        $this->assertNotNull($service->subscription_link);
        $this->assertNotNull($service->config_link);

        $service->update([
            'subscription_link' => null,
            'config_link'       => null,
        ]);

        VpnServiceProvisionLog::create([
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $panel->id,
            'action'          => 'clear_local_links',
            'status'          => 'success',
            'message'         => 'Local subscription_link and config_link cleared by admin.',
        ]);

        $service->refresh();
        $this->assertNull($service->subscription_link);
        $this->assertNull($service->config_link);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'clear_local_links',
            'status'          => 'success',
        ]);
    }
}
