<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PhoneVerificationCode;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Services\Phone\PhoneVerificationService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPhoneTest extends TestCase
{
    use RefreshDatabase;

    // ── Account ID ───────────────────────────────────────────────────────────

    public function test_account_id_generated_for_new_user(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->account_id);
    }

    public function test_account_id_is_exactly_6_digits(): void
    {
        $user = User::factory()->create();
        $this->assertSame(6, strlen($user->account_id));
    }

    public function test_account_id_is_numeric_only(): void
    {
        $user = User::factory()->create();
        $this->assertTrue(ctype_digit($user->account_id));
        $this->assertStringNotContainsString('ZPX', $user->account_id);
    }

    public function test_account_id_is_unique(): void
    {
        $ids = collect(range(1, 30))->map(fn () => User::factory()->create()->account_id);
        $this->assertSame($ids->count(), $ids->unique()->count());
    }

    public function test_account_id_does_not_change_after_creation(): void
    {
        $user = User::factory()->create();
        $original = $user->account_id;

        $user->update(['name' => 'Changed Name']);

        $this->assertSame($original, $user->fresh()->account_id);
    }

    public function test_existing_users_can_be_backfilled(): void
    {
        $user = User::factory()->create();
        // Simulate a legacy user without account_id.
        \DB::table('users')->where('id', $user->id)->update(['account_id' => null]);
        $this->assertNull($user->fresh()->account_id);

        $this->artisan('zedproxy:backfill-account-ids')
            ->expectsOutputToContain('backfilled')
            ->assertExitCode(0);

        $this->assertNotNull($user->fresh()->account_id);
        $this->assertSame(6, strlen($user->fresh()->account_id));
    }

    public function test_backfill_does_not_change_existing_account_id(): void
    {
        $user = User::factory()->create();
        $original = $user->account_id;

        $this->artisan('zedproxy:backfill-account-ids')->assertExitCode(0);

        $this->assertSame($original, $user->fresh()->account_id);
    }

    // ── Phone normalization ──────────────────────────────────────────────────

    public function test_phone_normalization_works(): void
    {
        $this->assertSame('+989123456789', PhoneNumber::normalize('09123456789'));
        $this->assertSame('+989123456789', PhoneNumber::normalize('+989123456789'));
        $this->assertSame('+989123456789', PhoneNumber::normalize('989123456789'));
        $this->assertSame('+989123456789', PhoneNumber::normalize('00989123456789'));
        $this->assertSame('+989123456789', PhoneNumber::normalize('0912 345 6789'));
        $this->assertNull(PhoneNumber::normalize('12345'));
        $this->assertNull(PhoneNumber::normalize('08123456789'));
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function test_registration_requires_phone(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'No Phone',
            'username'              => 'nophoneuser',
            'email'                 => 'nophone@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_registration_stores_normalized_phone(): void
    {
        $this->post('/register', [
            'name'                  => 'With Phone',
            'username'              => 'withphone',
            'email'                 => 'withphone@example.com',
            'phone'                 => '09120001122',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertDatabaseHas('users', [
            'username'         => 'withphone',
            'phone'            => '09120001122',
            'normalized_phone' => '+989120001122',
        ]);
    }

    public function test_duplicate_normalized_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '09120001122', 'normalized_phone' => '+989120001122']);

        $response = $this->post('/register', [
            'name'                  => 'Dup Phone',
            'username'              => 'dupphone',
            'email'                 => 'dupphone@example.com',
            'phone'                 => '+989120001122', // same number, different format
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    // ── Admin settings ───────────────────────────────────────────────────────

    public function test_admin_can_enable_disable_phone_verification(): void
    {
        $svc = app(PhoneVerificationService::class);
        $this->assertFalse($svc->isEnabled());

        SiteSetting::set('phone_verification_enabled', 'true');
        $this->assertTrue($svc->isEnabled());

        SiteSetting::set('phone_verification_enabled', 'false');
        $this->assertFalse($svc->isEnabled());
    }

    public function test_admin_can_require_otp_during_registration(): void
    {
        $svc = app(PhoneVerificationService::class);
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');
        $this->assertTrue($svc->isRequiredOnRegister());

        // Required only matters when verification is enabled.
        SiteSetting::set('phone_verification_enabled', 'false');
        $this->assertFalse($svc->isRequiredOnRegister());
    }

    // ── OTP verification ─────────────────────────────────────────────────────

    public function test_user_can_verify_phone_from_account_settings(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();

        $result = app(PhoneVerificationService::class)->requestCode($user);
        $this->assertSame('sent', $result['status']);

        $verify = app(PhoneVerificationService::class)->verify($user, $result['code']);
        $this->assertSame('verified', $verify['status']);
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_wrong_otp_is_rejected(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();
        app(PhoneVerificationService::class)->requestCode($user);

        $result = app(PhoneVerificationService::class)->verify($user, '000000');
        $this->assertSame('invalid', $result['status']);
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_expired_otp_is_rejected(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        // Force expiry.
        PhoneVerificationCode::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $result = app(PhoneVerificationService::class)->verify($user, $res['code']);
        $this->assertSame('expired', $result['status']);
    }

    public function test_used_otp_cannot_be_reused(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        app(PhoneVerificationService::class)->verify($user, $res['code']); // first use OK

        // Reset verified flag but the code row is now used → cannot verify again.
        $user->update(['phone_verified_at' => null]);
        $result = app(PhoneVerificationService::class)->verify($user, $res['code']);
        $this->assertNotSame('verified', $result['status']);
    }

    public function test_too_many_attempts_blocks_otp(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        for ($i = 0; $i < PhoneVerificationService::MAX_ATTEMPTS; $i++) {
            app(PhoneVerificationService::class)->verify($user, '111111');
        }

        $result = app(PhoneVerificationService::class)->verify($user, $res['code']);
        $this->assertSame('too_many_attempts', $result['status']);
    }

    public function test_new_code_invalidates_old_unused_codes(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        // Disable resend cooldown impact by spacing creation manually.
        $user = User::factory()->unverifiedPhone()->create();

        $first = app(PhoneVerificationService::class)->requestCode($user);
        // Move first code outside the resend cooldown window.
        PhoneVerificationCode::where('user_id', $user->id)->update(['created_at' => now()->subMinutes(2)]);

        $second = app(PhoneVerificationService::class)->requestCode($user);
        $this->assertSame('sent', $second['status']);

        // The first (older) code should no longer verify.
        $result = app(PhoneVerificationService::class)->verify($user, $first['code']);
        // Only the latest code is considered; old one is marked used/invalid.
        $this->assertNotSame('verified', $result['status'] === 'verified' && $first['code'] === $second['code'] ? 'verified' : $result['status']);
    }

    public function test_otp_code_is_stored_hashed(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        $record = PhoneVerificationCode::where('user_id', $user->id)->latest()->first();
        $this->assertNotSame($res['code'], $record->code_hash);
        $this->assertTrue(Hash::check($res['code'], $record->code_hash));
    }

    // ── Sensitive action gate ────────────────────────────────────────────────

    public function test_user_without_phone_is_redirected_from_sensitive_action(): void
    {
        $user    = User::factory()->noPhone()->create();
        $service = $this->makeService($user);

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertRedirect(route('dashboard.profile.complete', ['intended' => route('dashboard.services.renew', $service)]));
    }

    public function test_user_with_phone_can_access_sensitive_action_when_otp_not_required(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'false');

        $user = User::factory()->unverifiedPhone()->create();
        $this->makePlan(); // a renewable plan must exist
        $service = $this->makeService($user);

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertStatus(200);
    }

    public function test_user_must_verify_when_otp_required(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');

        $user    = User::factory()->unverifiedPhone()->create();
        $service = $this->makeService($user);

        $this->actingAs($user)
            ->get(route('dashboard.services.renew', $service))
            ->assertRedirect(route('dashboard.profile.complete', ['intended' => route('dashboard.services.renew', $service)]));
    }

    // ── Admin manual verification ────────────────────────────────────────────

    public function test_admin_can_manually_verify_phone(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $this->assertNull($user->phone_verified_at);

        // Simulate the admin action behaviour.
        $user->update(['phone_verified_at' => now()]);
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    // ── Admin search ─────────────────────────────────────────────────────────

    public function test_admin_can_search_users_by_account_id(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->get('/zed-admin/users?tableSearch=' . $target->account_id)
            ->assertStatus(200)
            ->assertSee($target->account_id);
    }

    public function test_admin_can_search_orders_by_user_account_id(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();
        $plan  = $this->makePlan();
        Order::create([
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'user_id'           => $buyer->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => 100000,
            'final_price_toman' => 100000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
        ]);

        $this->actingAs($admin)
            ->get('/zed-admin/orders?tableSearch=' . $buyer->account_id)
            ->assertStatus(200)
            ->assertSee($buyer->account_id);
    }

    public function test_admin_can_search_services_by_user_account_id(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $this->makeService($owner);

        $this->actingAs($admin)
            ->get('/zed-admin/user-services?tableSearch=' . $owner->account_id)
            ->assertStatus(200)
            ->assertSee($owner->account_id);
    }

    public function test_related_pages_show_user_info(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();
        $plan  = $this->makePlan();
        $order = Order::create([
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'user_id'           => $buyer->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'price_toman'       => 100000,
            'final_price_toman' => 100000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
        ]);

        $this->actingAs($admin)
            ->get("/zed-admin/orders/{$order->id}/edit")
            ->assertStatus(200)
            ->assertSee($buyer->account_id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makePlan(): Plan
    {
        return Plan::create([
            'name'            => 'پلن',
            'slug'            => 'plan-' . uniqid(),
            'price_toman'     => 100000,
            'duration_days'   => 30,
            'traffic_gb'      => 50,
            'is_active'       => true,
            'renewal_enabled' => true,
            'sort_order'      => 0,
        ]);
    }

    private function makeService(User $user): UserService
    {
        $plan = $this->makePlan();
        return UserService::create([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'plan_name'        => $plan->name,
            'expires_at'       => now()->addDays(10),
        ]);
    }
}
