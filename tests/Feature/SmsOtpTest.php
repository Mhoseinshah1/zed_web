<?php

namespace Tests\Feature;

use App\Models\PhoneVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Phone\PhoneVerificationService;
use App\Services\Sms\SmsService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsOtpTest extends TestCase
{
    use RefreshDatabase;

    private function configureSms(string $provider = 'kavenegar'): void
    {
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', $provider);
        SmsService::storeApiKey('secret-api-key');
    }

    // ── Settings page ────────────────────────────────────────────────────────

    public function test_sms_settings_page_exists(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/zed-admin/settings/sms')
            ->assertStatus(200)
            ->assertSee('تنظیمات پیامک');
    }

    public function test_admin_can_enable_and_disable_sms(): void
    {
        $svc = app(SmsService::class);
        $this->assertFalse($svc->isEnabled());

        SiteSetting::set('sms_enabled', 'true');
        $this->assertTrue($svc->isEnabled());

        SiteSetting::set('sms_enabled', 'false');
        $this->assertFalse($svc->isEnabled());
    }

    public function test_sms_is_not_configured_without_api_key(): void
    {
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'kavenegar');
        // No API key set.
        $this->assertFalse(app(SmsService::class)->isConfigured());
    }

    public function test_api_key_is_stored_encrypted(): void
    {
        SmsService::storeApiKey('my-secret-key');

        $raw = SiteSetting::get('sms_api_key');
        $this->assertNotSame('my-secret-key', $raw);
        $this->assertSame('my-secret-key', Crypt::decryptString($raw));
        $this->assertSame('my-secret-key', app(SmsService::class)->apiKey());
    }

    public function test_admin_cannot_require_otp_on_registration_when_sms_disabled(): void
    {
        // SMS disabled → required-on-register must not take effect.
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');
        SiteSetting::set('sms_enabled', 'false');

        $this->assertFalse(app(PhoneVerificationService::class)->isRequiredOnRegister());
    }

    // ── Phone normalization ──────────────────────────────────────────────────

    public function test_phone_normalization_works(): void
    {
        $svc = app(PhoneVerificationService::class);
        $this->assertSame('+989121112233', $svc->normalizePhone('09121112233'));
        $this->assertSame('+989121112233', $svc->normalizePhone('00989121112233'));
        $this->assertNull($svc->normalizePhone('not-a-phone'));
    }

    // ── OTP generation / storage ─────────────────────────────────────────────

    public function test_otp_code_is_six_digits_and_hashed(): void
    {
        Http::fake();
        $this->configureSms();
        $user = User::factory()->unverifiedPhone()->create();

        $result = app(PhoneVerificationService::class)->requestCode($user);

        $this->assertSame('sent', $result['status']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['code']);

        $record = PhoneVerificationCode::where('user_id', $user->id)->latest()->first();
        $this->assertNotSame($result['code'], $record->code_hash);
        $this->assertTrue(Hash::check($result['code'], $record->code_hash));
    }

    public function test_otp_send_status_recorded_when_sms_configured(): void
    {
        Http::fake(); // any 200 response → provider returns true
        $this->configureSms();
        $user = User::factory()->unverifiedPhone()->create();

        app(PhoneVerificationService::class)->requestCode($user);

        $record = PhoneVerificationCode::where('user_id', $user->id)->latest()->first();
        $this->assertSame(PhoneVerificationCode::SEND_STATUS_SENT, $record->send_status);
    }

    public function test_otp_send_status_skipped_when_sms_disabled(): void
    {
        $user = User::factory()->unverifiedPhone()->create();

        app(PhoneVerificationService::class)->requestCode($user);

        $record = PhoneVerificationCode::where('user_id', $user->id)->latest()->first();
        $this->assertSame(PhoneVerificationCode::SEND_STATUS_SKIPPED, $record->send_status);
    }

    // ── OTP rules use admin settings ─────────────────────────────────────────

    public function test_otp_expires_using_admin_ttl(): void
    {
        SiteSetting::set('otp_ttl_minutes', 10);
        $user = User::factory()->unverifiedPhone()->create();

        app(PhoneVerificationService::class)->requestCode($user);

        $record = PhoneVerificationCode::where('user_id', $user->id)->latest()->first();
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $record->expires_at->timestamp, 5);
    }

    public function test_wrong_otp_fails(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        app(PhoneVerificationService::class)->requestCode($user);

        $this->assertFalse(app(PhoneVerificationService::class)->verifyCode($user, '000000'));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_expired_otp_fails(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);
        PhoneVerificationCode::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse(app(PhoneVerificationService::class)->verifyCode($user, $res['code']));
    }

    public function test_used_otp_cannot_be_reused(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        $this->assertTrue(app(PhoneVerificationService::class)->verifyCode($user, $res['code']));

        $user->update(['phone_verified_at' => null]);
        $this->assertFalse(app(PhoneVerificationService::class)->verifyCode($user, $res['code']));
    }

    public function test_max_attempts_enforced_from_settings(): void
    {
        SiteSetting::set('otp_max_attempts', 3);
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        for ($i = 0; $i < 3; $i++) {
            app(PhoneVerificationService::class)->verify($user, '111111');
        }

        $result = app(PhoneVerificationService::class)->verify($user, $res['code']);
        $this->assertSame('too_many_attempts', $result['status']);
    }

    public function test_resend_cooldown_enforced_from_settings(): void
    {
        SiteSetting::set('otp_resend_cooldown_seconds', 120);
        $user = User::factory()->unverifiedPhone()->create();

        $first = app(PhoneVerificationService::class)->requestCode($user);
        $this->assertSame('sent', $first['status']);

        $second = app(PhoneVerificationService::class)->requestCode($user);
        $this->assertSame('rate_limited', $second['status']);
        $this->assertFalse(app(PhoneVerificationService::class)->canResend($user->fresh()));
    }

    public function test_new_code_invalidates_old_unused_codes(): void
    {
        SiteSetting::set('otp_resend_cooldown_seconds', 60);
        $user = User::factory()->unverifiedPhone()->create();

        app(PhoneVerificationService::class)->requestCode($user);
        // Age the first code beyond the resend cooldown window.
        PhoneVerificationCode::where('user_id', $user->id)->update(['created_at' => now()->subMinutes(2)]);

        $second = app(PhoneVerificationService::class)->requestCode($user); // invalidates first
        $this->assertSame('sent', $second['status']);

        // The first code's row is now marked used → only the newest is verifiable.
        $oldRow = PhoneVerificationCode::where('user_id', $user->id)->orderBy('id')->first();
        $this->assertNotNull($oldRow->used_at);
    }

    // ── Verify from account settings ─────────────────────────────────────────

    public function test_user_can_verify_phone_from_account_settings(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $res  = app(PhoneVerificationService::class)->requestCode($user);

        $this->actingAs($user)
            ->post(route('dashboard.profile.phone.verify'), ['code' => $res['code']])
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_account_settings_shows_phone_section(): void
    {
        SiteSetting::set('phone_verification_enabled', 'true');
        $user = User::factory()->unverifiedPhone()->create();

        $this->actingAs($user)
            ->get(route('dashboard.profile'))
            ->assertStatus(200)
            ->assertSee('تایید شماره موبایل')
            ->assertSee('ارسال کد تایید');
    }

    // ── Registration OTP behavior ────────────────────────────────────────────

    public function test_registration_without_otp_works_when_disabled(): void
    {
        SiteSetting::set('phone_verification_enabled', 'false');

        $this->post('/register', [
            'name'                  => 'Plain User',
            'username'              => 'plainuser',
            'email'                 => 'plain@example.com',
            'phone'                 => '09120009988',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('dashboard.index'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'plainuser', 'phone_verified_at' => null]);
    }

    public function test_registration_redirects_to_verification_when_required(): void
    {
        Http::fake();
        $this->configureSms();
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');

        $response = $this->post('/register', [
            'name'                  => 'Otp User',
            'username'              => 'otpuser',
            'email'                 => 'otp@example.com',
            'phone'                 => '09120007766',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard.profile.complete'));

        $user = User::where('username', 'otpuser')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->phone_verified_at);
        $this->assertDatabaseHas('phone_verification_codes', ['user_id' => $user->id]);
    }

    // ── Providers / test SMS ─────────────────────────────────────────────────

    public function test_test_sms_succeeds_with_faked_http(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->configureSms();

        $ok = app(SmsService::class)->sendTest('+989120001122', 'تست');
        $this->assertTrue($ok);
    }

    public function test_test_sms_throws_when_not_configured(): void
    {
        $this->expectException(\RuntimeException::class);
        app(SmsService::class)->sendTest('+989120001122', 'تست');
    }

    public function test_send_never_throws_on_provider_error(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);
        $this->configureSms();

        // sendOtp must swallow the failure and return false.
        $this->assertFalse(app(SmsService::class)->sendOtp('+989120001122', '123456'));
    }

    public function test_custom_provider_posts_to_configured_url(): void
    {
        Http::fake(['https://panel.example.com/*' => Http::response('ok', 200)]);
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'custom');
        SiteSetting::set('sms_custom_url', 'https://panel.example.com/send');
        SiteSetting::set('sms_custom_method', 'POST');
        SiteSetting::set('sms_custom_body_template', '{"to":"{phone}","text":"{message}","key":"{api_key}"}');
        SmsService::storeApiKey('ck');

        $ok = app(SmsService::class)->sendMessage('+989120001122', 'hello');
        $this->assertTrue($ok);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'panel.example.com'));
    }
}
