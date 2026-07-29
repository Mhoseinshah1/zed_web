<?php

namespace Tests\Feature;

use App\Http\Controllers\CentralPayController;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteText;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Safety and idempotency coverage for the CentralPay money paths.
 *
 * `CentralPayTest` covers the order-payment happy path and its mismatch
 * branches. The **wallet top-up** half of the same controller — initiation,
 * callback, credit, and the admin re-verify — had no coverage at all, even
 * though it is the path that moves money straight into a user's balance with
 * no order to reconcile against.
 *
 * The properties asserted here are the ones whose violation costs real money:
 *
 *   • a top-up credits the wallet EXACTLY once, no matter how many times the
 *     callback is replayed;
 *   • the credit goes to the transaction's OWNER, never to whoever's browser
 *     happens to hit the public return URL;
 *   • an amount or userId that disagrees with the gateway's own verification
 *     credits NOTHING;
 *   • a transaction's `payment_purpose` decides its handler, so an order
 *     payment can never be settled as a wallet credit or vice versa;
 *   • nothing the gateway says is echoed to the user, and the API key never
 *     reaches storage.
 *
 * The gateway is faked at the HTTP layer only — the controller, the services,
 * the models, the database writes and the redirects are all real.
 */
class CentralPaySafetyTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function makeUser(int $balance = 0): User
    {
        return User::factory()->create(['wallet_balance_toman' => $balance]);
    }

    private function makeCentralPayMethod(array $attrs = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'title' => 'پرداخت ریالی',
            'slug' => 'centralpay',
            'type' => PaymentMethod::TYPE_CENTRALPAY,
            'is_active' => true,
            'sort_order' => 3,
            'api_key' => 'test-api-key-cp',
            'config' => [
                'base_url' => 'https://centralapi.org/webservice/basic',
                'type' => 'deposit',
                'amount_unit' => 'TOMAN',
                'callback_path' => '/payments/centralpay/callback',
            ],
        ], $attrs));
    }

    private function enableWalletTopup(): void
    {
        SiteText::set('wallet_enabled', 'true');
        SiteText::set('wallet_topup_enabled', 'true');
        SiteText::set('wallet_topup_centralpay_enabled', 'true');
        SiteText::set('wallet_min_topup_amount', '100000');
    }

    /** A top-up transaction sitting at the gateway, awaiting its callback. */
    private function makeTopupTx(User $user, PaymentMethod $method, int $amount = 250000, array $attrs = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id' => null,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'payment_purpose' => 'wallet_topup',
            'status' => PaymentTransaction::STATUS_WAITING,
            'amount_toman' => $amount,
            'gateway_amount' => $amount,
            'gateway_currency' => 'TOMAN',
            'gateway_status' => 'created',
            'gateway_url' => 'https://gateway.centralapi.org/#/topup123',
        ], $attrs));
    }

    private function makeOrderTx(User $user, PaymentMethod $method, int $amount = 200000): PaymentTransaction
    {
        $plan = Plan::factory()->create(['price_toman' => $amount, 'is_active' => true]);
        $order = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => $amount,
            'final_price_toman' => $amount,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'payment_purpose' => 'order_payment',
            'status' => PaymentTransaction::STATUS_WAITING,
            'amount_toman' => $amount,
            'gateway_amount' => $amount,
            'gateway_currency' => 'TOMAN',
            'gateway_status' => 'created',
            'gateway_url' => 'https://gateway.centralapi.org/#/order123',
        ]);
    }

    /** What the gateway returns from verify.php for a settled payment. */
    private function fakeVerify(array $data, bool $success = true): void
    {
        Http::fake(['*' => Http::response(['success' => $success, 'data' => $data], 200)]);
    }

    private function hitCallback(PaymentTransaction $tx, ?User $as = null)
    {
        $request = $as ? $this->actingAs($as) : $this;

        return $request->get(route('payments.centralpay.callback', ['orderId' => $tx->id]));
    }

    // ── Top-up initiation ──────────────────────────────────────────────────

    public function test_topup_initiation_records_the_requested_amount_and_purpose(): void
    {
        $this->enableWalletTopup();
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => ['redirectUrl' => 'https://gateway.centralapi.org/#/abc'],
        ], 200)]);

        $this->actingAs($user)
            ->post(route('dashboard.wallet.topup.submit'), [
                'payment_method_id' => $method->id,
                'amount' => 300000,
            ])
            ->assertRedirect('https://gateway.centralapi.org/#/abc');

        $tx = PaymentTransaction::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('wallet_topup', $tx->payment_purpose);
        $this->assertNull($tx->order_id, 'a top-up must not be attached to an order');
        $this->assertSame(300000, (int) $tx->amount_toman);
        $this->assertSame(300000, (int) $tx->gateway_amount);
        $this->assertSame(PaymentTransaction::STATUS_WAITING, $tx->status);

        // The balance must not move until the gateway confirms.
        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());
    }

    public function test_the_api_key_never_reaches_the_stored_topup_payload(): void
    {
        $this->enableWalletTopup();
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod(['api_key' => 'super-secret-key-value']);

        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => ['redirectUrl' => 'https://gateway.centralapi.org/#/abc'],
        ], 200)]);

        $this->actingAs($user)->post(route('dashboard.wallet.topup.submit'), [
            'payment_method_id' => $method->id,
            'amount' => 300000,
        ]);

        $tx = PaymentTransaction::where('user_id', $user->id)->firstOrFail();
        $encoded = json_encode([$tx->request_payload, $tx->response_payload], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('super-secret-key-value', (string) $encoded);
        $this->assertArrayNotHasKey('api_key', (array) $tx->request_payload);
    }

    public function test_a_failed_topup_link_never_credits_the_wallet(): void
    {
        $this->enableWalletTopup();
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();

        Http::fake(['*' => Http::response([
            'success' => false,
            'data' => ['message' => 'gateway is unhappy'],
        ], 200)]);

        $response = $this->actingAs($user)
            ->from(route('dashboard.wallet.topup'))
            ->post(route('dashboard.wallet.topup.submit'), [
                'payment_method_id' => $method->id,
                'amount' => 300000,
            ]);

        $response->assertRedirect(route('dashboard.wallet.topup'));

        $tx = PaymentTransaction::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $tx->status);
        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());
    }

    // ── Top-up callback: the credit itself ─────────────────────────────────

    public function test_a_verified_topup_credits_the_wallet_exactly_once(): void
    {
        $user = $this->makeUser(50000);
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($tx, $user)->assertRedirect(route('dashboard.wallet'));

        $this->assertSame(300000, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $tx->fresh()->status);
    }

    public function test_replaying_the_topup_callback_does_not_credit_twice(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        // A user refreshing the return page, or the gateway retrying, produces
        // exactly this: the same URL hit several times.
        for ($i = 0; $i < 5; $i++) {
            $this->hitCallback($tx, $user)->assertRedirect(route('dashboard.wallet'));
        }

        $this->assertSame(250000, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
    }

    /**
     * The replay test above passes even if `WalletService`'s own guard is
     * removed, because the controller's "already approved" early return fires
     * first — so on its own it proves only the OUTER layer. This one calls the
     * service directly, past that early return, and pins the inner layer that
     * has to hold when the controller's does not (admin re-verify, a queued
     * retry, a second process).
     */
    public function test_the_wallet_credit_is_idempotent_at_the_service_layer_too(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $wallet = app(WalletService::class);
        $first = $wallet->creditFromPaymentTransaction($user, $tx);
        $second = $wallet->creditFromPaymentTransaction($user, $tx);

        $this->assertSame($first->id, $second->id, 'the second call must return the SAME credit, not a new one');
        $this->assertSame(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(250000, (int) $user->fresh()->wallet_balance_toman);
    }

    /**
     * "Credited exactly once" survives even the service guard being removed,
     * because a UNIQUE constraint on `wallet_transactions.payment_transaction_id`
     * is the last line — the one layer no application race can talk its way
     * past. Pin it directly, so dropping it in a future migration fails here
     * rather than silently reducing three layers of protection to two.
     */
    public function test_the_database_refuses_a_second_credit_for_the_same_transaction(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        app(WalletService::class)->creditFromPaymentTransaction($user, $tx);

        $this->expectException(QueryException::class);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_TOPUP,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman' => 250000,
            'balance_before_toman' => 250000,
            'balance_after_toman' => 500000,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'payment_transaction_id' => $tx->id,
        ]);
    }

    public function test_the_credit_goes_to_the_transaction_owner_not_the_browsing_user(): void
    {
        // The return URL is public and carries only a transaction id. Whoever's
        // session hits it must be irrelevant to WHERE the money lands.
        $victim = $this->makeUser();
        $attacker = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($victim, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $victim->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($tx, $attacker);

        $this->assertSame(250000, (int) $victim->fresh()->wallet_balance_toman);
        $this->assertSame(0, (int) $attacker->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::where('user_id', $attacker->id)->count());
    }

    // ── Top-up callback: every way it must refuse ──────────────────────────

    public function test_a_topup_amount_mismatch_credits_nothing(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        // The gateway settled a SMALLER amount than the user asked to top up.
        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 1000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($tx, $user);

        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::where('payment_transaction_id', $tx->id)->count());

        $fresh = $tx->fresh();
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $fresh->status);
        $this->assertSame('amount_mismatch', $fresh->gateway_status);
    }

    public function test_a_topup_user_mismatch_credits_nothing(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $other->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($tx, $user);

        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, (int) $other->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame('user_mismatch', $tx->fresh()->gateway_status);
    }

    public function test_an_unsuccessful_verification_credits_nothing(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify(['message' => 'payment cancelled by user'], success: false);

        $this->hitCallback($tx, $user);

        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $tx->fresh()->status);
    }

    public function test_a_verification_transport_failure_credits_nothing(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        Http::fake(['*' => Http::response('gateway exploded', 500)]);

        $this->hitCallback($tx, $user)->assertRedirect(route('dashboard.wallet'));

        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::count());

        // A transport failure is NOT a settled failure: the transaction must
        // stay open so the admin re-verify can still resolve it.
        $this->assertSame(PaymentTransaction::STATUS_WAITING, $tx->fresh()->status);
    }

    public function test_a_credited_topup_cannot_be_downgraded_by_a_later_failed_callback(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);
        $this->hitCallback($tx, $user);
        $this->assertSame(250000, (int) $user->fresh()->wallet_balance_toman);

        // Now the gateway starts answering "failed" — a late retry, a changed
        // upstream state, or an attacker replaying the URL. The already-settled
        // credit must not be reversed or re-marked.
        $this->fakeVerify(['message' => 'expired'], success: false);
        $this->hitCallback($tx, $user);

        $this->assertSame(250000, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $tx->fresh()->status);
        $this->assertSame(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
    }

    // ── Purpose routing ────────────────────────────────────────────────────

    public function test_an_order_payment_is_never_settled_as_a_wallet_credit(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeOrderTx($user, $method, 200000);

        $this->fakeVerify([
            'referenceId' => 55,
            'amount' => 200000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($tx, $user);

        $this->assertSame(Order::PAYMENT_PAID, $tx->fresh()->order->payment_status);
        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman, 'an order payment must never top up the wallet');
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_wallet_topup_never_marks_an_order_paid(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();

        // An unpaid order exists for the same user at the same moment.
        $orderTx = $this->makeOrderTx($user, $method, 250000);
        $order = $orderTx->order;

        $topup = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($topup, $user);

        $this->assertSame(250000, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(Order::PAYMENT_UNPAID, $order->fresh()->payment_status);
    }

    // ── Admin re-verify ────────────────────────────────────────────────────

    public function test_admin_reverify_of_a_topup_credits_exactly_once_however_often_it_runs(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        // A support agent clicking "check status" repeatedly, possibly after the
        // browser callback already settled it.
        $this->hitCallback($tx, $user);
        CentralPayController::adminVerify($tx->fresh(), app(MarkOrderAsPaidService::class));
        CentralPayController::adminVerify($tx->fresh(), app(MarkOrderAsPaidService::class));

        $this->assertSame(250000, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
    }

    public function test_admin_reverify_refuses_a_topup_whose_amount_disagrees(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 1000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            CentralPayController::adminVerify($tx, app(MarkOrderAsPaidService::class));
        } finally {
            $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
            $this->assertSame(0, WalletTransaction::count());
        }
    }

    public function test_admin_reverify_refuses_a_topup_belonging_to_another_user(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $other->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            CentralPayController::adminVerify($tx, app(MarkOrderAsPaidService::class));
        } finally {
            $this->assertSame(0, WalletTransaction::count());
            $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
            $this->assertSame(0, (int) $other->fresh()->wallet_balance_toman);
        }
    }

    // ── What the user is allowed to learn ──────────────────────────────────

    public function test_the_card_number_is_masked_and_the_full_pan_is_never_stored(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'referenceId' => 998877,
            'amount' => 250000,
            'userId' => $user->id,
            'userCardNumber' => '6037991122334455',
        ]);

        $this->hitCallback($tx, $user);

        $fresh = $tx->fresh();
        $this->assertSame('603799******4455', $fresh->response_payload['masked_card_number'] ?? null);

        // The raw PAN may legitimately sit in the archived gateway reply, but it
        // must never be what the application itself persists as the card.
        $this->assertNotSame('6037991122334455', $fresh->response_payload['masked_card_number'] ?? null);
    }

    public function test_the_gateway_error_text_is_never_echoed_to_the_user(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod(['api_key' => 'super-secret-key-value']);
        $tx = $this->makeTopupTx($user, $method, 250000);

        $this->fakeVerify([
            'message' => 'SQLSTATE[42000] at https://internal.centralapi/db with key super-secret-key-value',
        ], success: false);

        $response = $this->hitCallback($tx, $user);
        $response->assertRedirect(route('dashboard.wallet'));

        $error = (string) session('error');
        $this->assertNotSame('', $error);
        $this->assertStringNotContainsString('super-secret-key-value', $error);
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringNotContainsString('internal.centralapi', $error);
    }

    public function test_an_unknown_transaction_id_settles_nothing_and_says_nothing(): void
    {
        $user = $this->makeUser();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['amount' => 1]], 200)]);

        $this->actingAs($user)
            ->get(route('payments.centralpay.callback', ['orderId' => 999999]))
            ->assertRedirect(route('dashboard.orders'));

        Http::assertNothingSent();
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_missing_transaction_id_settles_nothing(): void
    {
        $user = $this->makeUser();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['amount' => 1]], 200)]);

        $this->actingAs($user)
            ->get(route('payments.centralpay.callback'))
            ->assertRedirect(route('dashboard.orders'));

        Http::assertNothingSent();
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_non_centralpay_transaction_id_is_not_settled_by_this_callback(): void
    {
        $user = $this->makeUser();
        $method = $this->makeCentralPayMethod();
        $tx = $this->makeTopupTx($user, $method, 250000, ['provider' => 'nowpayments']);

        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'amount' => 250000, 'userId' => $user->id, 'referenceId' => 1,
        ]], 200)]);

        $this->hitCallback($tx, $user);

        Http::assertNothingSent();
        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
        $this->assertSame(0, WalletTransaction::count());
    }
}
