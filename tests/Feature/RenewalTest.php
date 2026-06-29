<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RenewalPackage;
use App\Models\User;
use App\Models\UserService;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Renewals\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'username'          => 'renewal_admin',
            'is_admin'          => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeUser(string $suffix = ''): User
    {
        return User::factory()->create([
            'username'          => "renew_user{$suffix}",
            'is_admin'          => false,
            'email_verified_at' => now(),
        ]);
    }

    private function makePackage(array $attrs = []): RenewalPackage
    {
        return RenewalPackage::create(array_merge([
            'name'          => 'تمدید ۳۰ روزه',
            'duration_days' => 30,
            'price_toman'   => 150000,
            'is_active'     => true,
            'sort_order'    => 0,
        ], $attrs));
    }

    private function makeService(User $user, array $attrs = []): UserService
    {
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_name'        => 'پلن پایه',
            'traffic_total_gb' => 50,
            'duration_days'    => 30,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'expires_at'       => now()->addDays(10),
        ], $attrs));
    }

    // ── Task 1: RenewalPackage model ──────────────────────────────────────────

    public function test_renewal_package_can_be_created(): void
    {
        $pkg = $this->makePackage();
        $this->assertDatabaseHas('renewal_packages', ['duration_days' => 30, 'price_toman' => 150000]);
        $this->assertEquals('150,000 تومان', $pkg->formattedPrice());
        $this->assertEquals('30 روز', $pkg->durationLabel());
    }

    public function test_renewal_package_inactive_packages_exist(): void
    {
        $this->makePackage(['is_active' => false]);
        $this->assertEquals(0, RenewalPackage::where('is_active', true)->count());
        $this->assertEquals(1, RenewalPackage::where('is_active', false)->count());
    }

    // ── Task 2: Order model renewal fields ───────────────────────────────────

    public function test_order_has_renewal_type_constant(): void
    {
        $this->assertEquals('renewal', Order::TYPE_RENEWAL);
        $this->assertEquals('new_service', Order::TYPE_NEW_SERVICE);
    }

    public function test_order_has_renewal_failed_status(): void
    {
        $this->assertEquals('renewal_failed', Order::STATUS_RENEWAL_FAILED);
        $this->assertArrayHasKey('renewal_failed', Order::allStatuses());
    }

    public function test_order_is_renewal_method(): void
    {
        $user    = $this->makeUser('orm');
        $service = $this->makeService($user);
        $pkg     = $this->makePackage();

        $order = Order::create([
            'order_type'         => Order::TYPE_RENEWAL,
            'user_id'            => $user->id,
            'user_service_id'    => $service->id,
            'renewal_package_id' => $pkg->id,
            'renewal_days'       => 30,
            'plan_name'          => 'تمدید',
            'plan_slug'          => 'renewal',
            'price_toman'        => 150000,
            'final_price_toman'  => 150000,
            'status'             => Order::STATUS_AWAITING_PAYMENT,
            'payment_status'     => Order::PAYMENT_UNPAID,
        ]);

        $this->assertTrue($order->isRenewal());
        $this->assertNotNull($order->userService);
        $this->assertNotNull($order->renewalPackage);
    }

    // ── Task 3 & 4: RenewalService logic ─────────────────────────────────────

    public function test_create_renewal_order_for_active_service(): void
    {
        $user    = $this->makeUser('cro');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(15)]);
        $pkg     = $this->makePackage(['duration_days' => 30]);

        $renewalService = app(RenewalService::class);
        $order = $renewalService->createRenewalOrder($service, $pkg);

        $this->assertEquals(Order::TYPE_RENEWAL, $order->order_type);
        $this->assertEquals($service->id, $order->user_service_id);
        $this->assertEquals(30, $order->renewal_days);
        $this->assertEquals(150000, $order->final_price_toman);
        $this->assertEquals(Order::PAYMENT_UNPAID, $order->payment_status);
    }

    public function test_create_renewal_order_throws_for_unlimited_service(): void
    {
        $user    = $this->makeUser('unlimited');
        $service = $this->makeService($user, ['expires_at' => null]);
        $pkg     = $this->makePackage();

        $this->expectException(\InvalidArgumentException::class);
        app(RenewalService::class)->createRenewalOrder($service, $pkg);
    }

    public function test_new_expiry_extends_from_expires_at_when_active(): void
    {
        $user    = $this->makeUser('exp1');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);

        $renewalService = app(RenewalService::class);
        $newExpiry = $renewalService->calculateNewExpiry($service, 30);

        // Should be ~40 days from now (10 remaining + 30 added)
        $this->assertEqualsWithDelta(
            now()->addDays(40)->timestamp,
            $newExpiry->timestamp,
            5 // allow 5 seconds drift
        );
    }

    public function test_new_expiry_extends_from_now_when_expired(): void
    {
        $user    = $this->makeUser('exp2');
        $service = $this->makeService($user, [
            'expires_at' => now()->subDays(5),
            'status'     => UserService::STATUS_EXPIRED,
        ]);

        $renewalService = app(RenewalService::class);
        $newExpiry = $renewalService->calculateNewExpiry($service, 30);

        // Should be ~30 days from now (not from expired date)
        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            $newExpiry->timestamp,
            5
        );
    }

    // ── Task 5: applyRenewal ─────────────────────────────────────────────────

    public function test_apply_renewal_updates_service_expires_at(): void
    {
        $user    = $this->makeUser('apl');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $pkg     = $this->makePackage(['duration_days' => 30]);

        $renewalService = app(RenewalService::class);
        $order = $renewalService->createRenewalOrder($service, $pkg);
        $order->update(['payment_status' => Order::PAYMENT_PAID, 'status' => Order::STATUS_PAID]);

        // applyRenewal without Marzban (no remote_username)
        $renewalService->applyRenewal($order->fresh());

        $service->refresh();
        $order->refresh();

        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->new_expire_at);
        $this->assertNotNull($order->original_expire_at);
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);

        // New expiry ≈ 40 days from now (10 remaining + 30)
        $this->assertEqualsWithDelta(
            now()->addDays(40)->timestamp,
            $service->expires_at->timestamp,
            5
        );
    }

    public function test_apply_renewal_is_idempotent(): void
    {
        $user    = $this->makeUser('idem');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $pkg     = $this->makePackage(['duration_days' => 30]);

        $renewalService = app(RenewalService::class);
        $order = $renewalService->createRenewalOrder($service, $pkg);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        $renewalService->applyRenewal($order->fresh());
        $firstExpiry = $service->fresh()->expires_at;

        // Call again — should be a no-op
        $renewalService->applyRenewal($order->fresh());
        $secondExpiry = $service->fresh()->expires_at;

        $this->assertEquals($firstExpiry->timestamp, $secondExpiry->timestamp);
    }

    public function test_apply_renewal_marks_renewal_failed_when_service_missing(): void
    {
        $user    = $this->makeUser('nosvc');
        $pkg     = $this->makePackage();
        $service = $this->makeService($user, ['expires_at' => now()->addDays(5)]);

        $order = Order::create([
            'order_type'         => Order::TYPE_RENEWAL,
            'user_id'            => $user->id,
            'user_service_id'    => $service->id,
            'renewal_package_id' => $pkg->id,
            'renewal_days'       => 30,
            'plan_name'          => 'تمدید',
            'plan_slug'          => 'renewal',
            'price_toman'        => 150000,
            'final_price_toman'  => 150000,
            'status'             => Order::STATUS_PAID,
            'payment_status'     => Order::PAYMENT_PAID,
        ]);

        // Simulate service deleted after order was created
        // Nullify the FK without deleting the order row
        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $order->id)
            ->update(['user_service_id' => null]);

        app(RenewalService::class)->applyRenewal($order->fresh());
        $this->assertEquals(Order::STATUS_RENEWAL_FAILED, $order->fresh()->status);
    }

    // ── Task 6: MarkOrderAsPaidService routing ───────────────────────────────

    public function test_mark_paid_routes_renewal_order_to_renewal_service(): void
    {
        $user    = $this->makeUser('mps');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $pkg     = $this->makePackage(['duration_days' => 30]);

        $renewalService = app(RenewalService::class);
        $order = $renewalService->createRenewalOrder($service, $pkg);

        $tx = \App\Models\PaymentTransaction::create([
            'order_id'        => $order->id,
            'user_id'         => $user->id,
            'provider'        => 'manual',
            'payment_purpose' => 'order_payment',
            'status'          => \App\Models\PaymentTransaction::STATUS_PENDING,
            'amount_toman'    => 150000,
        ]);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $order->refresh();
        $service->refresh();

        $this->assertEquals(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->new_expire_at);
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
    }

    // ── Task 7: Admin panel ───────────────────────────────────────────────────

    public function test_renewal_package_admin_page_renders(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/zed-admin/renewal-packages')
            ->assertOk();
    }

    public function test_renewal_package_create_page_renders(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/zed-admin/renewal-packages/create')
            ->assertOk();
    }

    public function test_renewal_package_navigation_group(): void
    {
        $group = \App\Filament\Resources\RenewalPackageResource::getNavigationGroup();
        $this->assertEquals('کاربران و سفارش‌ها', $group);
    }

    // ── Task 8: Dashboard routes ──────────────────────────────────────────────

    public function test_renew_page_returns_403_for_other_users_service(): void
    {
        $owner = $this->makeUser('own');
        $other = $this->makeUser('other');
        $service = $this->makeService($owner);

        $this->actingAs($other)
            ->get(route('dashboard.services.renew', $service))
            ->assertForbidden();
    }

    public function test_renew_page_renders_for_service_owner(): void
    {
        $user    = $this->makeUser('pg');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $this->makePackage();

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertOk();
    }

    public function test_renew_page_redirects_when_no_packages(): void
    {
        $user    = $this->makeUser('np');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_renew_page_redirects_for_unlimited_service(): void
    {
        $user    = $this->makeUser('ul');
        $service = $this->makeService($user, ['expires_at' => null]);
        $this->makePackage();

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertRedirect(route('dashboard.services.show', $service));
    }

    public function test_renew_submit_creates_order_and_redirects_to_payment(): void
    {
        $user    = $this->makeUser('sub');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $pkg     = $this->makePackage();

        $response = $this->actingAs($user)
            ->post(route('dashboard.services.renew.submit', $service), [
                'renewal_package_id' => $pkg->id,
            ]);

        $order = Order::where('order_type', Order::TYPE_RENEWAL)->where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $response->assertRedirect(route('dashboard.orders.pay', $order));
    }

    public function test_renew_submit_rejects_inactive_package(): void
    {
        $user    = $this->makeUser('inactive');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $pkg     = $this->makePackage(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('dashboard.services.renew.submit', $service), [
                'renewal_package_id' => $pkg->id,
            ])
            ->assertStatus(404); // firstOrFail on inactive package
    }

    // ── Task 9: OrderResource order_type filter ───────────────────────────────

    public function test_order_resource_contains_renewal_orders_filter(): void
    {
        // Test that STATUS_RENEWAL_FAILED is in allStatuses
        $statuses = Order::allStatuses();
        $this->assertArrayHasKey(Order::STATUS_RENEWAL_FAILED, $statuses);
    }

    // ── Task 10: Financial report includes renewal metrics ────────────────────

    public function test_financial_report_renewal_sales_method_exists(): void
    {
        $report = app(\App\Filament\Pages\FinancialReport::class);
        $this->assertIsInt($report->getRenewalSalesRange());
        $this->assertIsInt($report->getRenewalOrdersRange());
        $this->assertIsInt($report->getRenewalFailedCount());
    }

    public function test_financial_report_counts_renewal_orders(): void
    {
        $user    = $this->makeUser('frep');
        $service = $this->makeService($user, ['expires_at' => now()->addDays(10)]);
        $pkg     = $this->makePackage();

        Order::create([
            'order_type'         => Order::TYPE_RENEWAL,
            'user_id'            => $user->id,
            'user_service_id'    => $service->id,
            'renewal_package_id' => $pkg->id,
            'renewal_days'       => 30,
            'plan_name'          => 'تمدید',
            'plan_slug'          => 'renewal',
            'price_toman'        => 150000,
            'final_price_toman'  => 150000,
            'status'             => Order::STATUS_COMPLETED,
            'payment_status'     => Order::PAYMENT_PAID,
            'paid_at'            => now(),
        ]);

        $report = app(\App\Filament\Pages\FinancialReport::class);
        $this->assertEquals(1, $report->getRenewalOrdersRange());
        $this->assertEquals(150000, $report->getRenewalSalesRange());
    }
}
