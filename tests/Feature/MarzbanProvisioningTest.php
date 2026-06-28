<?php

namespace Tests\Feature;

use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\ServiceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarzbanProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        static $i = 0;
        $i++;
        return User::factory()->create(array_merge([
            'username'            => "prov_user_{$i}",
            'email'               => "prov_{$i}@ex.com",
            'wallet_balance_toman' => 0,
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

    private function makeMarzbanPanel(bool $isDefault = true): VpnPanel
    {
        return VpnPanel::create([
            'name'      => 'Main Marzban',
            'type'      => VpnPanel::TYPE_MARZBAN,
            'base_url'  => 'https://panel.example.com',
            'username'  => 'admin',
            'password'  => 'secret',
            'is_active' => true,
            'is_default' => $isDefault,
        ]);
    }

    private function fakeMarzbanHttp(string $username = 'zpx_1_1_abcde'): void
    {
        Http::fake([
            '*/api/admin/token'  => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user'         => Http::response($this->fakeUserResponse($username), 200),
            "*/api/user/{$username}" => Http::response($this->fakeUserResponse($username), 200),
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
            'subscription_url' => 'https://panel.example.com/sub/SUBTOKEN/',
            'links'            => ['vless://some-config'],
            'proxies'          => ['vless' => ['id' => 'some-uuid']],
        ];
    }

    // ── No default panel → manual_required ───────────────────────────────────

    public function test_paid_order_without_panel_creates_service_with_manual_required(): void
    {
        Queue::fake();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        app(ServiceProvisioner::class)->createFromOrder($order);

        $this->assertDatabaseHas('user_services', [
            'order_id'         => $order->id,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
        ]);

        Queue::assertNothingPushed();
    }

    // ── Default panel exists → job dispatched ─────────────────────────────────

    public function test_paid_order_with_default_panel_dispatches_provision_job(): void
    {
        Queue::fake();

        $this->makeMarzbanPanel();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        app(ServiceProvisioner::class)->createFromOrder($order);

        Queue::assertPushed(ProvisionMarzbanServiceJob::class);
    }

    // ── Successful provisioning ───────────────────────────────────────────────

    public function test_provision_job_marks_service_active_and_saves_subscription_link(): void
    {
        $panel   = $this->makeMarzbanPanel();
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 0,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
        ]);

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user'        => Http::response($this->fakeUserResponse('zpx_test'), 200),
        ]);

        // getUser (404 = not found, so create path is taken)
        Http::fake([
            '*/api/admin/token'     => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx*'       => Http::response(['detail' => 'User not found'], 404),
            '*/api/user'            => Http::response($this->fakeUserResponse('zpx_test'), 200),
        ]);

        dispatch_sync(new ProvisionMarzbanServiceJob($service->id, $panel->id));

        $service->refresh();

        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
        $this->assertEquals(UserService::PROVISION_PROVISIONED, $service->provision_status);
        $this->assertNotNull($service->subscription_link);
        $this->assertNotNull($service->remote_username);
        $this->assertNotNull($service->activated_at);
        $this->assertNotNull($service->expires_at);
    }

    // ── Failed provisioning ───────────────────────────────────────────────────

    public function test_provision_job_marks_service_failed_on_api_error(): void
    {
        $panel   = $this->makeMarzbanPanel();
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
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
            // Expected — job re-throws for queue retry
        }

        $service->refresh();
        $this->assertEquals(UserService::PROVISION_FAILED, $service->provision_status);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_create_user',
            'status'          => 'failed',
        ]);
    }

    // ── Idempotent retry ──────────────────────────────────────────────────────

    public function test_retry_does_not_create_duplicate_if_remote_username_exists(): void
    {
        $panel   = $this->makeMarzbanPanel();
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 0,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_FAILED,
            'remote_username'  => 'zpx_existing',
        ]);

        // getUser returns existing user → update path
        Http::fake([
            '*/api/admin/token'           => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_existing'     => Http::response($this->fakeUserResponse('zpx_existing'), 200),
            '*/api/user/zpx_existing'     => Http::response($this->fakeUserResponse('zpx_existing'), 200), // PUT
        ]);

        dispatch_sync(new ProvisionMarzbanServiceJob($service->id, $panel->id));

        $service->refresh();
        $this->assertEquals('zpx_existing', $service->remote_username);
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_update_user',
            'status'          => 'success',
        ]);
    }

    // ── Provision log created ─────────────────────────────────────────────────

    public function test_provision_log_created_after_successful_provisioning(): void
    {
        $panel   = $this->makeMarzbanPanel();
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 0,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
        ]);

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/*'      => Http::response(['detail' => 'User not found'], 404),
            '*/api/user'        => Http::response($this->fakeUserResponse('zpx_new'), 200),
        ]);

        dispatch_sync(new ProvisionMarzbanServiceJob($service->id, $panel->id));

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $panel->id,
            'status'          => 'success',
        ]);
    }

    // ── 409 conflict → fetch existing and update ──────────────────────────────

    public function test_409_conflict_on_create_fetches_existing_and_updates(): void
    {
        $panel   = $this->makeMarzbanPanel();
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 0,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/admin/token')) {
                return Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($request->url(), '/api/user') && $request->method() === 'POST') {
                return Http::response(['detail' => 'Username already exists'], 409);
            }
            if (str_contains($request->url(), '/api/user/') && $request->method() === 'GET') {
                return Http::response($this->fakeUserResponse('zpx_conflict_user'), 200);
            }
            if (str_contains($request->url(), '/api/user/') && $request->method() === 'PUT') {
                return Http::response($this->fakeUserResponse('zpx_conflict_user'), 200);
            }
        });

        dispatch_sync(new ProvisionMarzbanServiceJob($service->id, $panel->id));

        $service->refresh();
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
        $this->assertEquals(UserService::PROVISION_PROVISIONED, $service->provision_status);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_create_user',
            'status'          => 'skipped',
        ]);
        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'marzban_update_user',
            'status'          => 'success',
        ]);
    }

    // ── User cannot see another user's subscription link ──────────────────────

    public function test_user_cannot_access_another_users_service_detail(): void
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
}
