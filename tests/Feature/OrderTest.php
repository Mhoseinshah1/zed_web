<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'name'       => 'Starter',
            'price_toman' => 49000,
            'is_active'   => true,
        ], $attrs));
    }

    // ── Plans page ───────────────────────────────────────────────────────────

    public function test_guest_can_view_plans_page(): void
    {
        $this->get(route('plans'))->assertOk();
    }

    public function test_buy_button_requires_auth(): void
    {
        $plan = $this->makePlan();

        $response = $this->post(route('plans.buy', $plan));

        // Unauthenticated — Laravel redirects to /login
        $response->assertRedirectContains('/login');
        $this->assertDatabaseCount('orders', 0);
    }

    // ── Buy flow ─────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_buy_active_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $response = $this->actingAs($user)->post(route('plans.buy', $plan));

        $response->assertRedirectContains('/dashboard/orders/');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_buying_creates_order_with_plan_snapshot(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan([
            'name'         => 'Pro Plan',
            'price_toman'  => 89000,
            'traffic_gb'   => 100,
            'duration_days' => 30,
            'slug'         => 'pro-plan',
        ]);

        $this->actingAs($user)->post(route('plans.buy', $plan));

        $order = Order::first();

        $this->assertEquals($user->id, $order->user_id);
        $this->assertEquals($plan->id, $order->plan_id);
        $this->assertEquals('Pro Plan', $order->plan_name);
        $this->assertEquals('pro-plan', $order->plan_slug);
        $this->assertEquals(100, $order->traffic_gb);
        $this->assertEquals(30, $order->duration_days);
        $this->assertEquals(89000, $order->price_toman);
        $this->assertEquals(89000, $order->final_price_toman);
        $this->assertEquals(0, $order->discount_toman);
        $this->assertEquals(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(Order::PAYMENT_UNPAID, $order->payment_status);
    }

    public function test_order_number_is_generated_automatically(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $this->actingAs($user)->post(route('plans.buy', $plan));

        $order = Order::first();
        $this->assertNotEmpty($order->order_number);
        $this->assertStringStartsWith('ZED-', $order->order_number);
    }

    public function test_buying_inactive_plan_returns_404(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan(['is_active' => false]);

        $response = $this->actingAs($user)->post(route('plans.buy', $plan));

        $response->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_plan_snapshot_unchanged_when_plan_edited(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan(['name' => 'Original Name', 'price_toman' => 50000]);

        $this->actingAs($user)->post(route('plans.buy', $plan));

        // Admin changes plan price and name
        $plan->update(['name' => 'New Name', 'price_toman' => 99000]);

        // Order snapshot should still have original values
        $order = Order::first();
        $this->assertEquals('Original Name', $order->plan_name);
        $this->assertEquals(50000, $order->price_toman);
    }

    // ── Dashboard access ─────────────────────────────────────────────────────

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirectContains('/login');
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_dashboard_orders_requires_auth(): void
    {
        $this->get('/dashboard/orders')->assertRedirectContains('/login');
    }

    public function test_authenticated_user_can_access_orders_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard/orders')->assertOk();
    }

    // ── Order visibility ─────────────────────────────────────────────────────

    public function test_user_can_view_own_order(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $this->actingAs($user)->post(route('plans.buy', $plan));
        $order = $user->orders()->first();

        $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order))
            ->assertOk();
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $owner   = User::factory()->create();
        $intruder = User::factory()->create();
        $plan    = $this->makePlan();

        $this->actingAs($owner)->post(route('plans.buy', $plan));
        $order = $owner->orders()->first();

        $this->actingAs($intruder)
            ->get(route('dashboard.orders.show', $order))
            ->assertForbidden();
    }

    // ── Model relations ───────────────────────────────────────────────────────

    public function test_order_belongs_to_user(): void
    {
        $user  = User::factory()->create();
        $plan  = $this->makePlan();
        $order = Order::create([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
        ]);

        $this->assertTrue($order->user->is($user));
    }

    public function test_user_has_many_orders(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'plan_name' => 'A', 'price_toman' => 100, 'final_price_toman' => 100,
        ]);
        Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'plan_name' => 'B', 'price_toman' => 200, 'final_price_toman' => 200,
        ]);

        $this->assertCount(2, $user->orders);
    }

    public function test_payment_transaction_model_and_migration(): void
    {
        $user  = User::factory()->create();
        $plan  = $this->makePlan();
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'plan_name' => 'Test', 'price_toman' => 100, 'final_price_toman' => 100,
        ]);

        $tx = PaymentTransaction::create([
            'order_id'     => $order->id,
            'user_id'      => $user->id,
            'amount_toman' => 100,
            'status'       => PaymentTransaction::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('payment_transactions', ['id' => $tx->id]);
        $this->assertTrue($tx->order->is($order));
        $this->assertTrue($tx->user->is($user));
    }

    // ── Admin Filament routes ─────────────────────────────────────────────────

    public function test_admin_order_resource_route_list_works(): void
    {
        // Just ensure the route list compiles without error
        $routes = app('router')->getRoutes()->getRoutesByName();
        $this->assertArrayHasKey('filament.zed-admin.resources.orders.index', $routes);
    }

    public function test_zed_admin_still_works(): void
    {
        $this->get('/zed-admin')->assertRedirectContains('/zed-admin/login');
    }
}
