<?php

namespace App\Services\Auth;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Services\Sms\SmsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
 *       locks the user + challenge rows IN THAT ORDER, revalidates every
 *       binding, updates the password, rotates remember_token (revoking
 *       remember-me), advances users.auth_version and supersedes every other
 *       active challenge for the user.
 *
 * SESSION REVOCATION: `users.auth_version` is the AUTHORITATIVE monotonic
 * credential version (EnsureSessionAuthVersion verifies it on every
 * authenticated request). The framework's password-hash session binding is
 * only a SECONDARY compatibility layer covering legacy password writers that
 * do not advance the version.
 *
 * ══ LOCK HIERARCHY (the ONE global order; never reversed) ══════════════
 *
 *   1. per-user CACHE lock   (userLockKey — delivery coordination)
 *   2. `users` row lock      (lockForUpdate)
 *   3. `password_reset_challenges` row lock (lockForUpdate)
 *
 * | path                    | 1 cache | 2 user | 3 challenge |
 * |-------------------------|---------|--------|-------------|
 * | issueChallenge()        |  yes*   |  yes   |     yes     |
 * | verifyCode()            |    —    |  yes   |     yes     |
 * | finalize()              |    —    |  yes   |     yes     |
 * | SendPasswordResetOtpJob |   yes   |   —    |     yes     |
 *
 * (*) PUBLIC issuance takes level 1 NON-BLOCKING: if the lock is held it
 * gives up immediately and the request returns through the same
 * fixed-minimum Timebox, so a real account never takes measurably longer
 * than a decoy. Queue workers may wait briefly (LOCK_WAIT_SECONDS).
 *
 * A path may SKIP a level but never acquire one out of order, so the order
 * is a strict partial order and no cycle (deadlock) is possible. finalize()
 * and verifyCode() reach the challenge's user_id through an UNLOCKED
 * navigation read whose only use is choosing which user row to lock — every
 * security decision is made on the locked, reloaded records.
 *
 * NETWORK I/O is never performed inside a database transaction: the delivery
 * job claims the row in a short transaction, commits, and only then talks to
 * the transport while holding the per-user cache lock.
 */
class PasswordResetService
{
    /** Session key carrying the opaque challenge token (never in a URL). */
    public const SESSION_TOKEN_KEY = 'pwreset_token';

    /** Session key carrying the post-OTP reset-authorization proof. */
    public const SESSION_PROOF_KEY = 'pwreset_proof';

    /**
     * Session key carrying the NON-REVERSIBLE canonical-subject binding of
     * the identifier this flow was started for (an APP_KEY-keyed HMAC — never
     * the raw email address or phone number). It is the only thing that lets
     * a contended resend recognise "same account as before".
     */
    public const SESSION_SUBJECT_KEY = 'pwreset_subject';

    /** A brand-new authoritative challenge was created. */
    public const OUTCOME_ISSUED = 'issued';

    /** Issuance was not possible, but the session's challenge is still valid. */
    public const OUTCOME_PRESERVED = 'preserved';

    /** Nothing usable exists for this session — carry a decoy. */
    public const OUTCOME_DECOY = 'decoy';

    public const CODE_TTL_MINUTES = 10;

    public const AUTHORIZATION_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Bounded wait used by QUEUE WORKERS only: background contention may
     * legitimately block briefly.
     */
    public const LOCK_WAIT_SECONDS = 3;

    /**
     * PUBLIC HTTP issuance never blocks. A real account whose delivery is
     * in flight must not take measurably longer than a nonexistent one, so
     * the request path attempts the lock IMMEDIATELY and — if it is held —
     * returns through the very same fixed-minimum Timebox as every other
     * outcome. (0 = try once, never wait.)
     */
    public const PUBLIC_LOCK_WAIT_SECONDS = 0;

    /**
     * Lease for the ISSUANCE side of the per-user lock. Short: issuance does
     * only local work (supersede + insert) — never network I/O. An abandoned
     * lock therefore self-heals within this bound. The DELIVERY side uses its
     * own, longer lease (SendPasswordResetOtpJob::LOCK_TTL_SECONDS) because it
     * holds the lock across a bounded transport attempt.
     */
    public const LOCK_TTL_SECONDS = 10;

