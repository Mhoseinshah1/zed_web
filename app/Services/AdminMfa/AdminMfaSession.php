<?php

namespace App\Services\AdminMfa;

use App\Models\AdminTwoFactorCredential;
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

    public const ENROLLMENT_COMPLETION_KEY = 'zp_admin_mfa_enrollment_completion';

    public const REPLACEMENT_KEY = 'zp_admin_totp_replacement';

    /** Pending-login window: password proof goes stale after this. */
    public const PENDING_TTL_MINUTES = 10;

    /** Window between confirming enrollment and acknowledging the codes. */
    public const ENROLLMENT_COMPLETION_TTL_MINUTES = 5;

    /** Window for confirming an authenticator replacement on the new device. */
    public const REPLACEMENT_TTL_MINUTES = 10;

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

    public static function clearPending(): void
    {
        session()->forget(self::PENDING_KEY);
    }

    // ── Enrollment completion (confirmEnrollment → acknowledge hand-off) ─────

    /**
     * One-time completion record created EXCLUSIVELY after a successful
     * confirmEnrollment(). It is the sole authorization for the recovery-code
     * acknowledgement to finish login: bound to the subject, THIS session id,
     * the just-promoted credential version, the consumed confirmation
     * time-step, and a short expiry. Nothing else — in particular not a bare
     * authenticated session — may complete the acknowledge step.
     */
    public static function putEnrollmentCompletion(User $user, AdminTwoFactorCredential $cred): void
    {
        session()->put(self::ENROLLMENT_COMPLETION_KEY, [
            'user_id' => $user->id,
            'session_id' => session()->getId(),
            'version' => $cred->version(),
            'step' => (int) $cred->last_verified_timestep,
            'expires_at' => now()->addMinutes(self::ENROLLMENT_COMPLETION_TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * Consume the completion record: it is removed from the session BEFORE
     * validation, so it can satisfy at most one acknowledgement ever. Returns
     * the confirmed time-step, or null when the record is missing, expired,
     * bound to another user or session, or bound to a credential version that
     * is no longer the current one (reset/replaced since confirmation).
     */
    public static function consumeEnrollmentCompletion(User $user): ?int
    {
        $record = session()->get(self::ENROLLMENT_COMPLETION_KEY);
        session()->forget(self::ENROLLMENT_COMPLETION_KEY);

        if (! is_array($record)
            || (int) ($record['user_id'] ?? 0) !== (int) $user->id
            || ! hash_equals((string) ($record['session_id'] ?? ''), (string) session()->getId())
            || (int) ($record['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $version = app(AdminTotpService::class)->credentialFor($user)?->version() ?? '';
        if ($version === '' || ! hash_equals($version, (string) ($record['version'] ?? ''))) {
            return null;
        }

        $step = $record['step'] ?? null;

        return is_int($step) ? $step : null;
    }

    public static function clearEnrollmentCompletion(): void
    {
        session()->forget(self::ENROLLMENT_COMPLETION_KEY);
    }

    // ── Authenticator replacement (reauth → confirm-on-new-device hand-off) ──

    /**
     * Server-side authorization for confirming an authenticator replacement.
     * Created only after the current password AND a fresh live code were
     * verified; bound to the subject, THIS session id, the version of the
     * factor being replaced, a digest of the pending secret's ciphertext, and
     * a short expiry. Livewire component state never authorizes anything.
     */
    public static function startReplacement(User $user, AdminTwoFactorCredential $cred): void
    {
        session()->put(self::REPLACEMENT_KEY, [
            'user_id' => $user->id,
            'session_id' => session()->getId(),
            'version' => $cred->version(),
            'pending_digest' => $cred->pendingVersion(),
            'expires_at' => now()->addMinutes(self::REPLACEMENT_TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The replacement record is valid for THIS user, THIS session, the SAME
     * active factor it was issued against, and the SAME pending secret. Any
     * mismatch or expiry clears the record and fails closed. (Peek only — the
     * caller consumes via clearReplacement() after the new factor confirms,
     * so a mistyped code on the new device stays retryable in the window.)
     */
    public static function replacementValid(User $user): bool
    {
        $record = session()->get(self::REPLACEMENT_KEY);
        if (! is_array($record)) {
            return false;
        }

        $cred = app(AdminTotpService::class)->credentialFor($user);

        $valid = (int) ($record['user_id'] ?? 0) === (int) $user->id
            && hash_equals((string) ($record['session_id'] ?? ''), (string) session()->getId())
            && (int) ($record['expires_at'] ?? 0) >= now()->getTimestamp()
            && $cred !== null
            && $cred->version() !== ''
            && hash_equals($cred->version(), (string) ($record['version'] ?? ''))
            && $cred->pendingVersion() !== ''
            && hash_equals($cred->pendingVersion(), (string) ($record['pending_digest'] ?? ''));

        if (! $valid) {
            self::clearReplacement();

            return false;
        }

        return true;
    }

    public static function clearReplacement(): void
    {
        session()->forget(self::REPLACEMENT_KEY);
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
        self::clearEnrollmentCompletion();
        self::clearReplacement();
        self::clearMarker();
        AdminStepUpService::clearGrant();
    }
}
