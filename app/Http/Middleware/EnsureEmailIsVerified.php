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

        if (
            $user === null
            || $user->email_verified_at !== null
            || $user->is_admin
            || ! $verification->isRequiredOnRegister()
            // Accounts created while verification was disabled/optional
            // registered under a policy that never asked them to verify —
            // enabling "required" later must not retroactively lock them out.
            || $verification->isGrandfathered($user)
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
