<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE authoritative session-revocation check: every authenticated request
 * verifies the session's stamped authentication version against the user
 * row's monotonic `auth_version`.
 *
 *  - Stamp source: the Login-event listener stamps the current version on
 *    every successful login (normal, registration auto-login, remember-me,
 *    Filament panel — all fire the same guard event).
 *  - COMPATIBILITY: a session with NO stamp (created before this feature
 *    deployed) is adopted lazily while the account is still on the INITIAL
 *    version. Once the version has advanced (e.g. a password reset), an
 *    unstamped session FAILS CLOSED — the pre-deployment bypass where an
 *    old session could adopt the post-reset state is closed.
 *  - A mismatched or fail-closed session is logged out, invalidated, and
 *    its CSRF token regenerated.
 *
 * The framework AuthenticateSession (password-hash binding) stays as a
 * SECOND layer: it also revokes sessions for trusted password-change paths
 * that predate auth_version (e.g. direct admin password edits) without
 * requiring every legacy writer to know about versioning.
 */
class EnsureSessionAuthVersion
{
    /** Session key carrying the stamped authentication version. */
    public const SESSION_KEY = 'zp_auth_version';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $request->hasSession()) {
            $current = (int) ($user->auth_version ?? User::INITIAL_AUTH_VERSION);
            $stamped = $request->session()->get(self::SESSION_KEY);

            if ($stamped === null) {
                if ($current === User::INITIAL_AUTH_VERSION) {
                    // Pre-deployment session, account never rotated: adopt.
                    $request->session()->put(self::SESSION_KEY, $current);
                } else {
                    return $this->reject($request);
                }
            } elseif ((int) $stamped !== $current) {
                return $this->reject($request);
            }
        }

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect()->route('login');
    }
}
