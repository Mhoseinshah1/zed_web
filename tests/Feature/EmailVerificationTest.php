<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettingsPage;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\PhoneVerificationCode;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Services\Sms\SmsService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('email_verification_enabled', 'true');
        SiteSetting::set('email_verification_required_on_register', 'true');
        // Required mode demands a successful transport-test proof for the
        // CURRENT config; tests that mutate the mail config drift the
        // fingerprint and (intentionally) degrade required → optional.
        app(EmailVerificationService::class)->recordSuccessfulMailTest();
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'کاربر تست',
            'username' => 'testuser'.random_int(1000, 9999),
            'email' => 'newuser'.random_int(1000, 9999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    private function unverifiedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['email_verified_at' => null], $attrs));
    }

    /** Issue a code directly and return [record, plaintext]. */
    private function issueCode(User $user): array
    {
        Mail::fake();
        $result = app(EmailVerificationService::class)->requestCode($user);
        $this->assertSame('queued', $result['status']);
        $record = EmailVerificationCode::where('user_id', $user->id)->whereNull('used_at')->latest('id')->first();

        // Recover the plaintext by brute-forcing is impossible — instead craft
        // a known code by replacing the stored hash.
        $record->update(['code_hash' => Hash::make('123456')]);

        return [$record, '123456'];
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function test_registration_generates_exactly_one_code_and_redirects_to_verify(): void
    {
        Mail::fake();

        $response = $this->post('/register', $this->registrationPayload(['email' => 'reg@example.com']));

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();
        $user = User::where('email', 'reg@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at, 'new users start unverified');
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_code_is_stored_hashed_never_plaintext(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();

        app(EmailVerificationService::class)->requestCode($user);

        $record = EmailVerificationCode::first();
        $this->assertNotNull($record);
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $record->code_hash);
        $this->assertStringStartsWith('$', $record->code_hash, 'must be a Hash, not a code');
    }

    public function test_previous_code_is_invalidated_when_a_new_one_is_issued(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        $svc = app(EmailVerificationService::class);

        $svc->requestCode($user);
        $first = EmailVerificationCode::first();
        // Move past the cooldown, then request again.
        EmailVerificationCode::whereKey($first->id)->update(['created_at' => now()->subMinutes(5)]);
        $svc->requestCode($user);

        $this->assertNotNull($first->fresh()->used_at, 'old code must be invalidated');
        $this->assertSame(1, EmailVerificationCode::whereNull('used_at')->count());
    }

    // ── Verify ───────────────────────────────────────────────────────────────

    public function test_correct_code_verifies_and_sets_timestamp_once_with_event(): void
    {
        Event::fake([Verified::class]);
        $user = $this->unverifiedUser();
        [, $code] = $this->issueCode($user);

        $response = $this->actingAs($user)->post('/email/verify', ['code' => $code]);

        $response->assertRedirect(route('dashboard.index'));
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        Event::assertDispatchedTimes(Verified::class, 1);
        // Every code is consumed after success.
        $this->assertSame(0, EmailVerificationCode::whereNull('used_at')->count());
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = $this->unverifiedUser();
        $this->issueCode($user);

        $response = $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => '999999']);

        $response->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->unverifiedUser();
        [$record, $code] = $this->issueCode($user);
        $record->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_used_code_is_rejected(): void
    {
        $user = $this->unverifiedUser();
        [$record, $code] = $this->issueCode($user);
        $record->update(['used_at' => now()]);

        $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_maximum_attempts_enforced(): void
    {
        $user = $this->unverifiedUser();
        [, $code] = $this->issueCode($user);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => '000000']);
        }
        // Even the CORRECT code fails after max attempts.
        $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ── Resend limits ────────────────────────────────────────────────────────

    public function test_resend_cooldown_enforced(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        $svc = app(EmailVerificationService::class);

        $this->assertSame('queued', $svc->requestCode($user)['status']);
        $this->assertSame('rate_limited', $svc->requestCode($user)['status']);
    }

    public function test_daily_cap_enforced(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        $svc = app(EmailVerificationService::class);
        SiteSetting::set('email_otp_daily_cap', 3);

        for ($i = 0; $i < 3; $i++) {
            $result = $svc->requestCode($user);
            $this->assertSame('queued', $result['status'], "send {$i} within cap");
            EmailVerificationCode::query()->update(['created_at' => now()->subMinutes(10 * ($i + 1))]);
        }

        $this->assertSame('rate_limited', $svc->requestCode($user)['status']);
    }

    // ── Honest sending ───────────────────────────────────────────────────────

    public function test_registration_notification_and_email_sent_once(): void
    {
        Queue::fake();

        $this->post('/register', $this->registrationPayload(['email' => 'once@example.com']));

        Queue::assertPushed(SendEmailOtpJob::class, 1);
        $user = User::where('email', 'once@example.com')->first();
        $this->assertSame(1, User::where('email', 'once@example.com')->count());
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_mailer_log_is_not_configured_in_production(): void
    {
        config(['mail.default' => 'log']);
        $this->app->detectEnvironment(fn () => 'production');

        $svc = app(EmailVerificationService::class);
        $this->assertFalse($svc->isMailConfigured());
        $this->assertFalse($svc->isRequiredOnRegister(), 'required flag can never lock users out without working mail');

        // And an unverified user is NOT blocked while mail is unusable.
        $user = $this->unverifiedUser();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_send_failure_produces_an_honest_error(): void
    {
        $user = $this->unverifiedUser();

        // Force a queue dispatch failure.
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('redis down'));
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('redis down'));

        $result = app(EmailVerificationService::class)->requestCode($user);

        $this->assertSame('error', $result['status']);
        $this->assertFalse($result['email_sent']);
        $this->assertSame(
            EmailVerificationCode::SEND_STATUS_FAILED,
            EmailVerificationCode::first()->send_status,
        );
    }

    public function test_no_code_or_smtp_secret_in_application_logs(): void
    {
        $log = storage_path('logs/laravel.log');
        File::put($log, '');

        Mail::fake();
        $user = $this->unverifiedUser();
        app(EmailVerificationService::class)->requestCode($user);
        $record = EmailVerificationCode::first();
        $record->update(['code_hash' => Hash::make('654321')]);
        $this->actingAs($user)->post('/email/verify', ['code' => '654321']);

        $contents = File::exists($log) ? File::get($log) : '';
        $this->assertStringNotContainsString('654321', $contents, 'OTP must never be logged');
        $this->assertStringNotContainsString('MAIL_PASSWORD', $contents);
    }

    // ── Access control ───────────────────────────────────────────────────────

    public function test_unverified_required_user_cannot_access_protected_routes(): void
    {
        $user = $this->unverifiedUser();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/dashboard/orders')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/dashboard/wallet')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/dashboard/tickets')->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_required_user_cannot_buy(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'price_toman' => 10000]);
        $user = $this->unverifiedUser();

        $this->actingAs($user)->post("/plans/{$plan->id}/buy")
            ->assertRedirect(route('verification.notice'));
    }

    public function test_json_requests_receive_403(): void
    {
        $user = $this->unverifiedUser();

        $this->actingAs($user)->getJson('/dashboard')->assertStatus(403);
    }

    public function test_verification_routes_remain_accessible_and_get_never_sends(): void
    {
        Queue::fake();
        $user = $this->unverifiedUser();

        $this->actingAs($user)->get('/email/verify')->assertOk();
        $this->actingAs($user)->get('/email/verify')->assertOk();   // refresh
        Queue::assertNothingPushed();                               // GET never sends
    }

    public function test_verified_user_retains_normal_access(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        // Verified users bounce off the verify page back to the dashboard.
        $this->actingAs($user)->get('/email/verify')->assertRedirect(route('dashboard.index'));
    }

    public function test_admin_users_are_never_locked_out(): void
    {
        $admin = User::factory()->create(['email_verified_at' => null]);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_disabled_verification_preserves_old_behavior(): void
    {
        SiteSetting::set('email_verification_enabled', 'false');
        Queue::fake();

        $response = $this->post('/register', $this->registrationPayload(['email' => 'legacy@example.com']));

        $response->assertRedirect(route('dashboard.index'));
        Queue::assertNothingPushed();
        $this->assertSame(0, EmailVerificationCode::count());

        $user = $this->unverifiedUser();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_grandfather_migration_backfills_only_null_timestamps(): void
    {
        $verifiedAt = now()->subYear()->startOfSecond();
        $existing = User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subMonths(6)]);
        $alreadyVerified = User::factory()->create(['email_verified_at' => $verifiedAt]);

        // Re-run the backfill exactly as the migration does.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);

        $this->assertEquals(
            $existing->created_at->toDateTimeString(),
            $existing->fresh()->email_verified_at->toDateTimeString(),
            'grandfathered to created_at',
        );
        $this->assertEquals(
            $verifiedAt->toDateTimeString(),
            $alreadyVerified->fresh()->email_verified_at->toDateTimeString(),
            'existing timestamps preserved',
        );
        // Grandfathered users are NOT locked out.
        $this->actingAs($existing->fresh())->get('/dashboard')->assertOk();
    }

    // ── Email changes ────────────────────────────────────────────────────────

    public function test_user_email_change_requires_password_clears_verification_and_resends(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser(['password' => Hash::make('secret-pass-1')]);
        $this->issueCode($user);

        // Wrong password rejected.
        $this->actingAs($user)->from('/email/verify')->patch('/email/verification/change-address', [
            'email' => 'right@example.com', 'password' => 'wrong',
        ])->assertSessionHasErrors('password');

        // Correct password: normalized, old codes invalidated, new code sent.
        $this->actingAs($user)->patch('/email/verification/change-address', [
            'email' => '  NewAddr@Example.COM ', 'password' => 'secret-pass-1',
        ])->assertRedirect(route('verification.notice'));

        $user->refresh();
        $this->assertSame('newaddr@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(1, EmailVerificationCode::whereNull('used_at')->count());
        $this->assertSame('newaddr@example.com', EmailVerificationCode::whereNull('used_at')->first()->email);
    }

    public function test_admin_edit_can_explicitly_choose_verification_state(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => now()->subDay()]);

        // Simulate the EditUser flow: admin changes email, chooses "require verification".
        $page = new EditUser;
        $method = new \ReflectionMethod($page, 'handleRecordUpdate');
        $method->invoke($page, $user, ['email' => 'Changed@Example.com', 'email_change_mark_verified' => false]);
        $user->refresh();
        $this->assertSame('changed@example.com', $user->email);
        $this->assertNull($user->email_verified_at, 'never silently retains the old timestamp');

        // And "mark verified" sets a fresh timestamp.
        $method->invoke($page, $user, ['email' => 'again@example.com', 'email_change_mark_verified' => true]);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    // ── Onboarding order ─────────────────────────────────────────────────────

    public function test_email_then_phone_onboarding_order(): void
    {
        // Phone verification also required.
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'kavenegar');
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');
        SmsService::storeApiKey('test-key-123');

        $user = $this->unverifiedUser(['phone_verified_at' => null]);
        [, $code] = $this->issueCode($user);

        // Step 1: everything redirects to EMAIL verification first.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));

        // Step 2: after the email OTP, the user is sent to phone completion.
        $this->actingAs($user)->post('/email/verify', ['code' => $code])
            ->assertRedirect(route('dashboard.profile.complete'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    // ── Headers / markup ─────────────────────────────────────────────────────

    public function test_verification_pages_return_noindex_headers(): void
    {
        $user = $this->unverifiedUser();

        $response = $this->actingAs($user)->get('/email/verify');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_mobile_verification_markup_is_accessible(): void
    {
        $user = $this->unverifiedUser(['email' => 'someone@example.com']);

        $html = $this->actingAs($user)->get('/email/verify')->getContent();

        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('for="otp-code"', $html, 'labelled input');
        $this->assertStringContainsString('maxlength="6"', $html);
        // Email partially masked, never shown in full.
        $this->assertStringContainsString('so*', $html);
        $this->assertStringNotContainsString('someone@example.com', $html);
        // Logout + resend + change-address affordances present.
        $this->assertStringContainsString(route('logout'), $html);
        $this->assertStringContainsString(route('verification.resend'), $html);
        $this->assertStringContainsString(route('verification.change'), $html);
    }

    public function test_admin_cannot_require_verification_while_mail_unconfigured(): void
    {
        // An invalid From address makes the mail config unusable in ANY env.
        config(['mail.from.address' => 'not-an-email']);
        SiteSetting::set('email_verification_required_on_register', 'false');
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');

        $this->assertFalse(
            filter_var(SiteSetting::get('email_verification_required_on_register', false), FILTER_VALIDATE_BOOLEAN),
            'required flag must be refused while mail is unconfigured',
        );
    }

    // ── Codex review regressions ─────────────────────────────────────────────

    public function test_failover_mailer_containing_log_is_unconfigured_in_production(): void
    {
        config(['mail.default' => 'failover']);   // repo default: smtp → log
        $this->app->detectEnvironment(fn () => 'production');

        $svc = app(EmailVerificationService::class);
        $this->assertFalse($svc->isMailConfigured(), 'a log fallback silently discards OTPs');
        $this->assertFalse($svc->isRequiredOnRegister());
    }

    public function test_request_code_refused_while_feature_disabled(): void
    {
        SiteSetting::set('email_verification_enabled', 'false');
        Queue::fake();
        $user = $this->unverifiedUser();

        $result = app(EmailVerificationService::class)->requestCode($user);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, EmailVerificationCode::count());
        Queue::assertNothingPushed();

        // The resend endpoint honors the switch too.
        $this->actingAs($user)->from('/email/verify')->post('/email/verification/resend');
        $this->assertSame(0, EmailVerificationCode::count());
    }

    public function test_delivered_email_is_recorded_sent_not_stuck_queued(): void
    {
        // Sync queue: the job runs before requestCode() returns — its terminal
        // `sent` state must survive (queued is written BEFORE dispatch).
        Mail::fake();
        $user = $this->unverifiedUser();

        app(EmailVerificationService::class)->requestCode($user);

        $this->assertSame(
            EmailVerificationCode::SEND_STATUS_SENT,
            EmailVerificationCode::first()->send_status,
        );
    }

    public function test_stale_queued_job_is_skipped_never_sending_an_obsolete_code(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser(['email' => 'first@example.com']);
        Queue::fake();   // capture, don't run
        app(EmailVerificationService::class)->requestCode($user);
        $record = EmailVerificationCode::first();

        // The user corrects their address while the job waits in the queue.
        $user->forceFill(['email' => 'second@example.com'])->save();
        app(EmailVerificationService::class)->invalidateCodes($user);

        (new SendEmailOtpJob($record->id, 'first@example.com', '123456', 10))->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $record->fresh()->send_status);
    }

    public function test_notice_page_shows_remaining_cooldown_not_the_full_window(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        app(EmailVerificationService::class)->requestCode($user);

        $this->travel(45)->seconds();

        $remaining = app(EmailVerificationService::class)->resendCooldownRemaining($user);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(15, $remaining, 'must be the REMAINING seconds, not the full 60');

        $this->travel(20)->seconds();
        $this->assertSame(0, app(EmailVerificationService::class)->resendCooldownRemaining($user));
    }

    public function test_optional_email_with_required_phone_preserves_the_phone_flow(): void
    {
        SiteSetting::set('email_verification_required_on_register', 'false');   // optional
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'kavenegar');
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');
        SmsService::storeApiKey('test-key-123');
        Mail::fake();

        $response = $this->post('/register', $this->registrationPayload(['email' => 'optemail@example.com']));

        // The MANDATORY phone step wins: phone OTP sent, profile-complete next.
        $response->assertRedirect(route('dashboard.profile.complete'));
        $user = User::where('email', 'optemail@example.com')->first();
        $this->assertSame(1, PhoneVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_phone_otp_is_sent_after_the_required_email_step(): void
    {
        SiteSetting::set('sms_enabled', 'true');
        SiteSetting::set('sms_provider', 'kavenegar');
        SiteSetting::set('phone_verification_enabled', 'true');
        SiteSetting::set('phone_verification_required_on_register', 'true');
        SmsService::storeApiKey('test-key-123');

        $user = $this->unverifiedUser(['phone_verified_at' => null]);
        [, $code] = $this->issueCode($user);

        $this->actingAs($user)->post('/email/verify', ['code' => $code])
            ->assertRedirect(route('dashboard.profile.complete'));

        // The deferred REQUIRED phone flow continues: its OTP exists now.
        $this->assertSame(1, PhoneVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_email_change_uniqueness_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'Victim@EXAMPLE.COM']);
        $user = $this->unverifiedUser(['password' => Hash::make('secret-pass-1')]);

        $this->actingAs($user)->from('/email/verify')->patch('/email/verification/change-address', [
            'email' => 'victim@example.com', 'password' => 'secret-pass-1',
        ])->assertSessionHasErrors('email');
    }

    public function test_intended_destination_preserved_after_verification(): void
    {
        $user = $this->unverifiedUser();
        [, $code] = $this->issueCode($user);

        // Hitting a protected page records the intended URL…
        $this->actingAs($user)->get('/dashboard/wallet')->assertRedirect(route('verification.notice'));
        // …and a successful verification returns there.
        $this->actingAs($user)->post('/email/verify', ['code' => $code])
            ->assertRedirect(url('/dashboard/wallet'));
    }
}
