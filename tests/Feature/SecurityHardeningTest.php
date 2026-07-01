<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Locks in the four security fixes from the audit:
 *   1. wallet double-credit race (unique constraint + atomic, idempotent credit)
 *   2. order row locked in markPaid (covered also by ProvisioningTest)
 *   3. rate limiting on payment/OTP endpoints
 *   4. is_admin / wallet_balance_toman no longer mass-assignable
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // reset rate-limiter counters between tests
    }

    // ── Item 1: wallet double-credit ─────────────────────────────────────────

    private function walletTopupTx(User $user, int $amount = 50000): PaymentTransaction
    {
        return PaymentTransaction::create([
            'user_id'         => $user->id,
            'provider'        => 'nowpayments',
            'method'          => 'nowpayments',
            'payment_purpose' => 'wallet_topup',
            'status'          => PaymentTransaction::STATUS_WAITING,
            'amount_toman'    => $amount,
        ]);
    }

    public function test_payment_transaction_id_is_unique(): void
    {
        $user = User::factory()->create();
        $tx   = $this->walletTopupTx($user);

        app(WalletService::class)->creditFromPaymentTransaction($user, $tx);

        // A second row for the same payment transaction must be rejected by the DB.
        $this->expectException(QueryException::class);
        WalletTransaction::create([
            'user_id'                => $user->id,
            'type'                   => WalletTransaction::TYPE_TOPUP,
            'direction'              => WalletTransaction::DIRECTION_CREDIT,
            'amount_toman'           => 50000,
            'balance_before_toman'   => 0,
            'balance_after_toman'    => 50000,
            'status'                 => WalletTransaction::STATUS_COMPLETED,
            'payment_transaction_id' => $tx->id,
        ]);
    }

    public function test_multiple_null_payment_transaction_ids_are_allowed(): void
    {
        $user = User::factory()->create();

        // Manual/admin credits carry no payment_transaction_id — many NULLs must coexist.
        app(WalletService::class)->credit($user, 1000, WalletTransaction::TYPE_ADJUSTMENT);
        app(WalletService::class)->credit($user, 2000, WalletTransaction::TYPE_ADJUSTMENT);

        $this->assertSame(2, WalletTransaction::whereNull('payment_transaction_id')->count());
    }

    public function test_credit_from_payment_transaction_is_idempotent(): void
    {
        $user = User::factory()->create(['wallet_balance_toman' => 0]);
        $tx   = $this->walletTopupTx($user, 50000);

        $service = app(WalletService::class);
        $first  = $service->creditFromPaymentTransaction($user, $tx);
        $second = $service->creditFromPaymentTransaction($user, $tx); // duplicate → no 500

        $this->assertTrue($first->is($second));
        $this->assertSame(1, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(50000, (int) $user->fresh()->wallet_balance_toman); // credited once
    }

    public function test_nowpayments_ipn_does_not_recredit_an_approved_wallet_topup(): void
    {
        $user = User::factory()->create(['wallet_balance_toman' => 0]);

        PaymentMethod::create([
            'title' => 'NP', 'slug' => 'np', 'type' => PaymentMethod::TYPE_NOWPAYMENTS,
            'is_active' => true, 'sort_order' => 1,
            'api_key' => 'k', 'ipn_secret' => 'ipn-secret',
            'config' => ['sandbox' => true],
        ]);

        // Already processed top-up (status APPROVED).
        $tx = PaymentTransaction::create([
            'user_id'         => $user->id,
            'provider'        => 'nowpayments',
            'method'          => 'nowpayments',
            'payment_purpose' => 'wallet_topup',
            'status'          => PaymentTransaction::STATUS_APPROVED,
            'external_id'     => 'pay_approved_1',
            'amount_toman'    => 50000,
        ]);

        $payload = ['payment_id' => 'pay_approved_1', 'payment_status' => 'finished'];
        $sorted  = $payload;
        ksort($sorted);
        $signature = hash_hmac('sha512', json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'ipn-secret');

        $this->postJson('/webhooks/nowpayments', $payload, ['x-nowpayments-sig' => $signature])
            ->assertOk();

        // No wallet credit, balance untouched.
        $this->assertSame(0, WalletTransaction::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(0, (int) $user->fresh()->wallet_balance_toman);
    }

    // ── Item 3: rate limiting ────────────────────────────────────────────────

    public function test_otp_send_endpoint_is_throttled(): void
    {
        $user = User::factory()->create();

        // throttle:5,1 → the 6th request within the minute is blocked.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('dashboard.profile.phone.send-otp'));
        }
        $this->actingAs($user)->post(route('dashboard.profile.phone.send-otp'))->assertStatus(429);
    }

    public function test_payment_submit_endpoint_is_throttled(): void
    {
        $user  = User::factory()->create();
        $order = Order::create([
            'user_id'           => $user->id,
            'plan_name'         => 'Test',
            'price_toman'       => 10000,
            'final_price_toman' => 10000,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);

        // throttle:10,1 → the 11th request within the minute is blocked.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order));
        }
        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order))->assertStatus(429);
    }

    // ── Item 4: privileged fields not mass-assignable ────────────────────────

    public function test_normal_user_cannot_mass_assign_privileged_fields(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'wallet_balance_toman' => 0]);

        // Simulate a mass-assignment attempt (e.g. if request data were ever passed through).
        $user->update(['is_admin' => true, 'wallet_balance_toman' => 999999]);
        $user->refresh();

        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame(0, (int) $user->wallet_balance_toman);
    }

    public function test_registration_cannot_grant_admin(): void
    {
        $this->post('/register', [
            'name'                  => 'Attacker',
            'username'              => 'attacker1',
            'email'                 => 'attacker@example.com',
            'phone'                 => '09120000000',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'is_admin'              => '1',
            'wallet_balance_toman'  => '999999',
        ]);

        $user = User::where('username', 'attacker1')->first();
        if ($user) {
            $this->assertFalse((bool) $user->is_admin);
            $this->assertSame(0, (int) $user->wallet_balance_toman);
        } else {
            $this->assertTrue(true); // validation rejected the request — also acceptable
        }
    }

    public function test_create_admin_command_still_grants_admin(): void
    {
        $this->artisan('zedproxy:create-admin', [
            '--username' => 'rootadmin',
            '--email'    => 'root@example.com',
            '--name'     => 'Root',
            '--password' => 'secret1234',
        ])->assertExitCode(0);

        $this->assertTrue((bool) User::where('username', 'rootadmin')->first()->is_admin);
    }

    public function test_admin_panel_can_still_toggle_is_admin(): void
    {
        // Explicit usernames — the admin form validates username against
        // /^[a-zA-Z0-9_]+$/, and the factory's faker username can contain a dot.
        $admin  = User::factory()->create(['is_admin' => true, 'username' => 'admin_user']);
        $target = User::factory()->create(['is_admin' => false, 'username' => 'target_user']);

        // Grant admin via the edit page (exercises EditUser::handleRecordUpdate forceFill).
        Livewire::actingAs($admin)->test(EditUser::class, ['record' => $target->id])
            ->fillForm(['is_admin' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue((bool) $target->fresh()->is_admin);

        // …and revoke it again.
        Livewire::actingAs($admin)->test(EditUser::class, ['record' => $target->id])
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse((bool) $target->fresh()->is_admin);
    }
}
