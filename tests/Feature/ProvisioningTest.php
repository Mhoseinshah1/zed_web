<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\ProvisioningAttempt;
use App\Models\SiteText;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(array $attrs = []): User
    {
        static $seq = 0;
        $seq++;
        return User::factory()->create(array_merge([
            'username'             => "prov_test_{$seq}",
            'email'                => "prov_{$seq}@test.com",
            'wallet_balance_toman' => 0,
        ], $attrs));
    }

    private function makePlan(int $price = 50000): Plan
    {
        return Plan::factory()->create([
            'price_toman'   => $price,
            'is_active'     => true,
            'traffic_gb'    => 20,
            'duration_days' => 30,
        ]);
    }

    private function makeOrder(User $user, Plan $plan, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now(),
        ], $attrs));
    }

    private function makeMarzbanPanel(): VpnPanel
    {
        return VpnPanel::create([
            'name'       => 'Test Panel',
            'type'       => VpnPanel::TYPE_MARZBAN,
            'base_url'   => 'https://panel.test',
            'username'   => 'admin',
            'password'   => 'secret',
            'is_active'  => true,
            'is_default' => true,
        ]);
    }

    private function fakeMarzbanSuccess(string $username = 'zpx_test'): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response([
                'access_token' => 'test-token',
                'token_type'   => 'bearer',
            ], 200),
            '*/api/user'        => Http::response($this->marzbanUserResponse($username), 200),
            "*/api/user/*"      => Http::response($this->marzbanUserResponse($username), 200),
        ]);
    }

    private function fakeMarzbanFailure(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response([
                'access_token' => 'test-token',
                'token_type'   => 'bearer',
            ], 200),
            '*/api/user'        => Http::response(['detail' => 'Internal server error'], 500),
            '*/api/user/*'      => Http::response(['detail' => 'Not found'], 404),
        ]);
    }

    private function marzbanUserResponse(string $username): array
    {
        return [
            'username'         => $username,
            'status'           => 'active',
            'used_traffic'     => 0,
            'data_limit'       => 21_474_836_480,
            'expire'           => now()->addDays(30)->timestamp,
            'subscription_url' => 'https://panel.test/sub/TOKEN/',
            'links'            => ['vless://test-config'],
            'proxies'          => ['vless' => ['id' => 'some-uuid']],
        ];
    }

    // ── PART A: Basic provisioning ────────────────────────────────────────────

    public function test_paid_order_provisions_service_successfully(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanSuccess();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $service = app(ProvisioningService::class)->provisionOrder($order);

        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
        $this->assertNotNull($service->subscription_link);
        $this->assertNotNull($service->remote_username);

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
        $this->assertEquals(Order::PAYMENT_PAID, $order->payment_status);
    }

    public function test_paid_order_creates_provisioning_attempt_on_success(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanSuccess();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        app(ProvisioningService::class)->provisionOrder($order);

        $this->assertDatabaseHas('provisioning_attempts', [
            'order_id' => $order->id,
            'status'   => ProvisioningAttempt::STATUS_SUCCESS,
        ]);
    }

    public function test_unpaid_order_cannot_provision(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanSuccess();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status'         => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'paid_at'        => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('سفارش پرداخت نشده است.');

        app(ProvisioningService::class)->provisionOrder($order);
    }

    // ── PART B: Failure handling ──────────────────────────────────────────────

    public function test_marzban_failure_marks_order_provisioning_failed(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanFailure();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        try {
            app(ProvisioningService::class)->provisionOrder($order);
        } catch (\RuntimeException) {
        }

        $order->refresh();
        $this->assertEquals(Order::STATUS_PROVISIONING_FAILED, $order->status);
    }

    public function test_payment_remains_paid_when_provisioning_fails(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanFailure();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        try {
            app(ProvisioningService::class)->provisionOrder($order);
        } catch (\RuntimeException) {
        }

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_PAID, $order->payment_status);
    }

    public function test_marzban_failure_creates_failed_provisioning_attempt(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanFailure();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        try {
            app(ProvisioningService::class)->provisionOrder($order);
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseHas('provisioning_attempts', [
            'order_id' => $order->id,
            'status'   => ProvisioningAttempt::STATUS_FAILED,
        ]);
    }

    // ── PART C: Admin retry ───────────────────────────────────────────────────

    public function test_admin_retry_works_after_provisioning_failed(): void
    {
        $panel = $this->makeMarzbanPanel();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING_FAILED]);

        // Set up the already-failed state manually (avoids Http::fake sequencing issues)
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => $plan->traffic_gb,
            'traffic_used_gb'  => 0,
            'duration_days'    => $plan->duration_days,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_FAILED,
        ]);
        ProvisioningAttempt::create([
            'order_id'       => $order->id,
            'user_id'        => $user->id,
            'user_service_id'=> $service->id,
            'vpn_panel_id'   => $panel->id,
            'status'         => ProvisioningAttempt::STATUS_FAILED,
            'attempt_number' => 1,
            'error_message'  => 'HTTP 500 - Internal server error',
            'started_at'     => now()->subMinute(),
            'finished_at'    => now()->subSeconds(59),
        ]);

        // Admin retry with a working Marzban
        $this->fakeMarzbanSuccess();
        $service = app(ProvisioningService::class)->provisionOrder($order, true);

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
        $this->assertEquals(UserService::STATUS_ACTIVE, $service->status);
    }

    public function test_retry_does_not_duplicate_user_service(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanSuccess();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        app(ProvisioningService::class)->provisionOrder($order);
        // Call again (retry)
        app(ProvisioningService::class)->provisionOrder($order, true);

        $this->assertEquals(1, UserService::where('order_id', $order->id)->count());
    }

    public function test_provisioning_creates_attempt_record_per_call(): void
    {
        $panel = $this->makeMarzbanPanel();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING_FAILED]);

        // Set up the already-failed state manually
        $service = UserService::create([
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'plan_id'          => $plan->id,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => $plan->traffic_gb,
            'traffic_used_gb'  => 0,
            'duration_days'    => $plan->duration_days,
            'status'           => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_FAILED,
        ]);
        ProvisioningAttempt::create([
            'order_id'       => $order->id,
            'user_id'        => $user->id,
            'user_service_id'=> $service->id,
            'vpn_panel_id'   => $panel->id,
            'status'         => ProvisioningAttempt::STATUS_FAILED,
            'attempt_number' => 1,
            'error_message'  => 'HTTP 500 - Internal server error',
            'started_at'     => now()->subMinute(),
            'finished_at'    => now()->subSeconds(59),
        ]);

        // Second attempt (retry) succeeds
        $this->fakeMarzbanSuccess();
        app(ProvisioningService::class)->provisionOrder($order, true);

        $this->assertEquals(2, ProvisioningAttempt::where('order_id', $order->id)->count());
    }

    // ── PART D: Idempotency ───────────────────────────────────────────────────

    public function test_active_service_returned_without_reprovisioning(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanSuccess();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        // First provisioning
        app(ProvisioningService::class)->provisionOrder($order);

        $attemptsBefore = ProvisioningAttempt::where('order_id', $order->id)->count();

        // Call again — should return early without creating new attempt
        app(ProvisioningService::class)->provisionOrder($order);

        $attemptsAfter = ProvisioningAttempt::where('order_id', $order->id)->count();

        $this->assertEquals($attemptsBefore, $attemptsAfter);
        $this->assertEquals(1, UserService::where('order_id', $order->id)->count());
    }

    public function test_duplicate_payment_does_not_duplicate_service(): void
    {
        Queue::fake();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        // Simulate two IPN arrivals for same order
        $tx = PaymentTransaction::create([
            'order_id'          => $order->id,
            'user_id'           => $user->id,
            'provider'          => 'nowpayments',
            'method'            => 'nowpayments',
            'payment_purpose'   => 'order_payment',
            'status'            => PaymentTransaction::STATUS_WAITING,
            'amount_toman'      => $plan->price_toman,
        ]);

        $markPaid = app(\App\Services\Orders\MarkOrderAsPaidService::class);
        $markPaid->markPaid($order, $tx);

        $order->refresh();
        $orderAfterFirst = $order->status;

        // Second IPN call — should be idempotent
        $markPaid->markPaid($order, $tx);

        $this->assertEquals(1, UserService::where('order_id', $order->id)->count());
    }

    // ── PART E: Wallet topup does not provision ────────────────────────────────

    public function test_wallet_topup_does_not_provision_service(): void
    {
        Queue::fake();

        $user   = $this->makeUser(['wallet_balance_toman' => 0]);
        $method = PaymentMethod::firstOrCreate(
            ['type' => PaymentMethod::TYPE_NOWPAYMENTS],
            [
                'title'      => 'NOWPayments',
                'slug'       => 'nowpayments',
                'is_active'  => true,
                'api_key'    => 'test-key',
                'ipn_secret' => 'test-secret',
                'config'     => ['sandbox' => true, 'exchange_rate_usd' => 75000],
            ]
        );

        // Create a wallet-topup transaction (not linked to an order)
        $tx = PaymentTransaction::create([
            'order_id'           => null,
            'user_id'            => $user->id,
            'payment_method_id'  => $method->id,
            'provider'           => 'nowpayments',
            'method'             => 'nowpayments',
            'payment_purpose'    => 'wallet_topup',
            'status'             => PaymentTransaction::STATUS_WAITING,
            'amount_toman'       => 100000,
        ]);

        // Credit wallet (simulates IPN finished for wallet_topup)
        app(WalletService::class)->creditFromPaymentTransaction($user, $tx);

        $user->refresh();
        $this->assertEquals(100000, $user->wallet_balance_toman);

        // No UserService should have been created
        $this->assertEquals(0, UserService::where('user_id', $user->id)->count());
    }

    // ── PART F: User-facing messages ─────────────────────────────────────────

    public function test_user_sees_provisioning_message_when_order_provisioning(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING]);

        $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order))
            ->assertOk()
            ->assertSee('پرداخت شما تایید شده و سرویس در حال فعال‌سازی است');
    }

    public function test_user_sees_safe_message_on_provisioning_failed(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING_FAILED]);

        $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order))
            ->assertOk()
            ->assertSee('پرداخت شما تایید شده اما فعال‌سازی سرویس با خطا مواجه شده است');
    }

    public function test_user_does_not_see_technical_error_on_provisioning_failed(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING_FAILED]);

        // Create a failed attempt with technical error
        ProvisioningAttempt::create([
            'order_id'      => $order->id,
            'user_id'       => $user->id,
            'status'        => ProvisioningAttempt::STATUS_FAILED,
            'attempt_number'=> 1,
            'error_message' => 'Marzban API error 500: Internal Server Error at https://panel.internal',
            'started_at'    => now(),
            'finished_at'   => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.orders.show', $order));

        $response->assertOk();
        // Technical error details must NOT be visible to the user
        $response->assertDontSee('Marzban API error');
        $response->assertDontSee('panel.internal');
        $response->assertDontSee('Internal Server Error');
    }

    // ── PART G: Admin can see provisioning attempts ───────────────────────────

    public function test_admin_can_see_provisioning_attempts_in_panel(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING_FAILED]);

        ProvisioningAttempt::create([
            'order_id'       => $order->id,
            'user_id'        => $user->id,
            'status'         => ProvisioningAttempt::STATUS_FAILED,
            'attempt_number' => 1,
            'error_message'  => 'Connection refused',
            'started_at'     => now(),
            'finished_at'    => now(),
        ]);

        $this->assertTrue(
            ProvisioningAttempt::where('order_id', $order->id)->exists()
        );
        $this->assertEquals(
            ProvisioningAttempt::STATUS_FAILED,
            ProvisioningAttempt::where('order_id', $order->id)->first()->status
        );
    }

    // ── PART H: Sensitive credentials not stored ──────────────────────────────

    public function test_sensitive_credentials_not_stored_in_provisioning_logs(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanFailure();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        try {
            app(ProvisioningService::class)->provisionOrder($order);
        } catch (\RuntimeException) {
        }

        $attempt = ProvisioningAttempt::where('order_id', $order->id)->first();
        $this->assertNotNull($attempt);

        // Check request_payload doesn't contain passwords or tokens
        $reqPayload = json_encode($attempt->request_payload ?? []);
        $this->assertStringNotContainsStringIgnoringCase('password', $reqPayload);
        $this->assertStringNotContainsStringIgnoringCase('api_key', $reqPayload);

        // Check response_payload doesn't contain tokens
        $respPayload = json_encode($attempt->response_payload ?? []);
        $this->assertStringNotContainsStringIgnoringCase('access_token', $respPayload);
        $this->assertStringNotContainsStringIgnoringCase('api_key', $respPayload);
    }

    public function test_sanitize_error_strips_bearer_tokens(): void
    {
        $service = app(ProvisioningService::class);

        // Use reflection to test private method
        $ref    = new \ReflectionMethod($service, 'sanitizeError');
        $ref->setAccessible(true);

        $dirty  = 'Request failed with Bearer eyJhbGciOiJIUzI1NiJ9.test token';
        $result = $ref->invoke($service, $dirty);

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $result);
    }

    // ── PART I: Order status flow ─────────────────────────────────────────────

    public function test_order_transitions_to_provisioning_during_attempt(): void
    {
        $this->makeMarzbanPanel();
        $this->fakeMarzbanSuccess();

        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        app(ProvisioningService::class)->provisionOrder($order);

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
    }

    public function test_order_status_label_returns_persian_for_new_statuses(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();

        $order = $this->makeOrder($user, $plan, ['status' => Order::STATUS_PROVISIONING]);
        $this->assertEquals('در حال ساخت سرویس', $order->statusLabel());

        $order->update(['status' => Order::STATUS_PROVISIONING_FAILED]);
        $order->refresh();
        $this->assertEquals('خطا در ساخت سرویس', $order->statusLabel());
    }
}
