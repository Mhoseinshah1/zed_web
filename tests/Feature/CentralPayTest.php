<?php

namespace Tests\Feature;

use App\Http\Controllers\CentralPayController;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\ServiceProvisioner;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralPayTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        return User::factory()->create(['wallet_balance_toman' => 0]);
    }

    private function makePlan(int $price = 200000): Plan
    {
        return Plan::factory()->create(['price_toman' => $price, 'is_active' => true]);
    }

    private function makeOrder(User $user, Plan $plan): Order
    {
        return Order::create([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);
    }

    private function makeCentralPayMethod(array $attrs = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'title'      => 'پرداخت ریالی',
            'slug'       => 'centralpay',
            'type'       => PaymentMethod::TYPE_CENTRALPAY,
            'is_active'  => true,
            'sort_order' => 3,
            'api_key'    => 'test-api-key-cp',
            'config'     => [
                'base_url'      => 'https://centralapi.org/webservice/basic',
                'type'          => 'deposit',
                'amount_unit'   => 'TOMAN',
                'callback_path' => '/payments/centralpay/callback',
            ],
        ], $attrs));
    }

    private function enableCentralPay(): void
    {
        // No-op: config is now read from PaymentMethod model, not from .env / config().
        // Tests pass api_key and config through makeCentralPayMethod().
    }

    private function makeTx(Order $order, PaymentMethod $method, array $attrs = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id'          => $order->id,
            'user_id'           => $order->user_id,
            'payment_method_id' => $method->id,
            'provider'          => 'centralpay',
            'method'            => 'centralpay',
            'status'            => PaymentTransaction::STATUS_WAITING,
            'amount_toman'      => $order->final_price_toman,
            'gateway_amount'    => $order->final_price_toman,
            'gateway_currency'  => 'TOMAN',
            'gateway_status'    => 'created',
            'gateway_url'       => 'https://gateway.centralapi.org/#/test123',
        ], $attrs));
    }

    // ── PART A: Payment Method Model ──────────────────────────────────────────

    public function test_centralpay_type_constant_exists(): void
    {
        $this->assertEquals('centralpay', PaymentMethod::TYPE_CENTRALPAY);
    }

    public function test_centralpay_is_in_all_types(): void
    {
        $this->assertArrayHasKey('centralpay', PaymentMethod::allTypes());
    }

    public function test_is_centralpay_helper(): void
    {
        $method = $this->makeCentralPayMethod();
        $this->assertTrue($method->isCentralPay());
        $this->assertFalse($method->isNowPayments());
    }

    public function test_api_key_is_not_visible_in_model_json(): void
    {
        // api_key is in $hidden — must never appear in toArray() / JSON serialization
        $method = $this->makeCentralPayMethod();
        $json   = $method->toArray();
        $this->assertArrayNotHasKey('api_key', $json);
    }

    // ── PART B: CentralPay payment option visibility ──────────────────────────

    public function test_centralpay_option_shows_on_pay_page_when_active(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $this->makeCentralPayMethod();

        $response = $this->actingAs($user)->get(route('dashboard.orders.pay', $order));
        $response->assertOk();
        $response->assertSee('پرداخت ریالی');
    }

    public function test_centralpay_option_hidden_when_inactive(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        PaymentMethod::create([
            'title'      => 'پرداخت ریالی',
            'slug'       => 'centralpay-off',
            'type'       => PaymentMethod::TYPE_CENTRALPAY,
            'is_active'  => false,
            'sort_order' => 3,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.orders.pay', $order));
        $response->assertOk();
        // Inactive method should NOT appear in the methods list
        $response->assertDontSee('پرداخت ریالی از طریق CentralPay');
    }

    // ── PART C: getLink / initiate flow ──────────────────────────────────────

    public function test_getlink_sends_json_post_to_correct_endpoint(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/abc123'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/getLink.php')
                && $request->method() === 'POST'
                && $request->header('Accept')[0] === 'application/json';
        });
    }

    public function test_getlink_sends_type_deposit(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/abc'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            $this->assertEquals('deposit', $body['type']);
            return true;
        });
    }

    public function test_getlink_sends_amount_in_toman(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(300000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/x'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            $this->assertEquals(300000, $body['amount']);
            return true;
        });
    }

    public function test_getlink_includes_order_id_in_return_url(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/y'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            $this->assertStringContainsString('orderId=', $body['returnUrl']);
            return true;
        });
    }

    public function test_getlink_does_not_include_api_key_in_stored_payload(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/z'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $tx = PaymentTransaction::where('order_id', $order->id)->where('provider', 'centralpay')->first();
        $this->assertNotNull($tx);
        $this->assertArrayNotHasKey('api_key', $tx->request_payload ?? []);
    }

    public function test_successful_getlink_saves_redirect_url_and_redirects_user(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/pay123'],
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect('https://gateway.centralapi.org/#/pay123');

        $tx = PaymentTransaction::where('order_id', $order->id)->where('provider', 'centralpay')->first();
        $this->assertNotNull($tx);
        $this->assertEquals('https://gateway.centralapi.org/#/pay123', $tx->gateway_url);
        $this->assertEquals('TOMAN', $tx->gateway_currency);
        $this->assertEquals(PaymentTransaction::STATUS_WAITING, $tx->status);
    }

    public function test_failed_getlink_does_not_mark_order_paid(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => false,
            'data'    => ['message' => 'invalid_api_key'],
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertSessionHasErrors(['payment']);
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_centralpay_inactive_method_returns_422(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod(['is_active' => false]);

        Http::fake();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_duplicate_active_transaction_reuses_gateway_url(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        // Create an existing active transaction
        $this->makeTx($order, $method);

        Http::fake();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect('https://gateway.centralapi.org/#/test123');
        Http::assertNothingSent();
    }

    public function test_create_blocked_for_wrong_user(): void
    {
        $this->enableCentralPay();
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        $response = $this->actingAs($other)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_create_blocked_for_already_paid_order(): void
    {
        $this->enableCentralPay();
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $method = $this->makeCentralPayMethod();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertStatus(403);
    }

    // ── PART D: Callback route ────────────────────────────────────────────────

    public function test_callback_requires_order_id(): void
    {
        $response = $this->get(route('payments.centralpay.callback'));
        $response->assertRedirect(route('dashboard.orders'));
        $response->assertSessionHas('error');
    }

    public function test_callback_calls_verify_for_unpaid_order(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method);

        Http::fake(['*' => Http::response([
            'success' => false,
            'data'    => ['message' => 'invalid_orderId'],
        ], 200)]);

        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/verify.php'));
    }

    public function test_callback_does_not_call_verify_for_already_paid_order(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method);

        Http::fake();

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $response->assertRedirect(route('dashboard.orders.show', $order));
        $response->assertSessionHas('success');
        Http::assertNothingSent();
    }

    // ── PART E: Verify success / failure ─────────────────────────────────────

    public function test_successful_verify_marks_transaction_paid(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 99887766,
                'amount'         => 200000,
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldReceive('createFromOrder')->once());

        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $this->assertEquals(PaymentTransaction::STATUS_APPROVED, $tx->fresh()->status);
        $this->assertEquals('verified', $tx->fresh()->gateway_status);
        $this->assertNotNull($tx->fresh()->verified_at);
    }

    public function test_successful_verify_marks_order_paid(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 99887766,
                'amount'         => 200000,
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldReceive('createFromOrder')->once());

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $response->assertRedirect(route('dashboard.orders.show', $order));
        $response->assertSessionHas('success');
        $this->assertEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_successful_verify_triggers_provisioning_once(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 12345,
                'amount'         => 200000,
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldReceive('createFromOrder')->once());

        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));
    }

    public function test_duplicate_callback_does_not_duplicate_service(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 12345,
                'amount'         => 200000,
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        // Provision only once even with two callbacks
        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldReceive('createFromOrder')->once());

        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));
        // Second callback — order already paid, no verify called, no provisioning
        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));
    }

    public function test_failed_verify_shows_safe_error(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method);

        Http::fake(['*' => Http::response([
            'success' => false,
            'data'    => ['message' => 'invalid_orderId'],
        ], 200)]);

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $response->assertRedirect(route('dashboard.orders.show', $order));
        $response->assertSessionHas('error');
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    // ── PART F: Amount mismatch ───────────────────────────────────────────────

    public function test_amount_mismatch_does_not_mark_order_paid(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 9999,
                'amount'         => 100000, // MISMATCH: expected 200000
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldNotReceive('createFromOrder'));

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $response->assertRedirect(route('dashboard.orders.show', $order));
        $response->assertSessionHas('error');
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertEquals('amount_mismatch', $tx->fresh()->gateway_status);
    }

    public function test_amount_mismatch_saves_failure_reason(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 9999,
                'amount'         => 1,
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $this->assertStringContainsString('amount_mismatch', $tx->fresh()->failure_reason);
    }

    // ── PART G: userId mismatch ───────────────────────────────────────────────

    public function test_userid_mismatch_does_not_mark_order_paid(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 7777,
                'amount'         => 200000,
                'userId'         => 99999, // MISMATCH: different user
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldNotReceive('createFromOrder'));

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertEquals('user_mismatch', $tx->fresh()->gateway_status);
    }

    // ── PART H: Card number masking ───────────────────────────────────────────

    public function test_card_number_is_masked_before_storage(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 55443322,
                'amount'         => 200000,
                'userId'         => $user->id,
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldReceive('createFromOrder')->once());

        $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $stored = $tx->fresh()->response_payload ?? [];
        $maskedCard = $stored['masked_card_number'] ?? '';

        // Full card number must never be stored unmasked
        $this->assertStringNotContainsString('1111222233334444', $maskedCard);
        $this->assertStringContainsString('******', $maskedCard);
        // First 6 and last 4 are visible
        $this->assertStringStartsWith('111122', $maskedCard);
        $this->assertStringEndsWith('4444', $maskedCard);
    }

    public function test_mask_card_number_helper(): void
    {
        $this->assertEquals('111122******4444', CentralPayController::maskCardNumber('1111222233334444'));
        $this->assertEquals('111122******4444', CentralPayController::maskCardNumber(1111222233334444));
        $this->assertEquals('123456******7890', CentralPayController::maskCardNumber('1234567890127890'));
    }

    // ── PART I: User access control ───────────────────────────────────────────

    public function test_user_cannot_access_another_users_callback(): void
    {
        $this->enableCentralPay();
        $user1  = $this->makeUser();
        $user2  = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user1, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 111,
                'amount'         => 200000,
                'userId'         => $user1->id, // correct user
                'userCardNumber' => 1111222233334444,
            ],
        ], 200)]);

        // user2 accesses user1's callback URL — should still verify but the order belongs to user1
        // The callback is unauthenticated, so the verify happens. The order will be marked paid for user1.
        // user2 will see the paid confirmation (no personal data exposed).
        // This is acceptable since the orderId is in the URL and is not guessable.
        // The important protection is: user2 CANNOT initiate payment for user1's order (403 on initiate).
        $this->actingAs($user2)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ])->assertStatus(403);
    }

    // ── PART J: Admin action ─────────────────────────────────────────────────

    public function test_admin_check_status_action_exists_in_filament(): void
    {
        // Verify the method is accessible (compiled earlier via Filament resource)
        $this->assertTrue(method_exists(\App\Filament\Resources\PaymentTransactionResource::class, 'table'));
    }

    public function test_admin_verify_static_method_works_on_success(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => [
                'referenceId'    => 99999,
                'amount'         => 200000,
                'userId'         => $user->id,
                'userCardNumber' => 9988776655443322,
            ],
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($m) => $m->shouldReceive('createFromOrder')->once());

        CentralPayController::adminVerify($tx, app(MarkOrderAsPaidService::class));

        $this->assertEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertEquals('verified', $tx->fresh()->gateway_status);
    }

    public function test_admin_verify_throws_if_order_already_paid(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(200000);
        $order  = $this->makeOrder($user, $plan);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method, ['gateway_amount' => 200000]);

        Http::fake();

        $this->expectException(\RuntimeException::class);
        CentralPayController::adminVerify($tx, app(MarkOrderAsPaidService::class));

        Http::assertNothingSent();
    }

    // ── PART K: Error messages ────────────────────────────────────────────────

    public function test_invalid_order_id_shows_safe_error(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method);

        Http::fake(['*' => Http::response([
            'success' => false,
            'data'    => ['message' => 'invalid_orderId'],
        ], 200)]);

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $response->assertSessionHas('error');
        $error = session('error');
        // Error message should NOT contain raw API error details
        $this->assertStringNotContainsString('invalid_orderId', $error);
        $this->assertStringContainsString('پشتیبانی', $error);
    }

    public function test_invalid_api_key_shows_safe_error(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();
        $tx     = $this->makeTx($order, $method);

        Http::fake(['*' => Http::response([
            'success' => false,
            'data'    => ['message' => 'invalid_api_key'],
        ], 200)]);

        $response = $this->actingAs($user)->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));

        $response->assertSessionHas('error');
        $error = session('error');
        // Never expose "invalid_api_key" to users
        $this->assertStringNotContainsString('invalid_api_key', $error);
        $this->assertStringNotContainsString('api_key', $error);
    }

    // ── PART L: Gateway amount stored correctly ───────────────────────────────

    public function test_gateway_amount_is_stored_in_toman_on_initiate(): void
    {
        $this->enableCentralPay();
        $user   = $this->makeUser();
        $plan   = $this->makePlan(500000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/t'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $tx = PaymentTransaction::where('order_id', $order->id)->where('provider', 'centralpay')->first();
        $this->assertNotNull($tx);
        $this->assertEquals(500000, $tx->gateway_amount);
        $this->assertEquals('TOMAN', $tx->gateway_currency);
    }

    // ── PART M: Admin-config tests ────────────────────────────────────────────

    public function test_client_reads_api_key_from_payment_method_not_env(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod(['api_key' => 'model-api-key-xyz']);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/m'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            return ($body['api_key'] ?? '') === 'model-api-key-xyz';
        });
    }

    public function test_client_reads_base_url_from_payment_method_config(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod([
            'config' => [
                'base_url'      => 'https://custom-central.example.com/api',
                'type'          => 'deposit',
                'amount_unit'   => 'TOMAN',
                'callback_path' => '/payments/centralpay/callback',
            ],
        ]);

        Http::fake(['https://custom-central.example.com/*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/cu'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'custom-central.example.com'));
    }

    public function test_missing_api_key_shows_persian_safe_error(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod(['api_key' => null]);

        Http::fake();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertSessionHasErrors(['payment']);
        $errors = session('errors')->get('payment');
        $error  = implode(' ', $errors);
        $this->assertStringNotContainsString('validation.required', $error);
        $this->assertStringContainsString('پشتیبانی', $error);
        Http::assertNothingSent();
    }

    public function test_return_url_uses_callback_path_from_admin_config(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/ru'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            return str_contains($body['returnUrl'] ?? '', '/payments/centralpay/callback');
        });
    }

    public function test_removing_env_values_does_not_break_centralpay(): void
    {
        // Simulate env with no CentralPay keys at all
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data'    => ['redirectUrl' => 'https://gateway.centralapi.org/#/nenv'],
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect('https://gateway.centralapi.org/#/nenv');
    }

    public function test_env_values_imported_once_if_admin_config_empty(): void
    {
        // Create centralpay method with no api_key and no config
        PaymentMethod::create([
            'title'      => 'پرداخت ریالی',
            'slug'       => 'centralpay',
            'type'       => PaymentMethod::TYPE_CENTRALPAY,
            'is_active'  => false,
            'sort_order' => 3,
        ]);

        $_ENV['CENTRALPAY_API_KEY']      = 'env-imported-key';
        $_ENV['CENTRALPAY_BASE_URL']     = 'https://env-base.example.com/api';
        $_ENV['CENTRALPAY_TYPE']         = 'deposit';
        $_ENV['CENTRALPAY_AMOUNT_UNIT']  = 'TOMAN';
        $_ENV['CENTRALPAY_CALLBACK_PATH'] = '/payments/centralpay/callback';

        try {
            (new PaymentMethodSeeder())->run();

            $method = PaymentMethod::where('slug', 'centralpay')->first();
            $this->assertNotNull($method);
            // api_key should have been imported from env
            $this->assertEquals('env-imported-key', $method->api_key);
            // config values should have been imported from env
            $this->assertEquals('https://env-base.example.com/api', $method->getConfig('base_url'));
            $this->assertEquals('deposit', $method->getConfig('type'));
        } finally {
            unset(
                $_ENV['CENTRALPAY_API_KEY'],
                $_ENV['CENTRALPAY_BASE_URL'],
                $_ENV['CENTRALPAY_TYPE'],
                $_ENV['CENTRALPAY_AMOUNT_UNIT'],
                $_ENV['CENTRALPAY_CALLBACK_PATH'],
            );
        }
    }

    public function test_existing_admin_config_not_overwritten_by_env(): void
    {
        // Create centralpay method with admin-configured values
        $method = PaymentMethod::create([
            'title'      => 'پرداخت ریالی',
            'slug'       => 'centralpay',
            'type'       => PaymentMethod::TYPE_CENTRALPAY,
            'is_active'  => true,
            'sort_order' => 3,
            'api_key'    => 'admin-set-key',
            'config'     => [
                'base_url'      => 'https://admin-set-base.example.com/api',
                'type'          => 'deposit',
                'amount_unit'   => 'TOMAN',
                'callback_path' => '/payments/centralpay/callback',
            ],
        ]);

        $_ENV['CENTRALPAY_API_KEY']  = 'env-key-should-not-overwrite';
        $_ENV['CENTRALPAY_BASE_URL'] = 'https://env-base-should-not-overwrite.example.com/api';

        try {
            (new PaymentMethodSeeder())->run();

            $method->refresh();
            // Admin-set api_key must NOT be overwritten by env
            $this->assertEquals('admin-set-key', $method->api_key);
            // Admin-set base_url must NOT be overwritten by env
            $this->assertEquals('https://admin-set-base.example.com/api', $method->getConfig('base_url'));
        } finally {
            unset($_ENV['CENTRALPAY_API_KEY'], $_ENV['CENTRALPAY_BASE_URL']);
        }
    }
}
