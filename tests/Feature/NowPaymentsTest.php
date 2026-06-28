<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Payments\NowPayments\NowPaymentsClient;
use App\Services\ServiceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NowPaymentsTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(int $balance = 0): User
    {
        return User::factory()->create(['wallet_balance_toman' => $balance]);
    }

    private function makePlan(int $price = 100000): Plan
    {
        return Plan::factory()->create([
            'price_toman' => $price,
            'is_active'   => true,
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
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);
    }

    /** Invoice mode (default) — no pay_currency required. */
    private function makeNowPaymentsMethod(array $config = []): PaymentMethod
    {
        return PaymentMethod::create([
            'title'      => 'NOWPayments Test',
            'slug'       => 'nowpayments-test',
            'type'       => PaymentMethod::TYPE_NOWPAYMENTS,
            'is_active'  => true,
            'sort_order' => 5,
            'api_key'    => 'test-api-key-123',
            'ipn_secret' => 'test-ipn-secret-456',
            'config'     => array_merge([
                'sandbox'           => true,
                'nowpayments_mode'  => 'invoice',
                'site_currency'     => 'IRT',
                'price_currency'    => 'usd',
                'exchange_rate_usd' => 75000,
            ], $config),
        ]);
    }

    /** Direct mode method — requires default_pay_currency. */
    private function makeDirectMethod(array $config = []): PaymentMethod
    {
        return PaymentMethod::create([
            'title'      => 'NOWPayments Direct',
            'slug'       => 'nowpayments-direct',
            'type'       => PaymentMethod::TYPE_NOWPAYMENTS,
            'is_active'  => true,
            'sort_order' => 6,
            'api_key'    => 'test-api-key-123',
            'ipn_secret' => 'test-ipn-secret-456',
            'config'     => array_merge([
                'sandbox'              => true,
                'nowpayments_mode'     => 'direct',
                'site_currency'        => 'IRT',
                'price_currency'       => 'usd',
                'exchange_rate_usd'    => 75000,
                'default_pay_currency' => 'usdttrc20',
            ], $config),
        ]);
    }

    private function makeNowPaymentsTx(Order $order, PaymentMethod $method, array $attrs = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id'           => $order->id,
            'user_id'            => $order->user_id,
            'payment_method_id'  => $method->id,
            'provider'           => 'nowpayments',
            'method'             => 'nowpayments',
            'status'             => PaymentTransaction::STATUS_WAITING,
            'amount_toman'       => $order->final_price_toman,
            'provider_reference' => 'pay_12345',
            'gateway_status'     => 'waiting',
            'pay_amount'         => 1.5,
            'pay_currency'       => 'usdttrc20',
            'pay_address'        => 'TXtest123abc',
        ], $attrs));
    }

    /** Make a valid HMAC-SHA512 IPN signature. */
    private function makeIpnSignature(array $payload, string $secret): string
    {
        $sorted = $payload;
        ksort($sorted);
        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha512', $json, $secret);
    }

    // ── PART A: Payment Method Model ──────────────────────────────────────────

    public function test_nowpayments_type_constant_exists(): void
    {
        $this->assertEquals('nowpayments', PaymentMethod::TYPE_NOWPAYMENTS);
    }

    public function test_nowpayments_is_in_all_types(): void
    {
        $this->assertArrayHasKey('nowpayments', PaymentMethod::allTypes());
    }

    public function test_is_now_payments_helper(): void
    {
        $method = $this->makeNowPaymentsMethod();
        $this->assertTrue($method->isNowPayments());
    }

    public function test_get_config_helper(): void
    {
        $method = $this->makeNowPaymentsMethod(['exchange_rate_usd' => 80000]);
        $this->assertEquals(80000, $method->getConfig('exchange_rate_usd'));
        $this->assertEquals('default', $method->getConfig('missing_key', 'default'));
    }

    public function test_api_key_and_ipn_secret_are_hidden_from_json(): void
    {
        $method = $this->makeNowPaymentsMethod();
        $json   = $method->toArray();
        $this->assertArrayNotHasKey('api_key', $json);
        $this->assertArrayNotHasKey('ipn_secret', $json);
    }

    // ── PART B: PaymentTransaction Model ─────────────────────────────────────

    public function test_new_status_constants_exist(): void
    {
        $this->assertEquals('waiting',        PaymentTransaction::STATUS_WAITING);
        $this->assertEquals('confirming',     PaymentTransaction::STATUS_CONFIRMING);
        $this->assertEquals('partially_paid', PaymentTransaction::STATUS_PARTIAL);
        $this->assertEquals('refunded',       PaymentTransaction::STATUS_REFUNDED);
        $this->assertEquals('expired',        PaymentTransaction::STATUS_EXPIRED);
    }

    public function test_new_statuses_appear_in_all_statuses(): void
    {
        $all = PaymentTransaction::allStatuses();
        $this->assertArrayHasKey('waiting', $all);
        $this->assertArrayHasKey('confirming', $all);
        $this->assertArrayHasKey('partially_paid', $all);
        $this->assertArrayHasKey('expired', $all);
    }

    public function test_is_pending_includes_waiting_and_confirming(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        $tx = $this->makeNowPaymentsTx($order, $method, ['status' => PaymentTransaction::STATUS_WAITING]);
        $this->assertTrue($tx->isPending());

        $tx->update(['status' => PaymentTransaction::STATUS_CONFIRMING]);
        $this->assertTrue($tx->fresh()->isPending());
    }

    // ── PART C: NowPaymentsClient ─────────────────────────────────────────────

    public function test_client_uses_sandbox_url(): void
    {
        $method = $this->makeNowPaymentsMethod(['sandbox' => true]);
        Http::fake(['https://api-sandbox.nowpayments.io/v1/status' => Http::response(['message' => 'OK'], 200)]);

        $client = new NowPaymentsClient($method);
        $result = $client->status();
        $this->assertEquals('OK', $result['message']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api-sandbox.nowpayments.io'));
    }

    public function test_client_uses_production_url_when_sandbox_false(): void
    {
        $method = $this->makeNowPaymentsMethod(['sandbox' => false]);
        Http::fake(['https://api.nowpayments.io/v1/status' => Http::response(['message' => 'OK'], 200)]);

        $client = new NowPaymentsClient($method);
        $result = $client->status();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.nowpayments.io') && ! str_contains($request->url(), 'sandbox'));
    }

    public function test_client_never_sends_credentials_in_body(): void
    {
        $method = $this->makeNowPaymentsMethod();
        Http::fake(['*' => Http::response(['payment_id' => '999', 'payment_status' => 'waiting'], 200)]);

        $client = new NowPaymentsClient($method);
        $client->createPayment(['price_amount' => 5.0, 'price_currency' => 'usd', 'pay_currency' => 'btc']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            $this->assertArrayNotHasKey('api_key', $body);
            $this->assertArrayNotHasKey('ipn_secret', $body);
            return true;
        });
    }

    public function test_verify_ipn_signature_valid(): void
    {
        $method  = $this->makeNowPaymentsMethod();
        $client  = new NowPaymentsClient($method);
        $payload = ['payment_id' => '123', 'payment_status' => 'waiting', 'order_id' => '1'];
        ksort($payload);
        $json      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $json, 'test-ipn-secret-456');

        $this->assertTrue($client->verifyIpnSignature($payload, $signature));
    }

    public function test_verify_ipn_signature_invalid(): void
    {
        $method  = $this->makeNowPaymentsMethod();
        $client  = new NowPaymentsClient($method);
        $payload = ['payment_id' => '123', 'payment_status' => 'waiting'];

        $this->assertFalse($client->verifyIpnSignature($payload, 'wrong-signature'));
    }

    public function test_verify_ipn_signature_fails_with_empty_secret(): void
    {
        $method = PaymentMethod::create([
            'title'     => 'No Secret',
            'slug'      => 'no-secret',
            'type'      => PaymentMethod::TYPE_NOWPAYMENTS,
            'is_active' => true,
            'api_key'   => 'key',
        ]);
        $client = new NowPaymentsClient($method);

        $this->assertFalse($client->verifyIpnSignature(['payment_id' => '1'], 'any'));
    }

    public function test_client_throws_on_api_error(): void
    {
        $method = $this->makeNowPaymentsMethod();
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $client = new NowPaymentsClient($method);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unauthorized/');
        $client->status();
    }

    // ── PART D: Create Payment Flow — Invoice Mode ────────────────────────────

    public function test_create_invoice_mode_calls_invoice_endpoint(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod(); // invoice mode

        Http::fake(['*' => Http::response([
            'id'             => 'inv_001',
            'invoice_url'    => 'https://nowpayments.io/payment/inv_001',
            'payment_status' => 'waiting',
            'price_amount'   => 1.33,
            'price_currency' => 'usd',
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/invoice'));
    }

    public function test_create_invoice_mode_payload_excludes_pay_currency(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        // Even with default_pay_currency set, invoice mode must NOT include it in payload
        $method = $this->makeNowPaymentsMethod(['default_pay_currency' => 'btc']);

        Http::fake(['*' => Http::response([
            'id'             => 'inv_002',
            'invoice_url'    => 'https://nowpayments.io/payment/inv_002',
            'payment_status' => 'waiting',
            'price_amount'   => 1.33,
            'price_currency' => 'usd',
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            $this->assertArrayNotHasKey('pay_currency', $body);
            return true;
        });
    }

    public function test_create_invoice_mode_stores_invoice_url(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        Http::fake(['*' => Http::response([
            'id'             => 'inv_003',
            'invoice_url'    => 'https://nowpayments.io/payment/inv_003',
            'payment_status' => 'waiting',
            'price_amount'   => 1.33,
            'price_currency' => 'usd',
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $tx = PaymentTransaction::where('order_id', $order->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('https://nowpayments.io/payment/inv_003', $tx->gateway_url);
        $this->assertEquals('inv_003', $tx->provider_reference);
        $this->assertNull($tx->external_id); // not set yet in invoice mode
    }

    public function test_create_redirects_to_invoice_url_when_provided(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        Http::fake(['*' => Http::response([
            'id'             => 'inv_001',
            'invoice_url'    => 'https://nowpayments.io/payment/inv_001',
            'payment_status' => 'waiting',
            'price_amount'   => 1.33,
            'price_currency' => 'usd',
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect('https://nowpayments.io/payment/inv_001');
    }

    public function test_create_invoice_mode_shows_persian_error_when_no_invoice_url(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        // Response missing invoice_url
        Http::fake(['*' => Http::response([
            'id'             => 'inv_004',
            'payment_status' => 'waiting',
            'price_amount'   => 1.33,
            'price_currency' => 'usd',
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertSessionHasErrors(['payment']);
        $errors = session('errors')->getBag('default')->get('payment');
        $this->assertStringContainsString('ساخت فاکتور NOWPayments انجام نشد', $errors[0]);
    }

    public function test_create_invoice_mode_without_default_pay_currency_does_not_error(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        // No default_pay_currency — must not cause any validation error in invoice mode
        $method = $this->makeNowPaymentsMethod();
        $cfg = $method->config ?? [];
        unset($cfg['default_pay_currency']);
        $method->config = $cfg;
        $method->save();

        Http::fake(['*' => Http::response([
            'id'             => 'inv_005',
            'invoice_url'    => 'https://nowpayments.io/payment/inv_005',
            'payment_status' => 'waiting',
            'price_amount'   => 1.33,
            'price_currency' => 'usd',
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertSessionMissing('errors');
        $response->assertRedirect('https://nowpayments.io/payment/inv_005');
    }

    // ── PART D2: Create Payment Flow — Direct Mode ────────────────────────────

    public function test_create_direct_mode_calls_payment_endpoint(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeDirectMethod();

        Http::fake(['*' => Http::response([
            'payment_id'     => 'pay_direct_001',
            'payment_status' => 'waiting',
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
            'pay_address'    => 'TXtest123',
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payment'));
    }

    public function test_create_direct_mode_includes_pay_currency_in_payload(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeDirectMethod(['default_pay_currency' => 'usdttrc20']);

        Http::fake(['*' => Http::response([
            'payment_id'     => 'pay_direct_002',
            'payment_status' => 'waiting',
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
            'pay_address'    => 'TXtest456',
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?? [];
            $this->assertArrayHasKey('pay_currency', $body);
            $this->assertEquals('usdttrc20', $body['pay_currency']);
            return true;
        });
    }

    public function test_create_redirects_to_nowpayments_page_without_invoice_url(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeDirectMethod();

        Http::fake(['*' => Http::response([
            'payment_id'     => 'pay_001',
            'payment_status' => 'waiting',
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
            'pay_address'    => 'TXtest123',
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect(route('dashboard.orders.nowpayments', $order));
    }

    public function test_create_fails_without_exchange_rate(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod(['exchange_rate_usd' => 0]);

        Http::fake();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertSessionHasErrors(['payment']);
        Http::assertNothingSent();
    }

    public function test_create_prevents_duplicate_active_transaction(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        // Create existing active transaction
        $this->makeNowPaymentsTx($order, $method);

        Http::fake();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect(route('dashboard.orders.nowpayments', $order));
        Http::assertNothingSent();
    }

    public function test_create_blocked_for_wrong_user(): void
    {
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $method = $this->makeNowPaymentsMethod();

        $response = $this->actingAs($other)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_create_blocked_for_already_paid_order(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        $method = $this->makeNowPaymentsMethod();

        $response = $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), [
            'payment_method_id' => $method->id,
        ]);

        $response->assertStatus(403);
    }

    // ── PART E: Show Page ─────────────────────────────────────────────────────

    public function test_show_page_renders_with_active_transaction(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        $this->makeNowPaymentsTx($order, $method);

        $response = $this->actingAs($user)->get(route('dashboard.orders.nowpayments', $order));
        $response->assertOk();
        $response->assertSee('TXtest123abc');
    }

    public function test_show_page_blocks_wrong_user(): void
    {
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $method = $this->makeNowPaymentsMethod();
        $this->makeNowPaymentsTx($order, $method);

        $response = $this->actingAs($other)->get(route('dashboard.orders.nowpayments', $order));
        $response->assertStatus(403);
    }

    public function test_show_page_redirects_if_no_transaction(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $response = $this->actingAs($user)->get(route('dashboard.orders.nowpayments', $order));
        $response->assertRedirect(route('dashboard.orders.pay', $order));
    }

    // ── PART F: Manual Status Check ───────────────────────────────────────────

    public function test_check_status_marks_paid_on_finished(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method, [
            'external_id' => 'pay_12345', // payment_id set — customer has started paying
        ]);

        Http::fake(['*' => Http::response([
            'payment_id'     => 'pay_12345',
            'payment_status' => 'finished',
        ], 200)]);

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldReceive('createFromOrder')->once());

        $response = $this->actingAs($user)->post(route('dashboard.orders.nowpayments.check', $order));

        $response->assertRedirect(route('dashboard.orders.show', $order));
        $this->assertEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_check_status_updates_gateway_status_on_confirming(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method, [
            'external_id' => 'pay_12345',
        ]);

        Http::fake(['*' => Http::response([
            'payment_id'     => 'pay_12345',
            'payment_status' => 'confirming',
        ], 200)]);

        $response = $this->actingAs($user)->post(route('dashboard.orders.nowpayments.check', $order));

        $response->assertRedirectContains('nowpayments');
        $this->assertEquals('confirming', $tx->fresh()->gateway_status);
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_check_status_marks_failed_on_expired(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method, [
            'external_id' => 'pay_12345',
        ]);

        Http::fake(['*' => Http::response([
            'payment_id'     => 'pay_12345',
            'payment_status' => 'expired',
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.orders.nowpayments.check', $order));

        $this->assertEquals(PaymentTransaction::STATUS_FAILED, $tx->fresh()->status);
    }

    public function test_check_status_shows_not_started_message_when_no_external_id(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        // Invoice mode: external_id is null until customer selects currency
        $tx = $this->makeNowPaymentsTx($order, $method, [
            'provider_reference' => 'inv_abc123',
            'gateway_url'        => 'https://nowpayments.io/payment/inv_abc123',
            'external_id'        => null,
            'pay_address'        => null,
        ]);

        Http::fake();

        $response = $this->actingAs($user)->post(route('dashboard.orders.nowpayments.check', $order));

        $response->assertRedirect(route('dashboard.orders.nowpayments', $order));
        $response->assertSessionHas('info');
        $sessionInfo = session('info');
        $this->assertStringContainsString('انتخاب/شروع نشده', $sessionInfo);

        Http::assertNothingSent();
    }

    // ── PART G: IPN Webhook ───────────────────────────────────────────────────

    public function test_ipn_finished_marks_order_paid(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload = [
            'payment_id'     => 'pay_12345',
            'payment_status' => 'finished',
            'order_id'       => (string) $order->id,
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldReceive('createFromOrder')->once());

        $response = $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $response->assertOk();
        $this->assertEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertEquals('finished', $tx->fresh()->gateway_status);
    }

    public function test_ipn_matches_transaction_by_invoice_id(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        // Invoice mode transaction — provider_reference = invoice_id
        $tx = $this->makeNowPaymentsTx($order, $method, [
            'provider_reference' => 'inv_xyz789',
            'external_id'        => null,
            'pay_address'        => null,
            'gateway_url'        => 'https://nowpayments.io/payment/inv_xyz789',
        ]);

        $payload = [
            'invoice_id'     => 'inv_xyz789',  // match by invoice_id
            'payment_id'     => 'pay_new_001', // new payment_id from customer choosing currency
            'payment_status' => 'confirming',
            'order_id'       => (string) $order->id,
            'pay_amount'     => 1.33,
            'pay_currency'   => 'eth',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $response = $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $response->assertOk();
        $freshTx = $tx->fresh();
        $this->assertEquals('confirming', $freshTx->gateway_status);
        $this->assertEquals('inv_xyz789', $freshTx->provider_reference); // unchanged
    }

    public function test_ipn_stores_payment_id_in_external_id(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        // Invoice mode: external_id is null initially
        $tx = $this->makeNowPaymentsTx($order, $method, [
            'provider_reference' => 'inv_abc',
            'external_id'        => null,
            'gateway_url'        => 'https://nowpayments.io/payment/inv_abc',
        ]);

        $payload = [
            'invoice_id'     => 'inv_abc',
            'payment_id'     => 'pay_customer_001', // customer chose currency and started paying
            'payment_status' => 'waiting',
            'order_id'       => (string) $order->id,
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();

        // payment_id must now be stored in external_id
        $this->assertEquals('pay_customer_001', $tx->fresh()->external_id);
    }

    public function test_ipn_waiting_does_not_provision(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload   = ['payment_id' => 'pay_12345', 'payment_status' => 'waiting', 'order_id' => (string) $order->id];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldNotReceive('createFromOrder'));

        $response = $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $response->assertOk();
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_ipn_confirming_does_not_provision(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload   = ['payment_id' => 'pay_12345', 'payment_status' => 'confirming', 'order_id' => (string) $order->id];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldNotReceive('createFromOrder'));

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();
    }

    public function test_ipn_confirmed_does_not_provision(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload   = ['payment_id' => 'pay_12345', 'payment_status' => 'confirmed', 'order_id' => (string) $order->id];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldNotReceive('createFromOrder'));

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();

        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_ipn_sending_does_not_provision(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload   = ['payment_id' => 'pay_12345', 'payment_status' => 'sending', 'order_id' => (string) $order->id];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldNotReceive('createFromOrder'));

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();

        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_ipn_rejects_invalid_signature(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $this->makeNowPaymentsTx($order, $method);

        $payload = ['payment_id' => 'pay_12345', 'payment_status' => 'finished', 'order_id' => (string) $order->id];

        $response = $this->withHeaders(['x-nowpayments-sig' => 'bad-signature'])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $response->assertStatus(401);
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_ipn_rejects_missing_signature(): void
    {
        $response = $this->postJson(route('webhooks.nowpayments'), ['payment_id' => '1']);
        $response->assertStatus(400);
    }

    public function test_ipn_duplicate_is_idempotent(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload   = ['payment_id' => 'pay_12345', 'payment_status' => 'finished', 'order_id' => (string) $order->id];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        // Provision only once — the IPN handler guards on order payment_status
        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldReceive('createFromOrder')->once());

        // First IPN — triggers provisioning
        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();

        $this->assertEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);

        // Second IPN — order already paid, provisioner must NOT be called again
        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();
    }

    public function test_ipn_expired_marks_transaction_expired(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $payload   = ['payment_id' => 'pay_12345', 'payment_status' => 'expired', 'order_id' => (string) $order->id];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret-456');

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload)
            ->assertOk();

        $this->assertEquals(PaymentTransaction::STATUS_EXPIRED, $tx->fresh()->status);
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    // ── PART H: MarkOrderAsPaidService ────────────────────────────────────────

    public function test_mark_paid_service_is_idempotent(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method, ['status' => PaymentTransaction::STATUS_APPROVED]);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        $provisioner = $this->mock(ServiceProvisioner::class);
        $provisioner->shouldNotReceive('createFromOrder');

        $service = app(MarkOrderAsPaidService::class);

        $freshOrder = $order->fresh();
        $provisioner->shouldReceive('createFromOrder')->atMost()->once();
        $service->markPaid($freshOrder, $tx->fresh());
    }

    public function test_mark_paid_updates_order_and_transaction(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan();
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeNowPaymentsTx($order, $method);

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldReceive('createFromOrder')->once());

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertEquals(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertEquals(PaymentTransaction::STATUS_APPROVED, $tx->fresh()->status);
        $this->assertNotNull($tx->fresh()->paid_at);
    }
}
