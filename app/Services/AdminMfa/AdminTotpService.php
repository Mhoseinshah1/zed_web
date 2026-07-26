<?php

namespace App\Services\AdminMfa;

use App\Models\AdminTwoFactorCredential;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * All administrator TOTP (RFC 6238) operations: enrollment, login/step-up
 * verification with atomic replay prevention, recovery codes, and secret
 * replacement.
 *
 * Replay model: every accepted code maps to a 30-second time-step; the row
 * stores the last CONSUMED step and a code is accepted only when its step is
 * strictly newer, updated inside a transaction holding a row lock — parallel
 * submissions of the same code have exactly one winner, and the login step can
 * never be replayed for the sensitive-settings step-up. Drift tolerance is
 * exactly ±1 step (DRIFT_WINDOW); stale, future, malformed, and replayed codes
 * all fail. Corrupt/undecryptable secrets fail CLOSED (verification refuses;
 * recovery is the emergency CLI reset), never open.
 */
class AdminTotpService
{
    /** otpauth:// issuer shown in the authenticator app. */
    public const ISSUER = 'ZedProxy Admin';

    /** Accepted clock drift: ±1 time-step (30 seconds) around "now". */
    public const DRIFT_WINDOW = 1;

    /** Base32 secret length (=160 bits of entropy). */
    public const SECRET_LENGTH = 32;

