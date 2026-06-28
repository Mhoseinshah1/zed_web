<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteText;
use App\Models\User;
use App\Models\UserService;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\ServiceProvisioner;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(array $attrs = []): User
    {
        static $seq = 0;
        $seq++;
        return User::factory()->create(array_merge([
            'wallet_balance_toman' => 0,
            'username'             => "testuser{$seq}",
            'email'                => "testuser{$seq}@example.com",
        ], $attrs));
    }

    private function makePlan(int $price = 50000): Plan
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

    private function makeWalletMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['type' => PaymentMethod::TYPE_WALLET],
            ['title' => 'کیف پول', 'slug' => 'wallet', 'is_active' => true]
        );
    }

    private function makeNowPaymentsMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['type' => PaymentMethod::TYPE_NOWPAYMENTS],
            [
                'title'      => 'NOWPayments',
                'slug'       => 'nowpayments',
                'is_active'  => true,
                'api_key'    => 'test-api-key',
                'ipn_secret' => 'test-ipn-secret',
                'config'     => [
                    'sandbox'           => true,
                    'nowpayments_mode'  => 'invoice',
                    'price_currency'    => 'usd',
                    'exchange_rate_usd' => 75000,
                ],
            ]
        );
    }

    private function enableWallet(): void
    {
        SiteText::firstOrCreate(['key' => 'wallet_enabled'], ['value' => '1', 'group' => 'wallet']);
    }

    private function enableWalletPayment(): void
    {
        $this->enableWallet();
        SiteText::firstOrCreate(['key' => 'wallet_payment_enabled'], ['value' => '1', 'group' => 'wallet']);
    }

    private function enableWalletTopup(): void
    {
        $this->enableWallet();
        SiteText::firstOrCreate(['key' => 'wallet_topup_enabled'], ['value' => '1', 'group' => 'wallet']);
    }

    private function enableNowpaymentsTopup(): void
    {
        $this->enableWalletTopup();
        SiteText::firstOrCreate(['key' => 'wallet_topup_nowpayments_enabled'], ['value' => '1', 'group' => 'wallet']);
    }

    private function makeIpnSignature(array $payload, string $secret): string
    {
        $sorted = $payload;
        ksort($sorted);
        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha512', $json, $secret);
    }

    private function makeWalletTopupTx(User $user, PaymentMethod $method, array $attrs = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id'           => null,
            'user_id'            => $user->id,
            'payment_method_id'  => $method->id,
            'provider'           => 'nowpayments',
            'method'             => 'nowpayments',
            'payment_purpose'    => 'wallet_topup',
            'status'             => PaymentTransaction::STATUS_WAITING,
            'amount_toman'       => 100000,
            'provider_reference' => 'inv_wallet_001',
            'gateway_status'     => 'waiting',
        ], $attrs));
    }

    // ── PART A: WalletService unit tests ──────────────────────────────────────

    public function test_credit_increases_balance(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 10000]);

        app(WalletService::class)->credit($user, 50000, WalletTransaction::TYPE_MANUAL_CREDIT);

        $user->refresh();
        $this->assertEquals(60000, $user->wallet_balance_toman);
    }

    public function test_credit_creates_wallet_transaction(): void
    {
        $user = $this->makeUser();

        app(WalletService::class)->credit($user, 75000, WalletTransaction::TYPE_MANUAL_CREDIT, [
            'description' => 'شارژ دستی',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'              => $user->id,
            'type'                 => WalletTransaction::TYPE_MANUAL_CREDIT,
            'direction'            => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman'         => 75000,
            'balance_before_toman' => 0,
            'balance_after_toman'  => 75000,
            'status'               => WalletTransaction::STATUS_COMPLETED,
        ]);
    }

    public function test_debit_decreases_balance(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 100000]);

        app(WalletService::class)->debit($user, 30000, WalletTransaction::TYPE_ORDER_PAYMENT);

        $user->refresh();
        $this->assertEquals(70000, $user->wallet_balance_toman);
    }

    public function test_debit_creates_wallet_transaction(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 100000]);

        app(WalletService::class)->debit($user, 40000, WalletTransaction::TYPE_ORDER_PAYMENT, [
            'description' => 'پرداخت سفارش',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'              => $user->id,
            'type'                 => WalletTransaction::TYPE_ORDER_PAYMENT,
            'direction'            => WalletTransaction::DIRECTION_DEBIT,
            'amount_toman'         => 40000,
            'balance_before_toman' => 100000,
            'balance_after_toman'  => 60000,
            'status'               => WalletTransaction::STATUS_COMPLETED,
        ]);
    }

    public function test_debit_throws_when_balance_insufficient(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 5000]);

        $this->expectException(\RuntimeException::class);

        app(WalletService::class)->debit($user, 10000, WalletTransaction::TYPE_ORDER_PAYMENT);
    }

    public function test_debit_does_not_make_balance_negative(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 5000]);

        try {
            app(WalletService::class)->debit($user, 10000, WalletTransaction::TYPE_ORDER_PAYMENT);
        } catch (\RuntimeException) {
        }

        $user->refresh();
        $this->assertGreaterThanOrEqual(0, $user->wallet_balance_toman);
        $this->assertEquals(5000, $user->wallet_balance_toman);
    }

    public function test_refund_credits_wallet(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 0]);

        app(WalletService::class)->refund($user, 20000);

        $user->refresh();
        $this->assertEquals(20000, $user->wallet_balance_toman);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $user->id,
            'type'      => WalletTransaction::TYPE_REFUND,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
        ]);
    }

    public function test_get_balance_returns_correct_amount(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 123456]);

        $balance = app(WalletService::class)->getBalance($user);

        $this->assertEquals(123456, $balance);
    }

    public function test_can_pay_returns_true_when_balance_sufficient(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 100000]);

        $this->assertTrue(app(WalletService::class)->canPay($user, 99999));
        $this->assertTrue(app(WalletService::class)->canPay($user, 100000));
    }

    public function test_can_pay_returns_false_when_balance_insufficient(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 50000]);

        $this->assertFalse(app(WalletService::class)->canPay($user, 50001));
    }

    // ── PART B: Wallet page access ────────────────────────────────────────────

    public function test_guest_cannot_access_wallet_page(): void
    {
        $this->get(route('dashboard.wallet'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_wallet_page(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('کیف پول');
    }

    public function test_wallet_page_shows_disabled_message_when_wallet_disabled(): void
    {
        // wallet_enabled not set (defaults to '0' in SiteText::get with old default, or simply absent)
        SiteText::firstOrCreate(['key' => 'wallet_enabled'], ['value' => '0', 'group' => 'wallet']);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('کیف پول در حال حاضر غیرفعال است');
    }

    public function test_wallet_page_shows_balance(): void
    {
        $this->enableWallet();
        $user = $this->makeUser(['wallet_balance_toman' => 250000]);

        $this->actingAs($user)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee(number_format(250000));
    }

    public function test_wallet_page_shows_transaction_history(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 100000]);

        WalletTransaction::create([
            'user_id'              => $user->id,
            'type'                 => WalletTransaction::TYPE_MANUAL_CREDIT,
            'direction'            => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman'         => 100000,
            'balance_before_toman' => 0,
            'balance_after_toman'  => 100000,
            'status'               => WalletTransaction::STATUS_COMPLETED,
            'description'          => 'شارژ تست',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('شارژ دستی');
    }

    public function test_wallet_page_shows_topup_button_when_enabled(): void
    {
        $this->enableWalletTopup();
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('شارژ کیف پول');
    }

    public function test_wallet_page_shows_contact_support_when_topup_disabled(): void
    {
        $this->enableWallet();
        // topup NOT enabled
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('تماس');
    }

    // ── PART C: Users cannot access each other's wallet ───────────────────────

    public function test_user_can_only_see_own_wallet_transactions(): void
    {
        $owner = $this->makeUser(['wallet_balance_toman' => 50000]);
        $other = $this->makeUser();

        WalletTransaction::create([
            'user_id'              => $owner->id,
            'type'                 => WalletTransaction::TYPE_MANUAL_CREDIT,
            'direction'            => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman'         => 50000,
            'balance_before_toman' => 0,
            'balance_after_toman'  => 50000,
            'status'               => WalletTransaction::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($other)
            ->get(route('dashboard.wallet'));

        $response->assertOk();

        // Other user's transactions are not visible to this user
        $this->assertEquals(0, $other->walletTransactions()->count());
    }

    // ── PART D: Wallet payment ────────────────────────────────────────────────

    public function test_wallet_payment_succeeds_when_enabled_and_balance_sufficient(): void
    {
        $this->enableWalletPayment();
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect(route('dashboard.orders.show', $order));

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_PAID, $order->payment_status);

        $user->refresh();
        $this->assertEquals(50000, $user->wallet_balance_toman);
    }

    public function test_wallet_payment_creates_debit_transaction(): void
    {
        $this->enableWalletPayment();
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $user->id,
            'order_id'  => $order->id,
            'type'      => WalletTransaction::TYPE_ORDER_PAYMENT,
            'direction' => WalletTransaction::DIRECTION_DEBIT,
            'amount_toman' => 50000,
        ]);
    }

    public function test_wallet_payment_blocked_when_wallet_disabled(): void
    {
        // wallet_enabled is NOT seeded (defaults to '0')
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->payment_status);
    }

    public function test_wallet_payment_blocked_when_payment_disabled(): void
    {
        $this->enableWallet();
        // wallet_payment_enabled NOT enabled
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->payment_status);
    }

    public function test_wallet_payment_blocked_when_balance_insufficient(): void
    {
        $this->enableWalletPayment();
        $user   = $this->makeUser(['wallet_balance_toman' => 10000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->payment_status);

        $user->refresh();
        $this->assertEquals(10000, $user->wallet_balance_toman); // unchanged
    }

    public function test_wallet_payment_is_idempotent_no_double_debit(): void
    {
        $this->enableWalletPayment();
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        // First payment
        $service = app(PaymentService::class);
        $service->payWithWallet($order, $user);

        $user->refresh();
        $this->assertEquals(50000, $user->wallet_balance_toman);

        // Second payment attempt must throw (order already paid)
        $this->expectException(\RuntimeException::class);
        $service->payWithWallet($order, $user);
    }

    public function test_double_wallet_payment_does_not_double_debit_balance(): void
    {
        $this->enableWalletPayment();
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeWalletMethod();

        $service = app(PaymentService::class);
        $service->payWithWallet($order, $user);

        try {
            $service->payWithWallet($order, $user);
        } catch (\RuntimeException) {
        }

        $user->refresh();
        $this->assertEquals(50000, $user->wallet_balance_toman); // only debited once
    }

    public function test_wallet_payment_does_not_go_negative(): void
    {
        $this->enableWalletPayment();
        $user  = $this->makeUser(['wallet_balance_toman' => 30000]);
        $plan  = $this->makePlan(50000);
        $order = $this->makeOrder($user, $plan);
        $this->makeWalletMethod();

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->payWithWallet($order, $user);

        $user->refresh();
        $this->assertGreaterThanOrEqual(0, $user->wallet_balance_toman);
    }

    public function test_user_cannot_pay_another_users_order(): void
    {
        $this->enableWalletPayment();
        $owner  = $this->makeUser(['wallet_balance_toman' => 100000]);
        $other  = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($owner, $plan);
        $method = $this->makeWalletMethod();

        $this->actingAs($other)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ])
            ->assertForbidden();
    }

    // ── PART E: Wallet top-up page ────────────────────────────────────────────

    public function test_topup_form_returns_404_when_wallet_disabled(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertNotFound();
    }

    public function test_topup_form_returns_404_when_topup_disabled(): void
    {
        $this->enableWallet(); // wallet enabled but topup NOT enabled
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertNotFound();
    }

    public function test_topup_form_accessible_when_enabled(): void
    {
        $this->enableWalletTopup();
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertOk();
    }

    public function test_topup_form_shows_preset_amounts(): void
    {
        $this->enableNowpaymentsTopup();
        SiteText::firstOrCreate(
            ['key' => 'wallet_topup_preset_amounts'],
            ['value' => '50000,100000,200000', 'group' => 'wallet']
        );
        $this->makeNowPaymentsMethod();
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertOk()
            ->assertSee('50,000'); // number_format renders as 50,000
    }

    public function test_topup_form_shows_nowpayments_when_enabled(): void
    {
        $this->enableNowpaymentsTopup();
        $method = $this->makeNowPaymentsMethod();
        $user   = $this->makeUser();

        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertOk()
            ->assertSee($method->title);
    }

    public function test_topup_form_shows_no_methods_when_all_disabled(): void
    {
        $this->enableWalletTopup();
        // NOWPayments NOT enabled for topup
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertOk();

        // Should mention no payment gateway available
        $response->assertSee('درگاه پرداختی');
    }

    public function test_process_topup_blocked_when_wallet_disabled(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('dashboard.wallet.topup.submit'), [
                'amount'            => 100000,
                'payment_method_id' => 1,
            ])
            ->assertForbidden();
    }

    // ── PART F: NOWPayments wallet top-up IPN ─────────────────────────────────

    public function test_ipn_finished_for_wallet_topup_credits_wallet(): void
    {
        $user   = $this->makeUser();
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeWalletTopupTx($user, $method, [
            'provider_reference' => 'inv_wallet_001',
            'amount_toman'       => 100000,
        ]);

        $payload = [
            'invoice_id'     => 'inv_wallet_001',
            'payment_id'     => 'pay_wallet_001',
            'payment_status' => 'finished',
            'order_id'       => 'wallet-' . $user->id . '-1234567890',
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret');

        $response = $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $response->assertOk();

        $user->refresh();
        $this->assertEquals(100000, $user->wallet_balance_toman);
    }

    public function test_ipn_wallet_topup_creates_wallet_transaction(): void
    {
        $user   = $this->makeUser();
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeWalletTopupTx($user, $method, [
            'provider_reference' => 'inv_wallet_002',
            'amount_toman'       => 50000,
        ]);

        $payload = [
            'invoice_id'     => 'inv_wallet_002',
            'payment_id'     => 'pay_wallet_002',
            'payment_status' => 'finished',
            'order_id'       => 'wallet-' . $user->id . '-111',
            'pay_amount'     => 0.75,
            'pay_currency'   => 'eth',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret');

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'               => $user->id,
            'payment_transaction_id' => $tx->id,
            'type'                  => WalletTransaction::TYPE_TOPUP,
            'direction'             => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman'          => 50000,
        ]);
    }

    public function test_duplicate_ipn_does_not_double_credit_wallet(): void
    {
        $user   = $this->makeUser();
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeWalletTopupTx($user, $method, [
            'provider_reference' => 'inv_wallet_003',
            'amount_toman'       => 80000,
        ]);

        $payload = [
            'invoice_id'     => 'inv_wallet_003',
            'payment_id'     => 'pay_wallet_003',
            'payment_status' => 'finished',
            'order_id'       => 'wallet-' . $user->id . '-222',
            'pay_amount'     => 1.0,
            'pay_currency'   => 'usdttrc20',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret');

        // Send IPN twice
        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);
        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $user->refresh();
        $this->assertEquals(80000, $user->wallet_balance_toman); // only credited once

        $this->assertEquals(1, WalletTransaction::where('user_id', $user->id)
            ->where('payment_transaction_id', $tx->id)
            ->count());
    }

    public function test_wallet_topup_ipn_does_not_create_user_service(): void
    {
        $user   = $this->makeUser();
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeWalletTopupTx($user, $method, [
            'provider_reference' => 'inv_wallet_004',
            'amount_toman'       => 100000,
        ]);

        $payload = [
            'invoice_id'     => 'inv_wallet_004',
            'payment_id'     => 'pay_wallet_004',
            'payment_status' => 'finished',
            'order_id'       => 'wallet-' . $user->id . '-333',
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldNotReceive('createFromOrder'));

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $this->assertEquals(0, UserService::where('user_id', $user->id)->count());
    }

    public function test_order_payment_ipn_still_creates_user_service(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeNowPaymentsMethod();

        $tx = PaymentTransaction::create([
            'order_id'           => $order->id,
            'user_id'            => $user->id,
            'payment_method_id'  => $method->id,
            'provider'           => 'nowpayments',
            'method'             => 'nowpayments',
            'payment_purpose'    => 'order_payment',
            'status'             => PaymentTransaction::STATUS_WAITING,
            'amount_toman'       => 50000,
            'provider_reference' => 'pay_order_001',
            'gateway_status'     => 'waiting',
        ]);

        $payload = [
            'payment_id'     => 'pay_order_001',
            'payment_status' => 'finished',
            'order_id'       => (string) $order->id,
            'pay_amount'     => 0.7,
            'pay_currency'   => 'usdttrc20',
        ];
        $signature = $this->makeIpnSignature($payload, 'test-ipn-secret');

        $this->mock(ServiceProvisioner::class, fn ($mock) => $mock->shouldReceive('createFromOrder')->once());

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_PAID, $order->payment_status);
    }

    // ── PART G: creditFromPaymentTransaction idempotency ─────────────────────

    public function test_credit_from_payment_transaction_is_idempotent(): void
    {
        $user   = $this->makeUser();
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeWalletTopupTx($user, $method, ['amount_toman' => 60000]);

        $walletService = app(WalletService::class);

        // Credit twice via same payment transaction
        $walletService->creditFromPaymentTransaction($user, $tx);
        $walletService->creditFromPaymentTransaction($user, $tx);

        $user->refresh();
        $this->assertEquals(60000, $user->wallet_balance_toman); // only credited once

        $this->assertEquals(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
    }

    // ── PART H: Admin wallet management ──────────────────────────────────────

    public function test_admin_manual_credit_increases_balance(): void
    {
        $user = $this->makeUser();

        app(WalletService::class)->credit($user, 200000, WalletTransaction::TYPE_MANUAL_CREDIT, [
            'description' => 'شارژ توسط ادمین',
            'admin_id'    => 1,
        ]);

        $user->refresh();
        $this->assertEquals(200000, $user->wallet_balance_toman);
    }

    public function test_admin_manual_debit_decreases_balance(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 500000]);

        app(WalletService::class)->debit($user, 100000, WalletTransaction::TYPE_MANUAL_DEBIT, [
            'description' => 'برداشت توسط ادمین',
            'admin_id'    => 1,
        ]);

        $user->refresh();
        $this->assertEquals(400000, $user->wallet_balance_toman);
    }

    public function test_admin_manual_debit_cannot_make_balance_negative(): void
    {
        $user = $this->makeUser(['wallet_balance_toman' => 50000]);

        $this->expectException(\RuntimeException::class);

        app(WalletService::class)->debit($user, 100000, WalletTransaction::TYPE_MANUAL_DEBIT, [
            'admin_id' => 1,
        ]);

        $user->refresh();
        $this->assertGreaterThanOrEqual(0, $user->wallet_balance_toman);
    }

    // ── PART I: Wallet settings control behavior ──────────────────────────────

    public function test_wallet_topup_enabled_setting_controls_topup_page(): void
    {
        $user = $this->makeUser();

        // Disabled by default
        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertNotFound();

        // Enable wallet and topup
        $this->enableWalletTopup();

        $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertOk();
    }

    public function test_nowpayments_topup_only_shown_when_enabled(): void
    {
        $this->enableWalletTopup();
        // wallet_topup_nowpayments_enabled NOT set
        $method = $this->makeNowPaymentsMethod();
        $user   = $this->makeUser();

        $response = $this->actingAs($user)
            ->get(route('dashboard.wallet.topup'))
            ->assertOk();

        $response->assertDontSee($method->title);
    }

    public function test_centralpay_topup_disabled_by_default(): void
    {
        // wallet_topup_centralpay_enabled should default to '0'
        $this->assertEquals('0', SiteText::get('wallet_topup_centralpay_enabled', '0'));
    }

    // ── PART J: Security ──────────────────────────────────────────────────────

    public function test_ipn_with_invalid_signature_is_rejected(): void
    {
        $user   = $this->makeUser();
        $method = $this->makeNowPaymentsMethod();
        $tx     = $this->makeWalletTopupTx($user, $method, [
            'provider_reference' => 'inv_invalid_sig',
            'amount_toman'       => 100000,
        ]);

        $payload = [
            'invoice_id'     => 'inv_invalid_sig',
            'payment_status' => 'finished',
            'pay_amount'     => 1.5,
            'pay_currency'   => 'usdttrc20',
        ];

        $response = $this->withHeaders(['x-nowpayments-sig' => 'wrong-signature'])
            ->postJson(route('webhooks.nowpayments'), $payload);

        $response->assertStatus(401);
        $user->refresh();
        $this->assertEquals(0, $user->wallet_balance_toman); // no credit
    }

    public function test_ipn_with_missing_signature_is_rejected(): void
    {
        $payload = [
            'payment_id'     => 'pay_xyz',
            'payment_status' => 'finished',
        ];

        $this->postJson(route('webhooks.nowpayments'), $payload)
            ->assertStatus(400);
    }
}
