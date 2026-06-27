<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'wallet_balance_toman' => 0,
        ], $attrs));
    }

    private function makePlan(int $price = 50000): Plan
    {
        return Plan::factory()->create([
            'name'        => 'Test Plan',
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

    private function makeWalletPaymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'title'     => 'کیف پول',
            'slug'      => 'wallet',
            'type'      => PaymentMethod::TYPE_WALLET,
            'is_active' => true,
        ]);
    }

    private function makeManualMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'title'     => 'پرداخت دستی ارز دیجیتال',
            'slug'      => 'manual-crypto',
            'type'      => PaymentMethod::TYPE_MANUAL_CRYPTO,
            'is_active' => true,
        ]);
    }

    // ── Wallet page ───────────────────────────────────────────────────────────

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

    // ── Payment page ──────────────────────────────────────────────────────────

    public function test_guest_cannot_access_payment_page(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $this->get(route('dashboard.orders.pay', $order))
            ->assertRedirect(route('login'));
    }

    public function test_user_cannot_access_another_users_payment_page(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser(['username' => 'other_user', 'email' => 'other@example.com']);
        $plan  = $this->makePlan();
        $order = $this->makeOrder($owner, $plan);

        $this->actingAs($other)
            ->get(route('dashboard.orders.pay', $order))
            ->assertForbidden();
    }

    // ── Manual payment ────────────────────────────────────────────────────────

    public function test_manual_payment_creates_submitted_payment_transaction(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeManualMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id'     => $method->id,
                'transaction_reference' => 'TXID-ABC123',
                'user_note'             => 'پرداخت شد',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_transactions', [
            'order_id'              => $order->id,
            'user_id'               => $user->id,
            'payment_method_id'     => $method->id,
            'status'                => PaymentTransaction::STATUS_SUBMITTED,
            'transaction_reference' => 'TXID-ABC123',
        ]);
    }

    public function test_manual_payment_does_not_auto_approve_order(): void
    {
        $user   = $this->makeUser();
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $method = $this->makeManualMethod();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ]);

        $order->refresh();
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $order->status);
    }

    // ── Wallet payment ────────────────────────────────────────────────────────

    public function test_wallet_payment_succeeds_when_balance_sufficient(): void
    {
        $user   = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $this->makeWalletPaymentMethod();
        $method = PaymentMethod::where('type', 'wallet')->first();

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

    public function test_wallet_payment_fails_when_balance_insufficient(): void
    {
        $user   = $this->makeUser(['wallet_balance_toman' => 10000]);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
        $this->makeWalletPaymentMethod();
        $method = PaymentMethod::where('type', 'wallet')->first();

        $this->actingAs($user)
            ->post(route('dashboard.orders.pay.submit', $order), [
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotEquals(Order::PAYMENT_PAID, $order->payment_status);

        $user->refresh();
        $this->assertEquals(10000, $user->wallet_balance_toman);
    }

    public function test_wallet_debit_creates_wallet_transaction_record(): void
    {
        $user  = $this->makeUser(['wallet_balance_toman' => 100000]);
        $plan  = $this->makePlan(50000);
        $order = $this->makeOrder($user, $plan);
        $this->makeWalletPaymentMethod();

        app(PaymentService::class)->payWithWallet($order, $user);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $user->id,
            'order_id'  => $order->id,
            'type'      => WalletTransaction::TYPE_ORDER_PAYMENT,
            'direction' => WalletTransaction::DIRECTION_DEBIT,
            'amount_toman' => 50000,
        ]);
    }

    // ── Admin approval ────────────────────────────────────────────────────────

    public function test_admin_approval_marks_order_as_paid(): void
    {
        $admin  = $this->makeUser(['username' => 'admin_user', 'email' => 'admin@example.com', 'is_admin' => true]);
        $user   = $this->makeUser(['username' => 'customer', 'email' => 'customer@example.com']);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
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

        app(PaymentService::class)->approveTransaction($tx, $admin->id, 'تایید شد');

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertEquals(Order::STATUS_PAID, $order->status);

        $tx->refresh();
        $this->assertEquals(PaymentTransaction::STATUS_APPROVED, $tx->status);
        $this->assertEquals($admin->id, $tx->reviewed_by);
    }

    public function test_double_approval_is_idempotent(): void
    {
        $admin  = $this->makeUser(['username' => 'admin2', 'email' => 'admin2@example.com', 'is_admin' => true]);
        $user   = $this->makeUser(['username' => 'customer2', 'email' => 'customer2@example.com']);
        $plan   = $this->makePlan(50000);
        $order  = $this->makeOrder($user, $plan);
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

        $service = app(PaymentService::class);
        $service->approveTransaction($tx, $admin->id);
        $service->approveTransaction($tx, $admin->id); // second call must not throw

        $this->assertEquals(PaymentTransaction::STATUS_APPROVED, $tx->fresh()->status);
    }

    // ── Admin wallet management ───────────────────────────────────────────────

    public function test_admin_wallet_credit_creates_wallet_transaction(): void
    {
        $user = $this->makeUser(['username' => 'creditme', 'email' => 'creditme@example.com']);

        app(WalletService::class)->credit($user, 100000, WalletTransaction::TYPE_MANUAL_CREDIT, [
            'description' => 'شارژ دستی توسط ادمین',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'      => $user->id,
            'type'         => WalletTransaction::TYPE_MANUAL_CREDIT,
            'direction'    => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman' => 100000,
        ]);

        $user->refresh();
        $this->assertEquals(100000, $user->wallet_balance_toman);
    }
}
