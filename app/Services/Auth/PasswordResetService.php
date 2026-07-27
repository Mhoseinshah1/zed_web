<?php

namespace App\Services\Auth;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Services\Sms\SmsService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Self-service OTP password reset — the complete server-side state machine.
 *
 * NON-ENUMERATION CONTRACT: every public-facing method is NEVER-THROW and
 * its outcome shape is identical for existing, nonexistent, ineligible and
 * delivery-broken accounts. The submitted identifier is never logged, never
 * placed in a URL, and never used as a cache key.
 *
 * PURPOSE SCOPE: only rows of the dedicated password_reset_challenges table
 * participate. Contact-verification OTPs can never reset a password and
 * reset OTPs can never verify contact information.
 *
 * STATE MACHINE per challenge:
 *   issued(code_hash, expires 10m, ≤5 attempts)
 *     → authorized (OTP verified: bound to the guest session id hash, the
 *       account's current password-hash fingerprint, and a 10m authorization
 *       expiry)
 *     → consumed exactly once by the atomic finalize() transaction, which
 *       locks the user + challenge rows, revalidates every binding, updates
 *       the password, rotates remember_token (revoking remember-me), and
 *       supersedes every other active challenge for the user. The password
 *       hash itself is the account's credential version: the framework
 *       AuthenticateSession middleware rejects every session stamped with
 *       the previous hash, so all other authenticated sessions die.
 */
class PasswordResetService
{
    /** Session key carrying the opaque challenge token (never in a URL). */
    public const SESSION_TOKEN_KEY = 'pwreset_token';

    /** Session key carrying the post-OTP reset-authorization proof. */
    public const SESSION_PROOF_KEY = 'pwreset_proof';

    public const CODE_TTL_MINUTES = 10;

    public const AUTHORIZATION_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Precomputed bcrypt of a random throwaway value: verification against a
     * missing/dead challenge burns the same hashing cost as a real check, so
     * response timing does not reveal whether the session's token matches a
     * live challenge. Matches the login-timing-equalizer convention.
     */
    private const DUMMY_HASH = '$2y$12$8xfL8iYD4SU4qLh8sP7OAOYv9Yy7sVYIfO9gzVvIIylwHjQdjwMyi';

    public function __construct(
        private readonly EmailVerificationService $email,
        private readonly SmsService $sms,
    ) {}

    /**
     * Handle a reset request for a submitted identifier (email or phone).
     * ALWAYS returns an opaque session token — a real one when an eligible
     * account with a usable channel exists, a decoy otherwise — so the
     * follow-up pages behave identically either way. Never throws.
     */
    public function request(string $identifier): string
    {
        try {
            [$user, $channel, $destination] = $this->resolveAccount($identifier);

            if ($user !== null && $channel !== null && $destination !== null) {
                return $this->issueChallenge($user, $channel, $destination);
            }
        } catch (\Throwable $e) {
            $this->safeLog('request', 'request_error', null, $e);
        }

        // Decoy token: structurally identical, matches no challenge row.
        return bin2hex(random_bytes(32));
    }

    /**
     * Verify the submitted OTP against the session's challenge and — on
     * success — create the short-lived reset authorization: a fresh random
     * PROOF whose hash is stored server-side on the challenge while the
     * plaintext lives ONLY in the verifying guest's session. finalize() must
     * present it again, so only the session that passed OTP verification can
     * complete the reset — a token leaked from any other place is useless.
     * Returns the proof, or null on failure. Never throws.
     */
    public function verifyCode(?string $token, string $code): ?string
    {
        try {
            $challenge = is_string($token) && $token !== ''
                ? PasswordResetChallenge::where('token', $token)->first()
                : null;

            if ($challenge === null
                || $challenge->consumed_at !== null
                || $challenge->authorized_at !== null
                || $challenge->expires_at->isPast()
                || $challenge->attempts >= self::MAX_ATTEMPTS) {
                Hash::check($code, self::DUMMY_HASH); // uniform timing

                return null;
            }

            $challenge->increment('attempts');

            if (! Hash::check($code, $challenge->code_hash)) {
                return null;
            }

            $user = $challenge->user;
            if ($user === null) {
                return null;
            }

            $proof = bin2hex(random_bytes(32));

            $challenge->forceFill([
                'authorized_at' => now(),
                'authorization_expires_at' => now()->addMinutes(self::AUTHORIZATION_TTL_MINUTES),
                'auth_session_hash' => hash('sha256', $proof),
                'password_fingerprint' => hash('sha256', (string) $user->password),
            ])->save();

            return $proof;
        } catch (\Throwable $e) {
            $this->safeLog('verify', 'verify_error', $token, $e);

            return null;
        }
    }

