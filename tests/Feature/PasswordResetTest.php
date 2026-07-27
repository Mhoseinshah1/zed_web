<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Mail\PasswordResetOtpMail;
use App\Models\EmailVerificationCode;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\Email\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('pwrr'); // buckets are per-test anyway (array cache)
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
