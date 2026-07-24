<?php

namespace Tests\Feature;

use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlanPanelSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function sanaeiPanel(): VpnPanel
    {
        return VpnPanel::create([
            'name' => 'سنایی',
            'type' => VpnPanel::TYPE_SANAEI_XUI,
            'base_url' => 'https://panel.example.com:2053',
            'panel_path' => '/xui-panel-path',
            'auth_method' => VpnPanel::AUTH_API_TOKEN,
            'api_token' => 'secret-token',
            'default_inbound_id' => 1,
            'is_active' => true,
        ]);
    }

    private function defaultMarzban(): VpnPanel
    {
        return VpnPanel::create([
            'name' => 'مرزبان پیش‌فرض',
            'type' => VpnPanel::TYPE_MARZBAN,
            'base_url' => 'https://m.example.com',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function paidOrder(Plan $plan): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'traffic_gb' => 10,
            'duration_days' => 30,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_at' => now(),
        ]);
    }

    // ── Plan → panel relation ─────────────────────────────────────────────────

    public function test_plan_belongs_to_vpn_panel(): void
    {
        $panel = $this->sanaeiPanel();
        $plan = Plan::factory()->create(['vpn_panel_id' => $panel->id]);

        $this->assertSame($panel->id, $plan->vpnPanel->id);
    }

    // ── ServiceProvisioner (live paid path) ───────────────────────────────────

    public function test_service_provisioner_stamps_plan_panel_on_service(): void
    {
        Queue::fake();
        $panel = $this->sanaeiPanel();
        $plan = Plan::factory()->create(['vpn_panel_id' => $panel->id, 'traffic_gb' => 10, 'duration_days' => 30]);
        $order = $this->paidOrder($plan);

        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        $this->assertSame($panel->id, $service->vpn_panel_id);
        Queue::assertPushed(ProvisionMarzbanServiceJob::class);
    }

    public function test_service_provisioner_without_plan_panel_falls_back_to_default(): void
    {
        Queue::fake();
        $default = $this->defaultMarzban();
        $plan = Plan::factory()->create(['vpn_panel_id' => null, 'traffic_gb' => 10, 'duration_days' => 30]);
        $order = $this->paidOrder($plan);

        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        // Service keeps null; the default panel is resolved for the dispatched job.
        $this->assertNull($service->vpn_panel_id);
        Queue::assertPushed(ProvisionMarzbanServiceJob::class);
    }

    // ── ProvisioningService (placeholder creation) ────────────────────────────

    public function test_provisioning_service_provisions_on_plan_sanaei_panel(): void
    {
        Http::fake([
            '*/panel/api/clients/get/*' => Http::response(['success' => false], 200),
            '*/panel/api/clients/add' => Http::response(['success' => true], 200),
            '*/panel/api/clients/links/*' => Http::response(['success' => true, 'obj' => ['vless://abc']], 200),
        ]);

        $panel = $this->sanaeiPanel();
        // A default Marzban exists too — the plan's panel must win over it.
        $this->defaultMarzban();
        $plan = Plan::factory()->create(['vpn_panel_id' => $panel->id, 'traffic_gb' => 10, 'duration_days' => 30]);
        $order = $this->paidOrder($plan);

        $service = app(ProvisioningService::class)->provisionOrder($order);

        $this->assertSame($panel->id, $service->vpn_panel_id);
        $this->assertSame(UserService::STATUS_ACTIVE, $service->status);
        $this->assertNotEmpty($service->remote_username);
    }

    public function test_provisioning_service_provisions_on_plan_remnawave_panel(): void
    {
        Http::fake([
            '*/api/users/by-username/*' => Http::response(['message' => 'not found'], 404),
            '*/api/users' => Http::response(['response' => [
                'uuid' => 'user-uuid-1', 'username' => 'zed-1', 'shortUuid' => 'short1',
                'status' => 'ACTIVE', 'trafficLimitBytes' => 10737418240,
                'expireAt' => '2026-08-02T00:00:00.000Z',
                'subscriptionUrl' => 'https://panel.example.com/sub/short1',
                'userTraffic' => ['usedTrafficBytes' => 0],
            ]], 201),
        ]);

        $panel = VpnPanel::create([
            'name' => 'رمناویو',
            'type' => VpnPanel::TYPE_REMNAWAVE,
            'base_url' => 'https://panel.example.com',
            'api_token' => 'jwt-token',
            'is_active' => true,
        ]);
        $plan = Plan::factory()->create(['vpn_panel_id' => $panel->id, 'traffic_gb' => 10, 'duration_days' => 30]);
        $order = $this->paidOrder($plan);

        $service = app(ProvisioningService::class)->provisionOrder($order);

        $this->assertSame($panel->id, $service->vpn_panel_id);
        $this->assertSame(UserService::STATUS_ACTIVE, $service->status);
        $this->assertSame('user-uuid-1', $service->remote_uuid);
    }
}
