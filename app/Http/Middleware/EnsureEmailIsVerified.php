<?php

namespace App\Http\Middleware;

use App\Services\Email\EmailVerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `email.verified` — blocks protected business functionality for users who
 * still have to verify their email address.
 *
 * Fail-open by design where it must be:
 *   - verification disabled (or not REQUIRED) → passthrough, old behavior
 *   - admins (installer-created accounts) are never locked out
 *   - grandfathered users are verified by the backfill migration
 * The verification routes themselves are never behind this middleware, so a
 * redirect loop is impossible.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $verification = app(EmailVerificationService::class);

        // Enforce ONLY when all of these hold: the feature is effectively
        // enforceable RIGHT NOW (enabled + required + usable mail + valid
        // transport-test proof — the temporary fail-safe for obligated users
        // during an outage), the user is not an exempt admin, the address is
        // still unverified, AND this specific account was registered under an
        // effectively-required policy (immutable per-user marker — accounts
        // from disabled/optional/fail-safe intervals are never retroactively
        // locked out, regardless of later toggles or proof recovery).
        if (
            $user === null
            || $user->email_verified_at !== null
            || $user->is_admin
            || ! $verification->isRequiredOnRegister()
            || ! $user->email_verification_required_at_registration
        ) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'ابتدا آدرس ایمیل خود را تایید کنید.',
                'code' => 'email_unverified',
            ], 403);
        }

        // Preserve the intended destination (same-app URL of THIS request —
        // never attacker-controlled input, so no open redirect is possible).
        if ($request->isMethod('GET')) {
            session(['url.intended' => $request->fullUrl()]);
        }

        return redirect()->route('verification.notice');
    }
}
