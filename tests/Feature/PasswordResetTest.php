<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Middleware\EnsureSessionAuthVersion;
use App\Jobs\SendPasswordResetOtpJob;
use App\Mail\PasswordResetOtpMail;
use App\Models\EmailVerificationCode;
use App\Models\PasswordResetChallenge;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\ResetIdentifier;
use App\Services\Email\EmailVerificationService;
use App\Services\Sms\SmsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Secure, non-enumerating OTP password reset:
 *  - indistinguishable public responses for existing / nonexistent /
 *    ineligible / delivery-broken accounts;
 *  - identifiers absent from logs, URLs and rate-limit keys;
 *  - purpose-scoped hash-only OTP challenges (10 min, ≤5 attempts, one-time);
 *  - session/time/single-use-bound reset authorization;
 *  - atomic reset that rotates remember_token and revokes every other
 *    authenticated session (password-hash-bound sessions);
 *  - administrator TOTP unaffected.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'reset-target@example.com';

    private const PHONE = '09121234567';

    private const OLD_PASSWORD = 'Old-Password-1';

    private const NEW_PASSWORD = 'Brand-New-Pass-1';

    /** Recording Timebox fake: no real sleep, captures configured minimums. */
    public object $timebox;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('pwrr'); // buckets are per-test anyway (array cache)

        // Swap the real Timebox for a recording fake so the suite does not
        // sleep the fixed minimum on every request; the timing-evidence test
        // asserts the SAME boundary + minimum is invoked for every outcome.
        $this->timebox = new class extends Timebox
        {
            /** @var list<int> */
            public array $calls = [];

            public function call(callable $callback, int $microseconds)
            {
                $this->calls[] = $microseconds;

                return $callback($this);
            }
        };
        $this->app->instance(Timebox::class, $this->timebox);

        BarrierDeliveryJob::$onBeforeClaim = null;
        BarrierDeliveryJob::$onBeforeTransport = null;
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
            'normalized_phone' => '+989121234567',
            'password' => bcrypt(self::OLD_PASSWORD),
            'email_verified_at' => now(),
        ], $attributes));
    }

    /** Run the request step and return the response. */
    private function requestReset(string $identifier): TestResponse
    {
        return $this->post(route('password.request.send'), ['identifier' => $identifier]);
    }

    /** Full happy path up to an issued challenge; returns the plaintext OTP. */
    private function issueAndCaptureCode(string $identifier = self::EMAIL): string
    {
        Mail::fake();
        $this->requestReset($identifier)->assertRedirect(route('password.verify'));

        $code = null;
        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code);

        return (string) $code;
    }

    // ── Non-enumerating request step ─────────────────────────────────────────

    public function test_login_page_links_to_password_recovery(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'), false);
    }

    public function test_existing_nonexistent_and_ineligible_identifiers_are_indistinguishable(): void
    {
        Mail::fake();
        $this->user();

        $responses = [
            'existing-email' => $this->requestReset(self::EMAIL),
            'nonexistent-email' => $this->requestReset('nobody-here@example.com'),
            'existing-phone-sms-unconfigured' => $this->requestReset(self::PHONE),
            'nonexistent-phone' => $this->requestReset('09359999999'),
            'garbage' => $this->requestReset('not-an-identifier'),
        ];

        foreach ($responses as $label => $response) {
            $response->assertStatus(302, $label);
            $this->assertSame(route('password.verify'), $response->headers->get('Location'), $label);
            $response->assertSessionHas('status', PasswordResetController::GENERIC_REQUEST_MESSAGE);
            $response->assertSessionHas(PasswordResetService::SESSION_TOKEN_KEY);
        }
    }

    public function test_delivery_publication_failure_returns_the_same_public_response(): void
    {
        $this->user();
        // Queue publication itself blows up for the real account…
        Queue::partialMock()->shouldReceive('connection')
            ->andThrow(new \RuntimeException('redis down'));

        $real = $this->requestReset(self::EMAIL);

        // …and the response is still EXACTLY the generic one.
        $real->assertStatus(302);
        $this->assertSame(route('password.verify'), $real->headers->get('Location'));
        $real->assertSessionHas('status', PasswordResetController::GENERIC_REQUEST_MESSAGE);

        // Honest internal delivery state, invisible to the requester.
        $this->assertSame(
            PasswordResetChallenge::SEND_STATUS_DISPATCH_FAILED,
            PasswordResetChallenge::firstOrFail()->send_status,
        );
    }

    public function test_submitted_identifier_never_reaches_logs_urls_or_limiter_keys(): void
    {
        Mail::fake();
        $this->user();
        $canary = self::EMAIL;

        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $response = $this->requestReset($canary);

        // Not in the redirect URL (no query string at all).
        $this->assertSame(route('password.verify'), $response->headers->get('Location'));
        // Not in any log line.
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($canary, $line);
            $this->assertStringNotContainsString('reset-target', $line);
        }

        // Not in any rate-limiter key: the limiter reduces the identifier to
        // an APP_KEY-keyed HMAC before it becomes a cache key.
        $request = Request::create('/forgot-password', 'POST', ['identifier' => $canary]);
        foreach (['password-reset-request', 'password-reset-verify', 'password-reset-submit'] as $limiter) {
            foreach ((array) app(\Illuminate\Cache\RateLimiter::class)->limiter($limiter)($request) as $limit) {
                $this->assertStringNotContainsString($canary, (string) $limit->key, $limiter);
                $this->assertStringNotContainsString('reset-target', (string) $limit->key, $limiter);
            }
        }
    }

    // ── Challenge storage and OTP rules ──────────────────────────────────────

    public function test_challenge_stores_hash_only_and_short_expiry(): void
    {
        $this->user();
        $code = $this->issueAndCaptureCode();

        $challenge = PasswordResetChallenge::firstOrFail();
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertStringStartsWith('$2y$', $challenge->code_hash);
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->assertTrue($challenge->expires_at->lte(now()->addMinutes(PasswordResetService::CODE_TTL_MINUTES)));
        $this->assertSame(PasswordResetChallenge::CHANNEL_EMAIL, $challenge->channel);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $challenge->send_status);
    }

    public function test_wrong_code_expired_code_and_exhausted_attempts_fail(): void
    {
        $this->user();
        $code = $this->issueAndCaptureCode();

        // Wrong code.
        $this->post(route('password.verify.submit'), ['code' => $code === '111111' ? '222222' : '111111'])
            ->assertSessionHasErrors('code');

        // Exhaust the attempt budget with wrong codes…
        for ($i = 1; $i < PasswordResetService::MAX_ATTEMPTS; $i++) {
            $this->post(route('password.verify.submit'), ['code' => '000000']);
        }
        // …then even the CORRECT code is refused.
        $this->post(route('password.verify.submit'), ['code' => $code])
            ->assertSessionHasErrors('code');

        // Expired challenge: fresh issuance, then time passes.
        $code2 = $this->issueAndCaptureCode();
        $this->travel(PasswordResetService::CODE_TTL_MINUTES + 1)->minutes();
        $this->post(route('password.verify.submit'), ['code' => $code2])
            ->assertSessionHasErrors('code');
    }

    public function test_verification_codes_and_reset_codes_cannot_cross_purposes(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        // A NORMAL email-verification OTP must never pass reset verification.
        EmailVerificationCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'code_hash' => Hash::make('333444'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        $this->issueAndCaptureCode(); // real reset challenge exists in-session
        $this->post(route('password.verify.submit'), ['code' => '333444'])
            ->assertSessionHasErrors('code');

        // A RESET OTP must never verify an email address.
        $resetCode = $this->issueAndCaptureCode();
        $result = app(EmailVerificationService::class)->verify($user->refresh(), $resetCode);
        $this->assertNotSame('verified', $result['status'] ?? null);
        $this->assertNull($user->refresh()->email_verified_at);
    }

    // ── Reset authorization binding ──────────────────────────────────────────

    public function test_authorization_is_session_bound_time_bound_and_single_use(): void
    {
        $this->user();
        $code = $this->issueAndCaptureCode();

        $this->post(route('password.verify.submit'), ['code' => $code])
            ->assertRedirect(route('password.reset'));
        $token = session(PasswordResetService::SESSION_TOKEN_KEY);
        $this->assertIsString($token);
        $this->assertIsString(session(PasswordResetService::SESSION_PROOF_KEY));

        // SESSION-BOUND: a different guest session presenting the stolen
        // token — but not this session's authorization proof — cannot
        // finalize.
        $this->flushSession();
        $this->withSession([PasswordResetService::SESSION_TOKEN_KEY => $token])
            ->post(route('password.update'), [
                'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect(route('password.request'));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, User::firstOrFail()->password));

        // TIME-BOUND: fresh authorization, then past its expiry.
        $code = $this->issueAndCaptureCode();
        $this->post(route('password.verify.submit'), ['code' => $code]);
        $this->travel(PasswordResetService::AUTHORIZATION_TTL_MINUTES + 1)->minutes();
        $this->post(route('password.update'), [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('password.request'));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, User::firstOrFail()->password));
    }

    public function test_successful_reset_changes_password_rotates_remember_token_and_is_single_use(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => 'OLD_REMEMBER_TOKEN_VALUE_123456789012345678901234567890'])->save();
        $oldRemember = $user->remember_token;

        $code = $this->issueAndCaptureCode();
        $this->post(route('password.verify.submit'), ['code' => $code])
            ->assertRedirect(route('password.reset'));
        $token = session(PasswordResetService::SESSION_TOKEN_KEY);
        $proof = session(PasswordResetService::SESSION_PROOF_KEY);

        $response = $this->post(route('password.update'), [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]);
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user->refresh();
        // Old password dead, new one live, remember-me revoked.
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $user->password));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        // NOT logged in automatically.
        $this->assertGuest();
        // Challenge consumed exactly once — replaying the FULL stolen state
        // (token AND proof) fails.
        $this->withSession([
            PasswordResetService::SESSION_TOKEN_KEY => $token,
            PasswordResetService::SESSION_PROOF_KEY => $proof,
        ])
            ->post(route('password.update'), [
                'password' => 'Another-Pass-22', 'password_confirmation' => 'Another-Pass-22',
            ])->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));

        // Old password fails at login; the new one succeeds.
        $this->post('/login', ['username' => $user->username, 'password' => self::OLD_PASSWORD])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->post('/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_policy_matches_registration(): void
    {
        $this->user();
        $code = $this->issueAndCaptureCode();
        $this->post(route('password.verify.submit'), ['code' => $code]);

        // Too short.
        $this->post(route('password.update'), [
            'password' => 'short1', 'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');
        // Unconfirmed.
        $this->post(route('password.update'), [
            'password' => 'Long-Enough-11', 'password_confirmation' => 'Different-One-22',
        ])->assertSessionHasErrors('password');
        // Nothing changed.
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, User::firstOrFail()->password));
    }

    // ── All-session revocation (password-hash-bound sessions) ────────────────

    public function test_sessions_stamped_with_the_old_password_hash_are_rejected_after_reset(): void
    {
        $user = $this->user();
        $oldHash = $user->password;

        $this->resetPasswordFor($user);

        // A session from another device, stamped with the OLD hash, dies on
        // its next request (AuthenticateSession) — user is logged out.
        $this->actingAs($user->refresh())
            ->withSession(['password_hash_web' => $oldHash])
            ->get(route('dashboard.index'))
            ->assertRedirect();
        $this->assertGuest();
    }

    public function test_pre_deployment_sessions_without_a_hash_stamp_survive_until_a_credential_change(): void
    {
        $user = $this->user();

        // Compatibility policy: an existing session with NO stamp is adopted
        // (stamped lazily) instead of being logged out at deployment.
        $this->actingAs($user);
        session()->forget('password_hash_web'); // simulate a pre-deployment session
        $this->get(route('dashboard.index'))->assertOk();
        $this->assertAuthenticatedAs($user);
        // Stamped lazily (HMAC'd by the guard) — present and accepted.
        $this->assertNotNull(session('password_hash_web'));
    }

    public function test_admin_totp_gate_survives_a_password_reset(): void
    {
        $admin = $this->user([
            'is_admin' => true,
            'username' => 'resetadmin',
        ]);

        $this->resetPasswordFor($admin);

        // The TOTP credential store was never touched by the reset flow…
        $this->assertSame(0, DB::table('admin_two_factor_credentials')->count());
        // …and the panel still demands the MFA gate on the next login: a
        // freshly authenticated admin session WITHOUT a TOTP marker cannot
        // reach /zed-admin.
        $this->actingAsWithoutAdminMfa($admin->refresh());
        $this->get('/zed-admin')->assertRedirect();
    }

    // ── Headers ──────────────────────────────────────────────────────────────

    public function test_reset_pages_are_noindex_and_sensitive_steps_are_no_store(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $this->user();
        $this->issueAndCaptureCode();

        $this->get(route('password.verify'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $code = null; // move to the password step for its headers
        Mail::fake();
        $this->post(route('password.verify.submit'), ['code' => $this->latestCodeFor()]);
        $this->get(route('password.reset'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    // ── Monotonic authentication versioning (4.1) ────────────────────────────

    public function test_unstamped_session_fails_closed_after_version_advances(): void
    {
        $user = $this->user();
        $this->resetPasswordFor($user); // auth_version: 1 → 2

        // Pre-deployment session (no stamps at all) whose FIRST request
        // happens AFTER the reset: fail closed — the old adoption bypass is
        // gone.
        $this->actingAs($user->refresh());
        session()->forget(['password_hash_web', EnsureSessionAuthVersion::SESSION_KEY]);
        $this->get(route('dashboard.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_stamped_old_version_session_is_rejected_after_reset(): void
    {
        $user = $this->user()->refresh(); // pull the DB-default auth_version
        $this->assertSame(User::INITIAL_AUTH_VERSION, (int) $user->auth_version);

        $this->resetPasswordFor($user);
        $this->assertSame(User::INITIAL_AUTH_VERSION + 1, (int) $user->refresh()->auth_version);

        // A session from another device stamped with the OLD version dies —
        // even when its (legacy-layer) password stamp is somehow current.
        $this->actingAs($user)
            ->withSession([EnsureSessionAuthVersion::SESSION_KEY => User::INITIAL_AUTH_VERSION])
            ->get(route('dashboard.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_fresh_login_after_reset_stamps_the_new_version_and_succeeds(): void
    {
        $user = $this->user();
        $this->resetPasswordFor($user);

        $this->post('/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(
            User::INITIAL_AUTH_VERSION + 1,
            (int) session(EnsureSessionAuthVersion::SESSION_KEY),
        );
        $this->get(route('dashboard.index'))->assertOk();
    }

    public function test_login_paths_stamp_the_authentication_version(): void
    {
        // Normal login.
        $user = $this->user()->refresh(); // pull the DB-default auth_version
        $this->post('/login', ['username' => $user->username, 'password' => self::OLD_PASSWORD])->assertRedirect();
        $this->assertSame((int) $user->auth_version, (int) session(EnsureSessionAuthVersion::SESSION_KEY));

        // Registration auto-login.
        $this->post('/logout');
        $this->flushSession();
        $this->post('/register', [
            'name' => 'Stamp Test', 'username' => 'stampuser1',
            'email' => 'stamp-user@example.com', 'phone' => '09359998877',
            'password' => 'Register-Pass-1', 'password_confirmation' => 'Register-Pass-1',
        ]);
        $this->assertAuthenticated();
        $this->assertSame(User::INITIAL_AUTH_VERSION, (int) session(EnsureSessionAuthVersion::SESSION_KEY));
    }

    public function test_old_remember_token_fails_after_reset(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => str_repeat('R', 60)])->save();
        $oldToken = $user->remember_token;

        $this->resetPasswordFor($user);
        $this->assertNotSame($oldToken, $user->refresh()->remember_token);

        // A remember-me cookie carrying the OLD rotated-away token must not
        // authenticate.
        $recaller = auth()->guard('web')->getRecallerName();
        $this->flushSession();
        $this->disableCookieEncryption()
            ->withUnencryptedCookie($recaller, $user->id.'|'.$oldToken.'|'.$user->password)
            ->get(route('dashboard.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ── Canonical identifiers for lookup + rate limiting (4.2) ───────────────

    public function test_equivalent_identifier_forms_share_one_limiter_bucket(): void
    {
        $emailForms = ['  User@Example.COM  ', 'user@example.com', 'USER@EXAMPLE.COM'];
        $subjects = array_map(fn ($f) => ResetIdentifier::limiterSubject($f), $emailForms);
        $this->assertCount(1, array_unique($subjects), 'equivalent email forms → one bucket');

        $phoneForms = ['09121234567', '+989121234567', '00989121234567', '989121234567', ' 0912 123 4567 '];
        $subjects = array_map(fn ($f) => ResetIdentifier::limiterSubject($f), $phoneForms);
        $this->assertCount(1, array_unique($subjects), 'equivalent phone forms → one bucket');

        // Distinct canonical accounts keep independent buckets.
        $this->assertNotSame(
            ResetIdentifier::limiterSubject('a@example.com'),
            ResetIdentifier::limiterSubject('b@example.com'),
        );
        // Raw values never appear in the subject.
        foreach (array_merge($emailForms, $phoneForms) as $form) {
            $this->assertStringNotContainsString('example', ResetIdentifier::limiterSubject($form));
            $this->assertStringNotContainsString('0912', ResetIdentifier::limiterSubject($form));
        }
    }

    public function test_cycling_identifier_representations_cannot_exceed_the_account_limit(): void
    {
        Mail::fake();
        $this->user();

        // 3 allowed requests, each using a DIFFERENT representation of the
        // same email — then the fourth form is throttled anyway.
        $this->requestReset(self::EMAIL)->assertStatus(302);
        $this->requestReset('  '.strtoupper(self::EMAIL).'  ')->assertStatus(302);
        $this->requestReset('Reset-Target@Example.com')->assertStatus(302);
        $this->requestReset('RESET-TARGET@example.COM')->assertStatus(429);
    }

    public function test_service_lookup_and_limiter_share_the_same_canonicalizer(): void
    {
        Mail::fake();
        $user = $this->user();

        // A decorated equivalent form still resolves the SAME account.
        $this->requestReset('  '.strtoupper(self::EMAIL).'  ')->assertStatus(302);
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->count());
    }

    // ── Atomic issuance + DB invariant + delivery ordering (4.3) ─────────────

    public function test_database_invariant_rejects_a_second_active_challenge(): void
    {
        $user = $this->user();
        $this->issueAndCaptureCode(); // one ACTIVE challenge exists

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($user): void {
            PasswordResetChallenge::create([
                'user_id' => $user->id,
                'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
                'token' => bin2hex(random_bytes(32)),
                'code_hash' => bcrypt('999999'),
                'expires_at' => now()->addMinutes(10),
            ]);
        });
    }

    public function test_rolled_back_issuance_publishes_no_delivery_job(): void
    {
        Queue::fake();
        $user = $this->user();

        $svc = \Mockery::mock(
            PasswordResetService::class,
            [app(EmailVerificationService::class), app(SmsService::class), $this->timebox],
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('persistChallenge')->once()
            ->andThrow(new \RuntimeException('insert failed'));

        $token = $svc->request(self::EMAIL); // never throws — decoy comes back

        $this->assertIsString($token);
        Queue::assertNothingPushed();
        $this->assertSame(0, PasswordResetChallenge::count(), 'rollback left no challenge row');
    }

    public function test_delivery_job_for_a_superseded_challenge_sends_nothing(): void
    {
        Mail::fake();
        $user = $this->user();

        $challenge = PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => bin2hex(random_bytes(32)),
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => now(), // superseded before the worker got to it
            'send_status' => PasswordResetChallenge::SEND_STATUS_QUEUED,
        ]);

        (new SendPasswordResetOtpJob($challenge->id, 'email', self::EMAIL, '123456'))
            ->handle(app(SmsService::class));

        Mail::assertNothingSent();
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_QUEUED, $challenge->fresh()->send_status);
    }

    // ── Fixed-minimum request timing boundary (4.4) ──────────────────────────

    public function test_every_request_outcome_runs_inside_the_same_timing_boundary(): void
    {
        Mail::fake();
        $this->user();

        $this->requestReset(self::EMAIL);                       // real issuance
        $this->requestReset('nobody-here@example.com');         // decoy
        $this->requestReset('not-an-identifier');               // invalid form
        Queue::partialMock()->shouldReceive('connection')
            ->andThrow(new \RuntimeException('redis down'));
        $this->requestReset(self::EMAIL);                       // publication failure

        // The SAME boundary with the SAME fixed minimum wrapped all four.
        $this->assertSame(
            array_fill(0, 4, PasswordResetService::REQUEST_TIMEBOX_MICROSECONDS),
            $this->timebox->calls,
        );
    }

    // ── Coordinated delivery claim: no stale OTP transport (4.2) ─────────────

    /** Issue a challenge WITHOUT inline delivery (queue faked). */
    private function issueWithoutDelivery(): PasswordResetChallenge
    {
        Queue::fake();
        app(PasswordResetService::class)->request(self::EMAIL);

        return PasswordResetChallenge::whereNull('consumed_at')->latest('id')->firstOrFail();
    }

    private function deliveryJob(PasswordResetChallenge $challenge, string $channel = 'email', string $code = '123456'): BarrierDeliveryJob
    {
        return new BarrierDeliveryJob(
            $challenge->id,
            $channel,
            $channel === 'sms' ? '+989121234567' : self::EMAIL,
            $code,
            (int) $challenge->user_id,
        );
    }

    public function test_replacement_issuance_before_the_claim_stops_the_stale_transport(): void
    {
        Mail::fake();
        $user = $this->user();
        $old = $this->issueWithoutDelivery();

        // The old worker is paused BEFORE it can claim; a replacement
        // issuance wins the coordination boundary and consumes the old
        // challenge. The resumed worker must transport NOTHING.
        BarrierDeliveryJob::$onBeforeClaim = function (): void {
            Queue::fake();
            app(PasswordResetService::class)->request(self::EMAIL);
        };

        $this->deliveryJob($old)->handle(app(SmsService::class));

        Mail::assertNothingSent();
        $this->assertNotNull($old->fresh()->consumed_at, 'old challenge was superseded');
        // The stale worker never touched the newer authoritative row.
        $new = PasswordResetChallenge::whereNull('consumed_at')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame(PasswordResetChallenge::SEND_STATUS_SENT, $new->send_status);
        $this->assertNotSame(PasswordResetChallenge::SEND_STATUS_FAILED, $new->send_status);
    }

    public function test_transport_runs_under_the_per_user_lock_and_outside_any_transaction(): void
    {
        Mail::fake();
        $user = $this->user();
        $challenge = $this->issueWithoutDelivery();

        // RefreshDatabase wraps each test in its own transaction, so the
        // evidence is that the job opens NO ADDITIONAL transaction level
        // around the transport (its claim transaction is already committed).
        $baseline = DB::transactionLevel();
        $observed = [];
        BarrierDeliveryJob::$onBeforeTransport = function () use (&$observed, $user): void {
            // No SQL transaction may wrap the network call…
            $observed['transaction_level'] = DB::transactionLevel();
            // …and the per-user coordination lock IS held, so a replacement
            // issuance cannot become authoritative mid-flight.
            $probe = Cache::lock(PasswordResetService::userLockKey($user->id), 1);
            $observed['lock_free'] = $probe->get();
            if ($observed['lock_free']) {
                $probe->release();
            }
        };

        $this->deliveryJob($challenge)->handle(app(SmsService::class));

        $this->assertSame($baseline, $observed['transaction_level'], 'transport must not open a DB transaction');
        $this->assertFalse($observed['lock_free'], 'per-user lock must be held across the transport');
        Mail::assertSentCount(1);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $challenge->fresh()->send_status);
    }

    public function test_issuance_fails_closed_while_a_delivery_claim_holds_the_lock(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->issueWithoutDelivery();
        $before = PasswordResetChallenge::count();

        // Simulate an in-flight delivery attempt holding the coordination
        // lock: a replacement issuance must NOT slip past it.
        $held = Cache::lock(PasswordResetService::userLockKey($user->id), 30);
        $this->assertTrue($held->get());

        try {
            $token = app(PasswordResetService::class)->request(self::EMAIL);
            // Public behavior is unchanged (a decoy token + generic message),
            // and no replacement challenge was created.
            $this->assertIsString($token);
            $this->assertSame($before, PasswordResetChallenge::count());
            $this->assertSame(0, PasswordResetChallenge::where('token', $token)->count());
        } finally {
            $held->release();
        }
    }

    public function test_email_and_sms_transports_run_at_most_once_per_challenge(): void
    {
        Mail::fake();
        $this->user();
        $challenge = $this->issueWithoutDelivery();

        // Two runs of the SAME job (a queue retry after a successful attempt):
        // the finalized row is no longer claimable, so nothing is re-sent.
        $this->deliveryJob($challenge)->handle(app(SmsService::class));
        $this->deliveryJob($challenge)->handle(app(SmsService::class));

        Mail::assertSentCount(1);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $challenge->fresh()->send_status);

        // Same guarantee on the SMS channel (the email challenge is retired
        // first — the single-active invariant forbids two active rows).
        $challenge->forceFill(['consumed_at' => now()])->save();
        SiteSetting::set('sms_enabled', 'true');
        $sms = \Mockery::mock(SmsService::class)->makePartial();
        $sms->shouldReceive('sendOtp')->once()->andReturnTrue();
        $smsChallenge = PasswordResetChallenge::create([
            'user_id' => $challenge->user_id,
            'channel' => PasswordResetChallenge::CHANNEL_SMS,
            'token' => bin2hex(random_bytes(32)),
            'code_hash' => bcrypt('222222'),
            'expires_at' => now()->addMinutes(10),
            'send_status' => PasswordResetChallenge::SEND_STATUS_QUEUED,
        ]);
        $this->deliveryJob($smsChallenge, 'sms', '222222')->handle($sms);
        $this->deliveryJob($smsChallenge, 'sms', '222222')->handle($sms);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $smsChallenge->fresh()->send_status);
    }

    public function test_failed_transport_finalizes_the_claim_and_never_blocks_future_issuance(): void
    {
        $this->user();
        $challenge = $this->issueWithoutDelivery();
        $challenge->forceFill(['channel' => PasswordResetChallenge::CHANNEL_SMS])->save();

        // A transport that THROWS (credentials in the message — they must not
        // reach any log): the job handles any Throwable identically on both
        // channels.
        $sms = \Mockery::mock(SmsService::class)->makePartial();
        $sms->shouldReceive('sendOtp')->once()
            ->andThrow(new \RuntimeException('smtp://user:pw@mail.example:587 refused'));

        $this->deliveryJob($challenge, 'sms', '222222')->handle($sms);

        $fresh = $challenge->fresh();
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_FAILED, $fresh->send_status);
        $this->assertNull($fresh->delivery_claim_token, 'claim released on failure');

        // A retry after a FAILED attempt does not re-send (documented policy:
        // one transport attempt per challenge) …
        $sms->shouldNotReceive('sendOtp');
        $this->deliveryJob($challenge, 'sms', '222222')->handle($sms);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_FAILED, $challenge->fresh()->send_status);

        // … and a brand-new issuance still works immediately.
        Queue::fake();
        Mail::fake();
        app(PasswordResetService::class)->request(self::EMAIL);
        $this->assertSame(1, PasswordResetChallenge::whereNull('consumed_at')->count());
    }

    public function test_abandoned_claim_from_a_crashed_worker_is_recoverable(): void
    {
        Mail::fake();
        $this->user();
        $challenge = $this->issueWithoutDelivery();

        // A crashed worker left a `sending` row owned by a token nobody holds.
        $challenge->forceFill([
            'send_status' => PasswordResetChallenge::SEND_STATUS_SENDING,
            'delivery_claim_token' => bin2hex(random_bytes(32)),
            'delivery_claimed_at' => now()->subMinutes(PasswordResetService::ABANDONED_CLAIM_MINUTES + 1),
        ])->save();

        $this->deliveryJob($challenge)->handle(app(SmsService::class));

        Mail::assertSentCount(1);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $challenge->fresh()->send_status);
    }

    public function test_a_fresh_claim_held_by_another_worker_is_not_stolen(): void
    {
        Mail::fake();
        $this->user();
        $challenge = $this->issueWithoutDelivery();

        // Another worker claimed it moments ago — still within the lease.
        $challenge->forceFill([
            'send_status' => PasswordResetChallenge::SEND_STATUS_SENDING,
            'delivery_claim_token' => bin2hex(random_bytes(32)),
            'delivery_claimed_at' => now(),
        ])->save();

        $this->deliveryJob($challenge)->handle(app(SmsService::class));

        Mail::assertNothingSent();
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENDING, $challenge->fresh()->send_status);
    }

    public function test_expired_challenge_is_never_transported(): void
    {
        Mail::fake();
        $this->user();
        $challenge = $this->issueWithoutDelivery();
        $challenge->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->deliveryJob($challenge)->handle(app(SmsService::class));

        Mail::assertNothingSent();
    }

    public function test_claim_states_keep_the_single_active_invariant_intact(): void
    {
        Mail::fake();
        $user = $this->user();
        $challenge = $this->issueWithoutDelivery();

        // A CLAIMED (`sending`) challenge is still ACTIVE: the partial unique
        // index must still refuse a second unconsumed row for this account.
        $challenge->forceFill([
            'send_status' => PasswordResetChallenge::SEND_STATUS_SENDING,
            'delivery_claim_token' => bin2hex(random_bytes(32)),
            'delivery_claimed_at' => now(),
        ])->save();

        $this->expectException(QueryException::class);
        PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => bin2hex(random_bytes(32)),
            'code_hash' => bcrypt('333333'),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Complete a full reset for $user via HTTP (email channel). */
    private function resetPasswordFor(User $user): void
    {
        $code = $this->issueAndCaptureCode((string) $user->email);
        $this->post(route('password.verify.submit'), ['code' => $code])
            ->assertRedirect(route('password.reset'));
        $this->post(route('password.update'), [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    /** The correct code for the latest active challenge (re-issued freshly). */
    private function latestCodeFor(): string
    {
        return $this->issueAndCaptureCode();
    }
}

/**
 * The real delivery job plus its two deterministic-concurrency seams, so a
 * test can interleave a replacement issuance at an exact point without any
 * timing sleep.
 */
class BarrierDeliveryJob extends SendPasswordResetOtpJob
{
    public static ?\Closure $onBeforeClaim = null;

    public static ?\Closure $onBeforeTransport = null;

    protected function beforeClaim(): void
    {
        if (self::$onBeforeClaim !== null) {
            (self::$onBeforeClaim)();
        }
    }

    protected function beforeTransport(): void
    {
        if (self::$onBeforeTransport !== null) {
            (self::$onBeforeTransport)();
        }
    }
}