    /** A pending (unconfirmed) secret older than this is abandoned. */
    public const PENDING_TTL_MINUTES = 30;

    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $engine) {}

    // ── Lookup ───────────────────────────────────────────────────────────────

    public function credentialFor(User $user): ?AdminTwoFactorCredential
    {
        return AdminTwoFactorCredential::query()->where('user_id', $user->id)->first();
    }

    /** True only for a CONFIRMED factor with a decryptable active secret. */
    public function hasConfirmedCredential(User $user): bool
    {
        $cred = $this->credentialFor($user);
        if ($cred === null || ! $cred->isConfirmed()) {
            return false;
        }

        try {
            return is_string($cred->secret) && $cred->secret !== '';
        } catch (DecryptException) {
            // Fail closed: corrupt data means the factor is UNUSABLE, never
            // that MFA is skipped.
            return false;
        }
    }

    // ── Enrollment / replacement ─────────────────────────────────────────────

    /**
     * Start (or resume a fresh, unexpired) enrollment or secret replacement.
     * Generates a CSPRNG-backed pending secret; the currently confirmed
     * factor — if any — stays fully active until the new one is confirmed.
     *
     * @return array{secret: string, otpauth: string}
     */
    public function startEnrollment(User $user): array
    {
        if ($user->is_admin !== true) {
            throw new \RuntimeException('Only administrators may enroll admin MFA.');
        }

        return DB::transaction(function () use ($user): array {
            $cred = AdminTwoFactorCredential::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cred === null) {
                $cred = new AdminTwoFactorCredential;
                $cred->forceFill(['user_id' => $user->id]);
            }

            $pending = null;
            if ($cred->exists && $cred->pending_secret_generated_at !== null
                && $cred->pending_secret_generated_at->gt(now()->subMinutes(self::PENDING_TTL_MINUTES))) {
                try {
                    $pending = $cred->pending_secret;
                } catch (DecryptException) {
                    $pending = null; // corrupt pending → rotate
                }
            }

            if (! is_string($pending) || $pending === '') {
                // Google2FA::generateSecretKey() draws from random_bytes().
                $pending = $this->engine->generateSecretKey(self::SECRET_LENGTH);
                $cred->forceFill([
                    'pending_secret' => $pending,
                    'pending_secret_generated_at' => now(),
                ]);
            }

            $cred->save();

            return [
                'secret' => $pending,
                'otpauth' => $this->provisioningUri($user, $pending),
            ];
        });
    }

    /**
     * Standard otpauth:// URI, rendered as a QR code LOCALLY only. Account is
     * the login username — no email/phone/personal data leaks into the label.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return 'otpauth://totp/'
            .rawurlencode(self::ISSUER).':'.rawurlencode((string) $user->username)
            .'?secret='.$secret
            .'&issuer='.rawurlencode(self::ISSUER)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Confirm the pending secret with a live code. Promotes it to the active
     * factor, consumes the code's time-step (it can NOT be replayed for
     * login), re-stamps confirmed_at (= new credential version → every
     * session marker / step-up grant bound to the old factor dies), and
     * issues a fresh one-time-displayed recovery-code set.
     *
     * @return array{codes: list<string>}|null null = wrong/expired code, nothing changed
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        return DB::transaction(function () use ($user, $code): ?array {
            $cred = AdminTwoFactorCredential::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cred === null || $cred->pending_secret_generated_at === null
                || $cred->pending_secret_generated_at->lte(now()->subMinutes(self::PENDING_TTL_MINUTES))) {
                return null; // nothing pending / abandoned → restart enrollment
            }

            try {
                $pending = $cred->pending_secret;
            } catch (DecryptException) {
                return null;
            }
            if (! is_string($pending) || $pending === '') {
                return null;
            }

            $step = $this->matchCode($pending, $code, null);
            if ($step === null) {
                return null;
            }

            $plainCodes = $this->newRecoveryCodes();

            $cred->forceFill([
                'secret' => $pending,
                'pending_secret' => null,
                'pending_secret_generated_at' => null,
                'confirmed_at' => now(),
                // The confirmation code is CONSUMED: the very next login needs
                // a strictly newer step.
                'last_verified_timestep' => $step,
                'recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plainCodes),
                'recovery_codes_generated_at' => now(),
            ])->save();

            return ['codes' => $plainCodes];
        });
    }

    // ── Verification (atomic consume) ────────────────────────────────────────

    /**
     * Verify a live TOTP code against the ACTIVE factor and atomically consume
     * its time-step. Returns the consumed step, or null for wrong, malformed,
     * stale, future, replayed, unconfirmed, or corrupt input — with no state
     * change in any failure case.
     */
    public function verifyAndConsume(User $user, string $code): ?int
    {
        return DB::transaction(function () use ($user, $code): ?int {
            $cred = AdminTwoFactorCredential::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cred === null || ! $cred->isConfirmed()) {
                return null;
            }

            try {
                $secret = $cred->secret;
            } catch (DecryptException) {
                AdminSecurityAudit::record('mfa_credential_corrupt', $user, 'failure');

                return null; // fail closed
            }
            if (! is_string($secret) || $secret === '') {
                return null;
            }

            $step = $this->matchCode($secret, $code, $cred->last_verified_timestep);
            if ($step === null) {
                return null;
            }

            $cred->forceFill(['last_verified_timestep' => $step])->save();

            return $step;
        });
    }

    /**
     * Match a 6-digit code inside the drift window, strictly newer than the
     * given consumed step. Pure check — persistence is the caller's job.
     */
    private function matchCode(string $secret, string $code, ?int $lastStep): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        try {
            // ALWAYS pass a numeric floor: with a null oldTimestamp google2fa
            // returns bool(true) instead of the matched time-step, which
            // would corrupt the consumed-step bookkeeping (and permit
            // replay). 0 is unreachably old, so the very first consumption
            // still records the real step.
            $step = $this->engine->verifyKeyNewer($secret, $code, $lastStep ?? 0, self::DRIFT_WINDOW);
        } catch (\Throwable) {
            return null; // malformed secret/code → never a bypass
        }

        return $step === false ? null : (int) $step;
    }

    /**
     * Abandon an unconfirmed pending secret (replacement cancelled or its
     * server-side record invalidated). The confirmed factor is untouched.
     */
    public function abandonPendingSecret(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $cred = AdminTwoFactorCredential::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cred === null) {
                return;
            }

            $cred->forceFill([
                'pending_secret' => null,
                'pending_secret_generated_at' => null,
            ])->save();
        });
    }

    // ── Recovery codes ───────────────────────────────────────────────────────

    /**
     * Atomically consume one recovery code (bcrypt-checked, removed from the
     * stored set under the row lock — a code can never be spent twice, and
     * parallel submissions have one winner). Login only; NEVER step-up.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 40) {
            return false;
        }

        return (bool) DB::transaction(function () use ($user, $code): bool {
            $cred = AdminTwoFactorCredential::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cred === null || ! $cred->isConfirmed()) {
                return false;
            }

            try {
                $hashes = $cred->recovery_codes;
            } catch (DecryptException) {
                return false;
            }
            if (! is_array($hashes) || $hashes === []) {
                return false;
            }

            foreach ($hashes as $i => $hash) {
                if (is_string($hash) && Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $cred->forceFill(['recovery_codes' => array_values($hashes)])->save();

                    return true;
                }
            }

            return false;
        });
    }

    /** Remaining single-use recovery codes (0 when unreadable — fail closed). */
    public function recoveryCodesRemaining(User $user): int
    {
        $cred = $this->credentialFor($user);
        if ($cred === null) {
            return 0;
        }

        try {
            $hashes = $cred->recovery_codes;
        } catch (DecryptException) {
            return 0;
        }

        return is_array($hashes) ? count($hashes) : 0;
    }

    /**
     * Replace the whole recovery-code set. Plaintext is returned exactly once
     * for display; storage keeps only bcrypt hashes.
     *
     * @return list<string>|null
     */
    public function regenerateRecoveryCodes(User $user): ?array
    {
        return DB::transaction(function () use ($user): ?array {
            $cred = AdminTwoFactorCredential::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cred === null || ! $cred->isConfirmed()) {
                return null;
            }

            $plainCodes = $this->newRecoveryCodes();
            $cred->forceFill([
                'recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plainCodes),
                'recovery_codes_generated_at' => now(),
            ])->save();

            return $plainCodes;
        });
    }

    /**
     * High-entropy single-use codes: 3×4 crockford-ish groups, 60 bits each,
     * from random_int (CSPRNG).
     *
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // no 0/O/1/I/L
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $groups = [];
            for ($g = 0; $g < 3; $g++) {
                $chunk = '';
                for ($c = 0; $c < 4; $c++) {
                    $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $groups[] = $chunk;
            }
            $codes[] = implode('-', $groups);
        }

        return $codes;
    }

    // ── Reset (emergency CLI only) ───────────────────────────────────────────

    /** Drop the credential entirely: next login forces fresh enrollment. */
    public function resetFor(User $user): void
    {
        AdminTwoFactorCredential::query()->where('user_id', $user->id)->delete();
    }
}
