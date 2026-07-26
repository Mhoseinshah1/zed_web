<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel-wide MFA gate (registered AFTER Filament's Authenticate, and as
 * Livewire-persistent so /livewire/update re-runs it for every component
 * round-trip).
 *
 * An authenticated session alone is NOT admin access: it must also carry the
 * server-side MFA marker bound to this user and the CURRENT credential
 * version. This closes every side door — an admin who authenticated through
 * the customer login, a session predating an MFA reset, a marker for a
 * different user, or an unconfirmed/corrupt credential (which can never
 * validate, so it forces the challenge instead of bypassing it).
 */
class EnsureAdminMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! AdminMfaSession::markerValid($user)) {
            AdminSecurityAudit::record('panel_access_without_mfa', $user, 'denied');

            if ($request->headers->has('X-Livewire') || $request->expectsJson()) {
                abort(403);
            }

            return redirect()->route('zed-admin.mfa.challenge');
        }

        return $next($request);
    }
}
