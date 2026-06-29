<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests that the PaymentTransactionResource admin page does not crash when:
 * - order is null (wallet top-up transactions)
 * - user is null
 * - provider/status/payment_purpose is null or unknown
 */
class PaymentTransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'username'          => 'ptx_admin',
            'password'          => bcrypt('secret'),
            'is_admin'          => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Task 1 & 2: Null order / null user safety ─────────────────────────────

    private function makeUser(string $suffix = ''): User
    {
        return User::factory()->create([
            'username'          => "ptx_user{$suffix}",
            'is_admin'          => false,
            'email_verified_at' => now(),
        ]);
    }

    public function test_payment_transactions_index_renders_when_order_is_null(): void
    {
        // Wallet top-up transactions have a user but no order_id
        $user = $this->makeUser('1');
        PaymentTransaction::create([
            'order_id'        => null,
            'user_id'         => $user->id,
            'provider'        => 'nowpayments',
            'payment_purpose' => 'wallet_topup',
            'status'          => PaymentTransaction::STATUS_PENDING,
            'amount_toman'    => 100000,
        ]);

        $this->actingAs($this->makeAdmin())
            ->get('/zed-admin/payment-transactions')
            ->assertOk();
    }

    public function test_payment_transactions_index_renders_with_unknown_status_and_provider(): void
    {
        // Old records may have an unexpected status value or null provider.
        // The badge formatStateUsing closure must not crash on unknown values.
        $user = $this->makeUser('2');
        PaymentTransaction::create([
            'order_id'     => null,
            'user_id'      => $user->id,
            'provider'     => null,
            'status'       => 'unknown_legacy_status',
            'amount_toman' => 0,
        ]);

        $this->actingAs($this->makeAdmin())
            ->get('/zed-admin/payment-transactions')
            ->assertOk();
    }

    public function test_centralpay_action_hidden_when_order_is_null(): void
    {
        // A CentralPay transaction for wallet top-up has no order.
        // The centralpay_check visibility callback must not crash and must hide the action.
        $user = $this->makeUser('3');
        PaymentTransaction::create([
            'order_id'        => null,
            'user_id'         => $user->id,
            'provider'        => 'centralpay',
            'payment_purpose' => 'wallet_topup',
            'status'          => PaymentTransaction::STATUS_PENDING,
            'gateway_status'  => null,
            'amount_toman'    => 200000,
        ]);

        // Page must render without 500 — null order no longer crashes the visibility callback
        $this->actingAs($this->makeAdmin())
            ->get('/zed-admin/payment-transactions')
            ->assertOk();
    }

    // ── Task 3: Discount code create ─────────────────────────────────────────

    public function test_discount_code_create_page_renders(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/zed-admin/discount-codes/create')
            ->assertOk();
    }

    public function test_discount_code_can_be_created_via_admin(): void
    {
        DiscountCode::create([
            'title'                => 'تست ادمین',
            'code'                 => 'ADMINTEST',
            'type'                 => DiscountCode::TYPE_FIXED,
            'value'                => 50000,
            'is_active'            => true,
            'per_user_usage_limit' => 2,
        ]);

        $this->assertDatabaseHas('discount_codes', [
            'code'  => 'ADMINTEST',
            'value' => 50000,
        ]);
    }

    // ── Task 4: Navigation groups ─────────────────────────────────────────────

    public function test_payment_transaction_resource_uses_mali_navigation_group(): void
    {
        $group = \App\Filament\Resources\PaymentTransactionResource::getNavigationGroup();
        $this->assertEquals('مالی', $group);
    }

    public function test_wallet_transaction_resource_uses_mali_navigation_group(): void
    {
        $group = \App\Filament\Resources\WalletTransactionResource::getNavigationGroup();
        $this->assertEquals('مالی', $group);
    }

    public function test_payment_method_resource_uses_mali_navigation_group(): void
    {
        $group = \App\Filament\Resources\PaymentMethodResource::getNavigationGroup();
        $this->assertEquals('مالی', $group);
    }

    public function test_discount_code_resource_uses_mali_navigation_group(): void
    {
        $group = \App\Filament\Resources\DiscountCodeResource::getNavigationGroup();
        $this->assertEquals('مالی', $group);
    }

    public function test_financial_report_page_uses_mali_navigation_group(): void
    {
        $group = \App\Filament\Pages\FinancialReport::getNavigationGroup();
        $this->assertEquals('مالی', $group);
    }

    public function test_wallet_settings_page_uses_mali_navigation_group(): void
    {
        $group = \App\Filament\Pages\WalletSettings::getNavigationGroup();
        $this->assertEquals('مالی', $group);
    }

    // ── Navigation sort order ─────────────────────────────────────────────────

    public function test_mali_navigation_sort_order_is_correct(): void
    {
        $this->assertEquals(10, \App\Filament\Pages\FinancialReport::getNavigationSort());
        $this->assertEquals(20, \App\Filament\Resources\PaymentTransactionResource::getNavigationSort());
        $this->assertEquals(30, \App\Filament\Resources\WalletTransactionResource::getNavigationSort());
        $this->assertEquals(40, \App\Filament\Pages\WalletSettings::getNavigationSort());
        $this->assertEquals(50, \App\Filament\Resources\PaymentMethodResource::getNavigationSort());
        $this->assertEquals(60, \App\Filament\Resources\DiscountCodeResource::getNavigationSort());
    }
}
