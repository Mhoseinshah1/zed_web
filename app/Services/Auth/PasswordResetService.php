<?php

namespace App\Services\Auth;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

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
 *   issued(code_hash, expires 10m, ≤5 attempts) — created atomically under a
 *       lock on the user row; a partial unique index enforces at most one
 *       ACTIVE challenge per account at the database level
 *     → authorized (OTP verified under a challenge row lock: bound to the
 *       sha256 of a session-held one-time authorization proof, the account's
 *       current password-hash fingerprint, and a 10m authorization expiry)
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

    /**
     * Fixed-MINIMUM duration of the synchronous request stage (µs). Every
     * syntactically valid request — real, decoy, ineligible, delivery-broken
     *  — runs inside the same Timebox, so the response cannot return faster
     * for one account state than another. This is fixed-minimum timing
     * equalization, NOT formal constant-time execution: an unusually slow
     * real path can still exceed the minimum.
     */
    public const REQUEST_TIMEBOX_MICROSECONDS = 1_000_000;

    public function __construct(
        private readonly EmailVerificationService $email,
        private readonly SmsService $sms,
        private readonly Timebox $timebox,
    ) {}

    /**
     * Handle a reset request for a submitted identifier (email or phone).
     * ALWAYS returns an opaque session token — a real one when an eligible
     * account with a usable channel exists, a decoy otherwise — so the
     * follow-up pages behave identically either way. Never throws.
     */
    public function request(string $identifier): string
    {
        // ONE explicit fixed-minimum timing boundary around the WHOLE
        // account-dependent synchronous work (lookup, hashing, writes, queue
        // publication) — identical for real and decoy outcomes.
        return (string) $this->timebox->call(function () use ($identifier): string {
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
        }, self::REQUEST_TIMEBOX_MICROSECONDS);
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
            // ONE locked transaction owns eligibility, expiry, the attempt
            // budget, the increment, hash verification and authorization
            // creation: concurrent submissions serialize on the row lock, so
            // the 5-submitted-attempts cap cannot be exceeded and only ONE
            // correct concurrent submission can mint the authorization — an
            // already-authorized, consumed, expired or superseded challenge
            // is never authorized again.
            return DB::transaction(function () use ($token, $code): ?string {
                $challenge = is_string($token) && $token !== ''
                    ? PasswordResetChallenge::where('token', $token)->lockForUpdate()->first()
                    : null;

                if ($challenge === null
                    || $challenge->consumed_at !== null
                    || $challenge->authorized_at !== null
                    || $challenge->expires_at->isPast()
                    || $challenge->attempts >= self::MAX_ATTEMPTS) {
                    Hash::check($code, self::DUMMY_HASH); // uniform timing

                    return null;
                }

                $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

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
                    'authorization_proof_hash' => hash('sha256', $proof),
                    'password_fingerprint' => hash('sha256', (string) $user->password),
                ])->save();

                return $proof;
            });
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
                    || ! hash_equals((string) $challenge->authorization_proof_hash, hash('sha256', $proof))) {
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
                    // Advance the monotonic credential version EXACTLY once,
                    // inside this same locked transaction: every session
                    // stamped with the previous version dies on its next
                    // request, and unstamped pre-deployment sessions fail
                    // closed (no longer on the initial version).
                    'auth_version' => ((int) ($user->auth_version ?? User::INITIAL_AUTH_VERSION)) + 1,
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
        // EXACTLY the same canonicalization boundary the rate limiter uses
        // (ResetIdentifier) — equivalent representations of one account can
        // never diverge between lookup identity and limiter identity.
        $canonical = ResetIdentifier::canonicalize($identifier);

        if ($canonical['type'] === 'email') {
            $user = User::whereRaw('lower(email) = ?', [$canonical['canonical']])->first();

            return ($user !== null && $this->email->isMailConfigured())
                ? [$user, PasswordResetChallenge::CHANNEL_EMAIL, (string) $user->email]
                : [null, null, null];
        }

        if ($canonical['type'] === 'phone') {
            $user = User::where('normalized_phone', $canonical['canonical'])->first();

            return ($user !== null && $this->sms->isConfigured())
                ? [$user, PasswordResetChallenge::CHANNEL_SMS, $canonical['canonical']]
                : [null, null, null];
        }

        return [null, null, null];
    }

    /**
     * Issue a fresh challenge atomically and queue delivery AFTER commit.
     * The transaction serializes on the authoritative USER row, supersedes
     * the previous active challenge and creates the replacement — so exactly
     * one active challenge survives, with the partial unique index
     * (password_reset_one_active) as the database-level authority. A rolled
     * back issuance publishes NO delivery job.
     */
    private function issueChallenge(User $user, string $channel, string $destination): string
    {
        $code = (string) random_int(100000, 999999);
        $token = bin2hex(random_bytes(32));

        $challenge = DB::transaction(function () use ($user, $channel, $code, $token): PasswordResetChallenge {
            User::whereKey($user->id)->lockForUpdate()->first();

            // Only one live challenge per account.
            PasswordResetChallenge::where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return $this->persistChallenge($user, $channel, $code, $token);
        });

        // Publication strictly AFTER the commit. Honest delivery state: a
        // publication failure is recorded as dispatch_failed (no transport
        // attempt ever happened) and the public response stays generic. The
        // `queued` stamp is conditional so an inline (sync-queue) job's own
        // sent/failed result is never overwritten.
        try {
            SendPasswordResetOtpJob::dispatch($challenge->id, $channel, $destination, $code);
            PasswordResetChallenge::whereKey($challenge->id)
                ->where('send_status', PasswordResetChallenge::SEND_STATUS_PENDING)
                ->update(['send_status' => PasswordResetChallenge::SEND_STATUS_QUEUED]);
        } catch (\Throwable $e) {
            PasswordResetChallenge::whereKey($challenge->id)
                ->update(['send_status' => PasswordResetChallenge::SEND_STATUS_DISPATCH_FAILED]);
            $this->safeLog('dispatch', 'publication_failed', $token, $e);
        }

        return $token;
    }

    /** Seam: create the challenge row inside the issuance transaction. */
    protected function persistChallenge(User $user, string $channel, string $code, string $token): PasswordResetChallenge
    {
        return PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => $channel,
            'token' => $token,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'attempts' => 0,
            'send_status' => PasswordResetChallenge::SEND_STATUS_PENDING,
        ]);
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