    /**
     * A `sending` claim older than this is ABANDONED (worker crashed or was
     * killed). It is NEVER re-transported — the provider may already have
     * accepted that message — it is retired to the terminal, honest
     * `delivery_unknown` state and the user requests a fresh challenge.
     * Comfortably above the delivery job's own timeout.
     */
    public const ABANDONED_CLAIM_MINUTES = 10;

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
     *
     * ALWAYS returns a usable opaque session token plus a SERVER-INTERNAL
     * outcome — issued (a new challenge), preserved (this session's existing
     * challenge is still valid and must not be clobbered) or decoy (nothing
     * usable) — together with the non-reversible canonical-subject binding to
     * store in the session. The outcome NEVER changes the public response:
     * status, redirect, message, validation shape and timing are identical.
     * Never throws.
     *
     * @return array{token:string, subject:string, outcome:string}
     */
    public function request(string $identifier, ?string $currentToken = null, ?string $currentSubject = null): array
    {
        // ONE explicit fixed-minimum timing boundary around the WHOLE
        // account-dependent synchronous work (lookup, hashing, writes, queue
        // publication, and the preserve decision) — identical for issued,
        // preserved, decoy, contended and cache-outage outcomes. Nothing
        // inside may block on a lock (see PUBLIC_LOCK_WAIT_SECONDS).
        return (array) $this->timebox->call(function () use ($identifier, $currentToken, $currentSubject): array {
            $subject = ResetIdentifier::limiterSubject($identifier);
            $resolvedUserId = null;

            try {
                [$user, $channel, $destination] = $this->resolveAccount($identifier);
                $resolvedUserId = $user?->id;

                if ($user !== null && $channel !== null && $destination !== null) {
                    return [
                        'token' => $this->issueChallenge($user, $channel, $destination),
                        'subject' => $subject,
                        'outcome' => self::OUTCOME_ISSUED,
                    ];
                }
            } catch (\Throwable $e) {
                $this->safeLog('request', 'request_error', null, $e);
            }

            // Issuance did not happen (contended lock, cache outage,
            // ineligible or nonexistent account). NEVER clobber a still-valid
            // challenge belonging to THIS session and THIS canonical account:
            // the OTP already on its way must stay verifiable.
            if ($this->currentChallengeStillUsable($currentToken, $currentSubject, $subject, $resolvedUserId)) {
                return [
                    'token' => (string) $currentToken,
                    'subject' => $subject,
                    'outcome' => self::OUTCOME_PRESERVED,
                ];
            }

            // Decoy token: structurally identical, matches no challenge row.
            return [
                'token' => bin2hex(random_bytes(32)),
                'subject' => $subject,
                'outcome' => self::OUTCOME_DECOY,
            ];
        }, self::REQUEST_TIMEBOX_MICROSECONDS);
    }

