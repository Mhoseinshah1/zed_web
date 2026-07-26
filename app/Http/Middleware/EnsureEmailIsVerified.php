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

        // Enforce ONLY when all of these hold: the feature is enforceable
        // RIGHT NOW (enabled + usable mail + valid transport-test proof —
        // the temporary fail-safe for obligated users during an outage), the
        // user is not an exempt admin, the address is still unverified, AND
        // this specific account CARRIES the obligation (per-user marker —
        // stamped at registration under an effectively-required policy, or
        // imposed by an explicit admin «require_verification»). The
        // registration-wide "required" toggle is deliberately NOT consulted
        // here: it only governs stamping of NEW registrations, so an
        // admin-imposed obligation binds even in optional mode, and accounts
        // from disabled/optional/fail-safe intervals are never retroactively
        // locked out.
        if (
            $user === null
            || $user->email_verified_at !== null
            || $user->is_admin
            || ! $verification->isEnforceableNow()
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

        // Preserve the intended destination as a RELATIVE target only: an
        // absolute URL would embed the request's Host header, and on a
        // deployment whose proxy accepts arbitrary Host values that is
        // attacker-influenced — redirect()->intended() would then leave the
        // site after verification. A leading-slash path (never `//host`)
        // cannot escape the application.
        if ($request->isMethod('GET')) {
            session(['url.intended' => '/'.ltrim($request->getRequestUri(), '/')]);
        }

        return redirect()->route('verification.notice');
    }
}
