<?php

namespace Tests;

use App\Models\AdminTwoFactorCredential;
use App\Models\User as AppUser;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminStepUpService;
use App\Services\AdminMfa\AdminTotpService;
use App\Services\Email\EmailTransportSettingsService;
use App\Services\Theme\ThemeSettingsService;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // BEFORE the app boots: the SMTP resolver's process-immutable
        // environment baseline must be forgotten first — application boot
        // runs apply(), and a baseline left over from the previous test
        // would be written back into the freshly built config.
        EmailTransportSettingsService::resetProcessBaselineForTesting();

        parent::setUp();

        // Stub Vite so view-rendering tests don't require a compiled asset
        // manifest (public/build/manifest.json). Without this, any test that
        // renders a Blade layout using @vite throws a ViewException → HTTP 500
        // in CI environments that don't run `npm run build`.
        $this->withoutVite();

        // The settings service memoises per request; flush it between tests so
        // a value set in one test can never leak into the next via the memo.
        ThemeSettingsService::flush();
    }

    /**
     * The admin panel requires a server-side MFA marker on top of
     * authentication. actingAs() in a test means "an established
     * authenticated session", so for admins it also provisions a confirmed
     * TOTP credential and primes the session marker — otherwise every
     * admin-page test would stop at the MFA challenge. Tests that exercise
     * the missing-MFA denial paths use actingAsWithoutAdminMfa().
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        if ($guard === null && $user instanceof AppUser && $user->is_admin === true) {
            $this->withAdminMfaVerified($user);
        }

        $this->stampSessionPasswordHash($user, $guard);

        return parent::actingAs($user, $guard);
    }

    /** Authenticate WITHOUT the MFA marker (for denial-path tests). */
    public function actingAsWithoutAdminMfa(UserContract $user)
    {
        $this->stampSessionPasswordHash($user, null);

        return parent::actingAs($user);
    }

    /**
     * AuthenticateSession binds every web session to the user's current
     * password hash (a real login stamps it via the Login event). actingAs()
     * means "an established authenticated session", so it stamps the same
     * binding — otherwise switching test users in one session would trip a
     * false credential-change logout. Tests that exercise stale-session
     * rejection overwrite the stamp explicitly with withSession(), and the
     * lazy-adoption test forgets it before the request.
     */
    private function stampSessionPasswordHash(UserContract $user, ?string $guard): void
    {
        if (($guard === null || $guard === 'web') && $user instanceof AppUser) {
            // Raw-hash legacy format — accepted by validatePasswordHash().
            session()->put('password_hash_web', (string) $user->getAuthPassword());
        }
    }

    /**
     * Carry the CURRENT session id into subsequent requests. Production keeps
     * the id stable via the session cookie; the test harness omits it, so the
     * id rotates every request — which breaks flows whose server-side records
     * are legitimately bound to the session id.
     */
    public function withCurrentSessionId(): static
    {
        return $this->withCookie((string) config('session.cookie'), session()->getId());
    }

    /**
     * Ensure a CONFIRMED admin TOTP credential exists (enrolled through the
     * real service with a real generated code) and prime the session MFA
     * marker for the next request.
     */
    public function withAdminMfaVerified(AppUser $user): static
    {
        $this->provisionConfirmedAdminTotp($user);

        $this->withSession([
            AdminMfaSession::MARKER_KEY => $this->adminMfaMarker($user),
        ]);

        return $this;
    }

    /** @return array{user_id: int, version: string, step: int|null, via: string, verified_at: int} */
    protected function adminMfaMarker(AppUser $user, string $via = 'totp'): array
    {
        $cred = app(AdminTotpService::class)->credentialFor($user);

        return [
            'user_id' => $user->id,
            'version' => (string) $cred?->version(),
            'step' => $cred?->last_verified_timestep,
            'via' => $via,
            'verified_at' => now()->getTimestamp(),
        ];
    }

    /**
     * Enroll + confirm a real TOTP credential for an admin user. The consumed
     * time-step is rewound afterwards so the CURRENT live code stays usable
     * in the test (enrollment consumed it) — e.g. for step-up attempts.
     */
    protected function provisionConfirmedAdminTotp(AppUser $user): AdminTwoFactorCredential
    {
        $totp = app(AdminTotpService::class);

        if (! $totp->hasConfirmedCredential($user)) {
            $enrollment = $totp->startEnrollment($user);
            $code = app(Google2FA::class)->getCurrentOtp($enrollment['secret']);
            $totp->confirmEnrollment($user, $code);
        }

        $cred = $totp->credentialFor($user);
        $cred->forceFill([
            'last_verified_timestep' => max(0, (int) $cred->last_verified_timestep - 10),
        ])->save();

        return $cred->refresh();
    }

    /** The current live TOTP code for an enrolled admin (test convenience). */
    protected function currentAdminTotpCode(AppUser $user): string
    {
        $cred = app(AdminTotpService::class)->credentialFor($user);

        return app(Google2FA::class)->getCurrentOtp($cred->secret);
    }

    /**
     * Prime BOTH the MFA marker and an active `admin_sensitive_communications`
     * step-up grant directly in the CURRENT session store — for Livewire
     * component tests of the sensitive settings pages (Livewire::test() does
     * not run HTTP middleware, but the pages verify the grant server-side in
     * mount and on every action).
     */
    public function grantCommunicationsStepUp(AppUser $user): static
    {
        $cred = $this->provisionConfirmedAdminTotp($user);

        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($user));
        session()->put(AdminStepUpService::GRANT_KEY, [
            'user_id' => $user->id,
            'session_id' => session()->getId(),
            'version' => $cred->version(),
            'password_hash' => (string) $user->getAuthPassword(),
            'scope' => AdminStepUpService::SCOPE,
            'step' => $cred->last_verified_timestep,
            'issued_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(AdminStepUpService::LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return $this;
    }
}
