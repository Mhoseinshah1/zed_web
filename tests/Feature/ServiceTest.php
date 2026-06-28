<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnInbound;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\PaymentService;
use App\Services\ServiceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['wallet_balance_toman' => 0], $attrs));
    }

    private function makePlan(int $price = 50000): Plan
    {
        return Plan::factory()->create([
            'name'        => 'Test Plan',
            'price_toman' => $price,
            'is_active'   => true,
            'traffic_gb'  => 20,
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

    private function makeManualMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'title'     => 'پرداخت دستی',
            'slug'      => 'manual-crypto',
            'type'      => PaymentMethod::TYPE_MANUAL_CRYPTO,
            'is_active' => true,
        ]);
    }

    // ── Service creation after payment ────────────────────────────────────────

    public function test_paid_order_approval_creates_one_pending_service(): void
    {
        $admin  = $this->makeUser(['username' => 'admin_s', 'email' => 'admin_s@ex.com', 'is_admin' => true]);
        $user   = $this->makeUser(['username' => 'customer_s', 'email' => 'cust_s@ex.com']);
        $plan   = $this->makePlan();
        $order  = Order::create([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);
        $method = $this->makeManualMethod();
        $tx = PaymentTransaction::create([
            'order_id'          => $order->id,
            'user_id'           => $user->id,
            'payment_method_id' => $method->id,
            'provider'          => 'manual',
            'method'            => 'manual',
            'status'            => PaymentTransaction::STATUS_SUBMITTED,
            'amount_toman'      => $order->final_price_toman,
        ]);

        app(PaymentService::class)->approveTransaction($tx, $admin->id);

        $this->assertDatabaseCount('user_services', 1);
        $this->assertDatabaseHas('user_services', [
            'order_id'         => $order->id,
            'user_id'          => $user->id,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => $plan->traffic_gb,
            'duration_days'    => $plan->duration_days,
        ]);
    }

    public function test_approving_same_payment_twice_does_not_create_duplicate_service(): void
    {
        $admin  = $this->makeUser(['username' => 'admin_dup', 'email' => 'admin_dup@ex.com', 'is_admin' => true]);
        $user   = $this->makeUser(['username' => 'cust_dup', 'email' => 'cust_dup@ex.com']);
        $plan   = $this->makePlan();
        $order  = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'price_toman' => $plan->price_toman, 'final_price_toman' => $plan->price_toman,
            'traffic_gb' => $plan->traffic_gb, 'duration_days' => $plan->duration_days,
            'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_UNPAID,
        ]);
        $method = $this->makeManualMethod();
        $tx = PaymentTransaction::create([
            'order_id' => $order->id, 'user_id' => $user->id,
            'payment_method_id' => $method->id, 'provider' => 'manual', 'method' => 'manual',
            'status' => PaymentTransaction::STATUS_SUBMITTED, 'amount_toman' => $order->final_price_toman,
        ]);

        $service = app(PaymentService::class);
        $service->approveTransaction($tx, $admin->id);
        $service->approveTransaction($tx, $admin->id); // second call — idempotent

        $this->assertDatabaseCount('user_services', 1);
    }

    public function test_provision_log_is_created_when_service_placeholder_is_created(): void
    {
        $user  = $this->makeUser(['username' => 'log_user', 'email' => 'log@ex.com']);
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        app(ServiceProvisioner::class)->createFromOrder($order);

        $service = UserService::where('order_id', $order->id)->firstOrFail();
        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'create_placeholder_service',
            'status'          => 'skipped',
        ]);
    }

    // ── User access ──────────────────────────────────────────────────────────

    public function test_user_can_view_own_services(): void
    {
        $user    = $this->makeUser();
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        $this->actingAs($user)
            ->get(route('dashboard.services'))
            ->assertOk()
            ->assertSee($service->service_number);
    }

    public function test_user_cannot_view_another_users_service(): void
    {
        $owner = $this->makeUser(['username' => 'owner_svc', 'email' => 'owner_svc@ex.com']);
        $other = $this->makeUser(['username' => 'other_svc', 'email' => 'other_svc@ex.com']);
        $plan  = $this->makePlan();
        $order = $this->makeOrder($owner, $plan);

        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        $this->actingAs($other)
            ->get(route('dashboard.services.show', $service))
            ->assertForbidden();
    }

    public function test_service_detail_shows_pending_message_when_no_config_link(): void
    {
        $user    = $this->makeUser(['username' => 'pending_user', 'email' => 'pend@ex.com']);
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        $this->actingAs($user)
            ->get(route('dashboard.services.show', $service))
            ->assertOk()
            ->assertSee('لینک اشتراک هنوز آماده نشده است');
    }

    // ── Admin manual activation ───────────────────────────────────────────────

    public function test_admin_can_manually_activate_service(): void
    {
        $user    = $this->makeUser(['username' => 'actv_user', 'email' => 'actv@ex.com']);
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        app(ServiceProvisioner::class)->activateManually($service);

        $service->refresh();
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
    }

    public function test_manual_activation_sets_dates_correctly(): void
    {
        $user    = $this->makeUser(['username' => 'date_user', 'email' => 'date@ex.com']);
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        app(ServiceProvisioner::class)->activateManually($service);

        $service->refresh();
        $this->assertNotNull($service->activated_at);
        $this->assertNotNull($service->starts_at);
        $this->assertNotNull($service->expires_at);
        $this->assertEquals(
            $service->starts_at->copy()->addDays(30)->format('Y-m-d'),
            $service->expires_at->format('Y-m-d')
        );
    }

    public function test_manual_activation_creates_provision_log(): void
    {
        $user    = $this->makeUser(['username' => 'logact', 'email' => 'logact@ex.com']);
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        app(ServiceProvisioner::class)->activateManually($service);

        $this->assertDatabaseHas('vpn_service_provision_logs', [
            'user_service_id' => $service->id,
            'action'          => 'manual_activate',
            'status'          => 'success',
        ]);
    }

    // ── Order detail ──────────────────────────────────────────────────────────

    public function test_order_detail_links_to_service_if_exists(): void
    {
        $user    = $this->makeUser(['username' => 'ord_svc_user', 'email' => 'ordsvc@ex.com']);
        $plan    = $this->makePlan();
        $order   = $this->makeOrder($user, $plan);
        $service = app(ServiceProvisioner::class)->createFromOrder($order);

        $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order))
            ->assertOk()
            ->assertSee('مشاهده سرویس');
    }

    // ── VPN placeholder models ────────────────────────────────────────────────

    public function test_vpn_panel_can_be_created(): void
    {
        VpnPanel::create([
            'name'    => 'Test Panel',
            'type'    => VpnPanel::TYPE_MARZBAN,
            'base_url' => 'https://panel.example.com:2053',
        ]);

        $this->assertDatabaseHas('vpn_panels', ['name' => 'Test Panel']);
    }

    public function test_vpn_inbound_can_be_created_and_linked_to_panel(): void
    {
        $panel = VpnPanel::create([
            'name' => 'Panel for Inbound',
            'type' => VpnPanel::TYPE_MARZBAN,
        ]);

        VpnInbound::create([
            'vpn_panel_id' => $panel->id,
            'name'         => 'VLESS-WS',
            'protocol'     => 'vless',
            'port'         => 443,
            'network'      => 'ws',
            'security'     => 'tls',
        ]);

        $this->assertDatabaseHas('vpn_inbounds', [
            'vpn_panel_id' => $panel->id,
            'name'         => 'VLESS-WS',
        ]);
    }
}
