<?php

namespace App\Services\AdminMfa;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Server-side session state for the two-phase administrator login.
 *
 * PENDING state (password accepted, MFA outstanding): holds ONLY the minimum
 * non-secret facts — subject user id, remember flag, expiry, and (during
 * enrollment hand-off) the already-consumed confirmation time-step. It never
 * contains secrets and never authorizes any Filament page or Livewire action:
 * while pending, the user is simply NOT authenticated.
 *
 * VERIFIED marker (MFA completed): binds the authenticated session to the
 * admin id AND the credential version (confirmed_at). Any TOTP reset or
 * replacement re-stamps the version, so markers issued against the old factor
 * stop validating everywhere, immediately. Client-side state is never
 * trusted — both structures live exclusively in the server session.
 */
final class AdminMfaSession
{
    public const PENDING_KEY = 'zp_admin_mfa_pending';

    public const MARKER_KEY = 'zp_admin_mfa';

    /** Pending-login window: password proof goes stale after this. */
    public const PENDING_TTL_MINUTES = 10;

    // ── Pending (password OK, MFA outstanding) ───────────────────────────────

    public static function startPending(User $user, bool $remember): void
    {
        session()->put(self::PENDING_KEY, [
            'user_id' => $user->id,
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::PENDING_TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /** The pending subject, or null (expired/invalid state is cleared). */
    public static function pendingUser(): ?User
    {
        $state = session()->get(self::PENDING_KEY);
        if (! is_array($state) || ! isset($state['user_id'], $state['expires_at'])) {
            return null;
        }

        if ((int) $state['expires_at'] < now()->getTimestamp()) {
            self::clearPending();

            return null;
        }

        $user = User::find((int) $state['user_id']);
        if ($user === null || $user->is_admin !== true) {
            self::clearPending();

            return null;
        }

        return $user;
    }

    public static function pendingRemember(): bool
    {
        return (bool) (session()->get(self::PENDING_KEY)['remember'] ?? false);
    }

    /** Record the enrollment-confirmation step for the acknowledge hand-off. */
    public static function putPendingConfirmedStep(int $step): void
    {
        $state = session()->get(self::PENDING_KEY);
        if (is_array($state)) {
            $state['confirmed_step'] = $step;
            session()->put(self::PENDING_KEY, $state);
        }
    }

    public static function pendingConfirmedStep(): ?int
    {
        $step = session()->get(self::PENDING_KEY)['confirmed_step'] ?? null;

        return is_int($step) ? $step : null;
    }

    public static function clearPending(): void
    {
        session()->forget(self::PENDING_KEY);
    }

    // ── Verified marker (MFA completed for THIS session) ─────────────────────

    /**
     * @param  'totp'|'recovery'  $via
     */
    public static function markVerified(User $user, ?int $step, string $via): void
    {
        $cred = app(AdminTotpService::class)->credentialFor($user);

        session()->put(self::MARKER_KEY, [
            'user_id' => $user->id,
            'version' => $cred?->version() ?? '',
            'step' => $step,
            'via' => $via,
            'verified_at' => now()->getTimestamp(),
        ]);
    }

    /**
     * Session marker is valid for THIS user and the CURRENT credential
     * version. Missing/foreign/stale markers, unconfirmed or corrupt
     * credentials all fail closed.
     */
    public static function markerValid(User $user): bool
    {
        $marker = session()->get(self::MARKER_KEY);
        if (! is_array($marker) || (int) ($marker['user_id'] ?? 0) !== (int) $user->id) {
            return false;
        }

        $totp = app(AdminTotpService::class);
        if (! $totp->hasConfirmedCredential($user)) {
            return false;
        }

        try {
            $version = $totp->credentialFor($user)?->version();
        } catch (DecryptException) {
            return false;
        }

        return is_string($version) && $version !== ''
            && hash_equals($version, (string) ($marker['version'] ?? ''));
    }

    /** The session entered via a recovery code (blocks step-up issuance). */
    public static function enteredViaRecovery(): bool
    {
        return (session()->get(self::MARKER_KEY)['via'] ?? null) === 'recovery';
    }

    /** The TOTP time-step consumed at login (step-up must beat it). */
    public static function loginStep(): ?int
    {
        $step = session()->get(self::MARKER_KEY)['step'] ?? null;

        return is_int($step) ? $step : null;
    }

    public static function clearMarker(): void
    {
        session()->forget(self::MARKER_KEY);
    }

    public static function clearAll(): void
    {
        self::clearPending();
        self::clearMarker();
        AdminStepUpService::clearGrant();
    }
}