    /**
     * May the session keep the challenge token it already holds?
     *
     * ONLY when every server-side condition holds: the session was started
     * for the SAME canonical account identity (constant-time comparison of
     * the non-reversible subject binding), the token maps to a real
     * challenge, that challenge is unconsumed and unexpired, and — when the
     * current identifier resolved to an account — it belongs to that same
     * account. A changed identifier can therefore never inherit the previous
     * account's challenge, and no user-supplied id or token is trusted: the
     * token is a server-generated secret that only this session holds.
     */
    private function currentChallengeStillUsable(?string $currentToken, ?string $currentSubject, string $subject, ?int $resolvedUserId): bool
    {
        if (! is_string($currentToken) || $currentToken === ''
            || ! is_string($currentSubject) || $currentSubject === ''
            || ! hash_equals($subject, $currentSubject)) {
            return false;
        }

        try {
            $challenge = PasswordResetChallenge::where('token', $currentToken)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->first(['id', 'user_id']);

            if ($challenge === null) {
                return false;
            }

            return $resolvedUserId === null || (int) $challenge->user_id === (int) $resolvedUserId;
        } catch (\Throwable $e) {
            $this->safeLog('request', 'preserve_check_failed', null, $e);

            return false;
        }
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
            // NAVIGATION ONLY (unlocked): find which USER row to lock first.
            // Nothing here authorizes anything — every decision below is made
            // on the locked, reloaded records.
            $userId = is_string($token) && $token !== ''
                ? PasswordResetChallenge::where('token', $token)->value('user_id')
                : null;

            if ($userId === null) {
                Hash::check($code, self::DUMMY_HASH); // uniform timing

                return null;
            }

            return DB::transaction(function () use ($token, $code, $userId): ?string {
                // Hierarchy level 2 → 3 (never reversed).
                User::whereKey($userId)->lockForUpdate()->first();

                $challenge = PasswordResetChallenge::where('token', $token)->lockForUpdate()->first();

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
            // NAVIGATION ONLY (unlocked): resolve which USER row to lock. This
            // read never authorizes the reset — it only chooses the lock
            // target, and the locked reload below re-validates everything.
            $userId = PasswordResetChallenge::where('token', $token)->value('user_id');
            if ($userId === null) {
                return false;
            }

            return DB::transaction(function () use ($token, $proof, $newPassword, $userId): bool {
                // HIERARCHY: user row FIRST, then the challenge row — the same
                // order issuance uses, so replacement issuance and
                // finalization for one account can never deadlock.
                $user = User::whereKey($userId)->lockForUpdate()->first();

                $this->raceBarrier('finalize.locks_acquired');

                $challenge = PasswordResetChallenge::where('token', $token)->lockForUpdate()->first();

                if ($challenge === null
                    || $challenge->user_id !== $userId
                    || $challenge->authorized_at === null
                    || $challenge->consumed_at !== null
                    || $challenge->authorization_expires_at === null
                    || $challenge->authorization_expires_at->isPast()
                    || ! hash_equals((string) $challenge->authorization_proof_hash, hash('sha256', $proof))) {
                    return false;
                }

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

        // HIERARCHY level 1: the per-user CACHE lock is held across the whole
        // issuance transaction. An in-flight delivery worker holds the SAME
        // lock across its transport attempt, so a replacement challenge can
        // never become authoritative while an older code is mid-flight — and
        // an older worker can never start a transport after this replacement
        // commits. Serialization is mandatory: if the lock is unavailable
        // (contention or a cache outage) issuance FAILS CLOSED and the caller
        // returns the ordinary decoy token + generic message.
        $challenge = $this->withUserLock($user->id, function () use ($user, $channel, $code, $token): PasswordResetChallenge {
            $this->raceBarrier('issue.lock_acquired');

            return DB::transaction(function () use ($user, $channel, $code, $token): PasswordResetChallenge {
                // Hierarchy level 2 → 3.
                User::whereKey($user->id)->lockForUpdate()->first();

                // An ABANDONED claim being superseded is terminalized
                // HONESTLY: the crashed worker may or may not have reached the
                // provider, so the row becomes `delivery_unknown` rather than
                // pretending it was sent (or that it never went out). It is
                // never transported again.
                PasswordResetChallenge::where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->where('send_status', PasswordResetChallenge::SEND_STATUS_SENDING)
                    ->update([
                        'send_status' => PasswordResetChallenge::SEND_STATUS_DELIVERY_UNKNOWN,
                        'delivery_claim_token' => null,
                    ]);

                // Only one live challenge per account (the
                // password_reset_one_active partial unique index is the
                // database-level authority for the same rule).
                PasswordResetChallenge::where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                return $this->persistChallenge($user, $channel, $code, $token);
            });
        });

        if (! $challenge instanceof PasswordResetChallenge) {
            $this->safeLog('issue', 'coordination_unavailable', $token, null);

            throw new \RuntimeException('password reset issuance could not be serialized');
        }

        // Publication strictly AFTER the commit. Honest delivery state: a
        // publication failure is recorded as dispatch_failed (no transport
        // attempt ever happened) and the public response stays generic. The
        // `queued` stamp is conditional so an inline (sync-queue) job's own
        // sent/failed result is never overwritten.
        // Published AFTER the issuance transaction committed AND after the
        // per-user lock was released: a sync-queue driver executes the job
        // inline, and it takes the SAME lock.
        try {
            SendPasswordResetOtpJob::dispatch($challenge->id, $channel, $destination, $code, $user->id);
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

    /**
     * The per-user coordination lock key — shared with
     * SendPasswordResetOtpJob so replacement issuance and an in-flight
     * delivery attempt can never overlap. Level 1 of the lock hierarchy.
     */
    public static function userLockKey(int $userId): string
    {
        return 'password-reset:user:'.$userId;
    }

    /**
     * Run $callback while holding the BOUNDED per-user cache lock (hierarchy
     * level 1, always taken BEFORE any row lock). Returns the sentinel
     * $onUnavailable when serialization cannot be guaranteed — contention or
     * a cache-backend outage — so callers FAIL CLOSED instead of mutating
     * unserialized. Release happens in `finally`; Laravel's lock owner token
     * makes the release owner-safe (a worker can never release another
     * holder's lock), and the TTL is the backstop for a crashed holder.
     *
     * $waitSeconds <= 0 (the DEFAULT, used by the public request path) means
     * ONE immediate, NON-BLOCKING attempt: a contended public request is
     * EXCLUDED rather than queued, so no caller can measure lock-wait time.
     * Only queue workers pass a bounded blocking wait.
     */
    private function withUserLock(int $userId, callable $callback, mixed $onUnavailable = null, int $waitSeconds = self::PUBLIC_LOCK_WAIT_SECONDS): mixed
    {
        try {
            $lock = Cache::lock(self::userLockKey($userId), self::LOCK_TTL_SECONDS);

            if ($waitSeconds <= 0) {
                // NON-BLOCKING: one immediate attempt (public request path).
                if (! $lock->get()) {
                    return $onUnavailable;
                }
            } else {
                $lock->block($waitSeconds);
            }
        } catch (LockTimeoutException) {
            return $onUnavailable;
        } catch (\Throwable) {
            return $onUnavailable;
        }

        try {
            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // TTL expiry is the backstop for a failed release.
            }
        }
    }

    /**
     * Deterministic-concurrency seam: a no-op in production, overridden by
     * PostgreSQL race tests to park a process at a named point while holding
     * its locks. Barriers — never timing sleeps — make the cross-flow
     * orderings reproducible.
     */
    protected function raceBarrier(string $stage): void
    {
        // no-op
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
