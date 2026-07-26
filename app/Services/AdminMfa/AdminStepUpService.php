<?php

namespace App\Services\AdminMfa;

use App\Models\User;

/**
 * Sensitive-settings step-up authorization — scope
 * `admin_sensitive_communications` (email + SMS communication settings).
 *
 * A grant is issued ONLY for a fresh live TOTP code (never a recovery code,
 * never in a session entered via recovery) consumed through the same atomic
 * time-step engine as login — so the login code can never be replayed here,
 * and two tabs racing the same code get one winner. The grant lives purely in
 * the server session, bound to admin id + session id + credential version +
 * password hash + scope + issue/expiry times + the consumed step; hidden form
 * fields, query params, cookies, or Livewire properties are never trusted as
 * proof. Every sensitive action must call assertGranted() again immediately
 * before executing — page state is presentation, not authorization.
 */
final class AdminStepUpService
{
    public const SCOPE = 'admin_sensitive_communications';

    public const GRANT_KEY = 'zp_admin_step_up';

    /** Maximum grant lifetime (spec: at most five minutes). */
    public const LIFETIME_MINUTES = 5;

    public function __construct(private readonly AdminTotpService $totp) {}

    /**
     * Attempt to unlock the scope with a fresh live TOTP code. Returns true
     * and stores the grant, or false with nothing changed.
     */
    public function attemptStepUp(User $user, string $code): bool
    {
        if ($user->is_admin !== true || ! AdminMfaSession::markerValid($user)) {
            AdminSecurityAudit::record('step_up', $user, 'failure', ['reason' => 'session_not_mfa_verified', 'scope' => self::SCOPE]);

            return false;
        }

        // A session entered with a recovery code has no proven live
        // authenticator; step-up explicitly requires one.
        if (AdminMfaSession::enteredViaRecovery()) {
            AdminSecurityAudit::record('step_up', $user, 'failure', ['reason' => 'recovery_session', 'scope' => self::SCOPE]);

            return false;
        }

        // Atomic consume: strictly newer than every previously accepted step,
        // including the login step — the login code can never be reused here.
        $step = $this->totp->verifyAndConsume($user, $code);
        if ($step === null) {
            AdminSecurityAudit::record('step_up', $user, 'failure', ['reason' => 'invalid_code', 'scope' => self::SCOPE]);

            return false;
        }

        $cred = $this->totp->credentialFor($user);

        session()->put(self::GRANT_KEY, [
            'user_id' => $user->id,
            'session_id' => session()->getId(),
            'version' => $cred?->version() ?? '',
            'password_hash' => (string) $user->getAuthPassword(),
            'scope' => self::SCOPE,
            'step' => $step,
            'issued_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
        ]);

        AdminSecurityAudit::record('step_up', $user, 'success', ['scope' => self::SCOPE]);

        return true;
    }

    /**
     * Authoritative server-side check. Every binding is re-validated on every
     * call; any mismatch (expiry, logout/session change, password change,
     * privilege loss, credential reset/replacement, recovery entry) clears
     * the grant and fails closed.
     */
    public function hasActiveGrant(User $user): bool
    {
        $grant = session()->get(self::GRANT_KEY);
        if (! is_array($grant)) {
            return false;
        }

        $valid = ($grant['scope'] ?? null) === self::SCOPE
            && (int) ($grant['user_id'] ?? 0) === (int) $user->id
            && hash_equals((string) ($grant['session_id'] ?? ''), (string) session()->getId())
            && (int) ($grant['expires_at'] ?? 0) >= now()->getTimestamp()
            && $user->is_admin === true
            && hash_equals((string) ($grant['password_hash'] ?? ''), (string) $user->getAuthPassword())
            && ! AdminMfaSession::enteredViaRecovery()
            && AdminMfaSession::markerValid($user);

        if ($valid) {
            $version = $this->totp->credentialFor($user)?->version() ?? '';
            $valid = $version !== '' && hash_equals($version, (string) ($grant['version'] ?? ''));
        }

        if (! $valid) {
            self::clearGrant();

            return false;
        }

        return true;
    }

    /** Seconds of validity left (0 when locked). */
    public function remainingSeconds(User $user): int
    {
        if (! $this->hasActiveGrant($user)) {
            return 0;
        }

        return max(0, (int) session()->get(self::GRANT_KEY)['expires_at'] - now()->getTimestamp());
    }

    /** Explicit "lock sensitive settings now". */
    public static function clearGrant(): void
    {
        session()->forget(self::GRANT_KEY);
    }
}