    /**
     * Atomically finalize the reset. Locks the challenge AND user rows,
     * revalidates authorization/expiry/session-binding/password-fingerprint
     * under the lock, then in one transaction: updates the password, rotates
     * remember_token, consumes the authorization exactly once, and
     * supersedes every other active challenge for the user. Exactly one of
     * two concurrent submissions can win. Never throws.
     */
    public function finalize(?string $token, ?string $proof, string $newPassword): bool
    {
        if (! is_string($token) || $token === '' || ! is_string($proof) || $proof === '') {
            return false;
        }

        try {
            return DB::transaction(function () use ($token, $proof, $newPassword): bool {
                $challenge = PasswordResetChallenge::where('token', $token)->lockForUpdate()->first();

                if ($challenge === null
                    || $challenge->authorized_at === null
                    || $challenge->consumed_at !== null
                    || $challenge->authorization_expires_at === null
                    || $challenge->authorization_expires_at->isPast()
                    || ! hash_equals((string) $challenge->auth_session_hash, hash('sha256', $proof))) {
                    return false;
                }

                $user = User::whereKey($challenge->user_id)->lockForUpdate()->first();

                // The account state must be EXACTLY what was authorized: a
                // password change since authorization (including a concurrent
                // winning reset) invalidates this authorization.
                if ($user === null
                    || ! hash_equals((string) $challenge->password_fingerprint, hash('sha256', (string) $user->password))) {
                    return false;
                }

                $user->forceFill([
                    'password' => Hash::make($newPassword),
                    'remember_token' => Str::random(60),
                ])->save();

                $challenge->forceFill(['consumed_at' => now()])->save();

                // No other outstanding challenge may survive the reset.
                PasswordResetChallenge::where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                $this->safeLog('finalize', 'password_reset_committed', $token, null);

                return true;
            });
        } catch (\Throwable $e) {
            $this->safeLog('finalize', 'finalize_error', $token, $e);

            return false;
        }
    }

    /**
     * Resolve identifier → [user, channel, destination]. Email wins when the
     * value contains '@'; otherwise the normalized Iranian mobile number is
     * matched. A channel is usable ONLY when its transport is configured —
     * an ineligible result is indistinguishable from a nonexistent account.
     *
     * @return array{0:?User,1:?string,2:?string}
     */
    private function resolveAccount(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 255) {
            return [null, null, null];
        }

        if (str_contains($identifier, '@')) {
            $email = strtolower($identifier);
            $user = User::whereRaw('lower(email) = ?', [$email])->first();

            return ($user !== null && $this->email->isMailConfigured())
                ? [$user, PasswordResetChallenge::CHANNEL_EMAIL, (string) $user->email]
                : [null, null, null];
        }

        $normalized = PhoneNumber::normalize($identifier);
        if ($normalized === null) {
            return [null, null, null];
        }
        $user = User::where('normalized_phone', $normalized)->first();

        return ($user !== null && $this->sms->isConfigured())
            ? [$user, PasswordResetChallenge::CHANNEL_SMS, $normalized]
            : [null, null, null];
    }

    /** Issue a fresh challenge (superseding actives) and queue delivery. */
    private function issueChallenge(User $user, string $channel, string $destination): string
    {
        // Only one live challenge per account.
        PasswordResetChallenge::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $token = bin2hex(random_bytes(32));

        $challenge = PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => $channel,
            'token' => $token,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'attempts' => 0,
            'send_status' => PasswordResetChallenge::SEND_STATUS_PENDING,
        ]);

        // Honest delivery state: queue publication failure is recorded as
        // dispatch_failed (no transport attempt ever happened) and the
        // public response stays generic. `queued` is stamped BEFORE the
        // dispatch call because a sync queue executes the job inline — the
        // job's own sent/failed result must not be overwritten afterwards.
        $challenge->update(['send_status' => PasswordResetChallenge::SEND_STATUS_QUEUED]);
        try {
            SendPasswordResetOtpJob::dispatch($challenge->id, $channel, $destination, $code);
        } catch (\Throwable $e) {
            $challenge->update(['send_status' => PasswordResetChallenge::SEND_STATUS_DISPATCH_FAILED]);
            $this->safeLog('dispatch', 'publication_failed', $token, $e);
        }

        return $token;
    }

    /**
     * Positive-listed security log: stage, safe reason code, short challenge
     * fingerprint, exception class basename. Never identifiers, codes,
     * passwords, session ids, or raw exception messages.
     */
    private function safeLog(string $stage, string $reason, ?string $token, ?\Throwable $e): void
    {
        try {
            Log::warning('[password-reset] '.$stage, [
                'stage' => $stage,
                'reason' => $reason,
                'challenge' => $token !== null ? substr(hash('sha256', $token), 0, 12) : null,
                'exception' => $e !== null ? class_basename($e) : null,
            ]);
        } catch (\Throwable) {
            // Logging must never break the flow.
        }
    }
}
