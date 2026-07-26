<?php

namespace App\Jobs;

use App\Mail\EmailOtpMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Support\DatabaseLockTimeout;
use App\Support\MailFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Queued OTP email delivery on the default (Redis) queue.
 *
 * - ShouldBeEncrypted: the payload carries the plaintext code, so it is
 *   encrypted at rest in Redis (the database only ever holds the hash).
 * - afterCommit: never dispatched before the surrounding transaction commits.
 * - CLAIM-OWNED delivery: every attempt claims the record inside a short
 *   bounded transaction (cache lock → User row → OTP row, `SET LOCAL
 *   lock_timeout`), writing a fresh cryptographically random per-attempt
 *   delivery_claim_token. Finalization (sent / skipped / accepted_pending /
 *   failed) runs in its OWN short bounded transaction with the same lock
 *   order and only ever mutates the record while it still carries THIS
 *   attempt's exact token — a worker resuming after its cache-lock TTL
 *   expired can never overwrite a newer worker's claim or state. The raw
 *   token is random non-secret material, held in memory for the attempt and
 *   hidden from serialized model output; it is never logged.
 * - The SMTP conversation runs with NO database transaction open, inside the
 *   per-user cache lock; the advertised validity is the REMAINING lifetime
 *   at claim time.
 * - Transport exceptions are re-thrown SANITIZED (category + scrubbed text,
 *   no chained original), so failed_jobs.exception can never store raw SMTP
 *   credentials.
 * - HONEST residual limitations (at-least-once): (1) if the transport
 *   accepts and the worker DIES before finalization, a retry re-claims and
 *   may re-send the still-valid single-use code; (2) if the worker survives
 *   but lost cache-lock ownership, the record is closed out as
 *   `accepted_pending` (delivered-but-uncertain, NOT claimable — no
 *   duplicate send); (3) if even finalization's bounded row-lock wait times
 *   out twice, the record stays `sending` under this attempt's token and a
 *   later retry may re-send. Exactly-once delivery over SMTP is not
 *   achievable; the code itself stays single-use throughout.
 */
class SendEmailOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> seconds */
    public array $backoff = [10, 30, 60];

    /**
     * Sized for the COMPLETE SMTP exchange, not one I/O operation: the
     * transport timeout (config/mail.php, capped at 20s) bounds each
     * individual stream read/write, and a full conversation is roughly ten
     * such blocking steps (connect, greeting, EHLO, STARTTLS+EHLO, AUTH,
     * MAIL, RCPT, DATA, payload, QUIT). A server that answers slowly-but-
     * within-bounds on every step must never be killed by the worker
     * mid-conversation — especially not after remote acceptance, where a
     * retry would duplicate the OTP email. 10 × 20s + claim/finalize margin.
     * EVERY queue driver's redelivery horizon MUST stay above this —
     * retry_after (config/queue.php: 300s) for database/Beanstalkd/Redis,
     * and the AWS-side queue VISIBILITY TIMEOUT for SQS (its 30s default is
     * far too low; see config/queue.php) — or an in-flight job would be
     * handed to a second worker mid-exchange, and failed()'s abandonment
     * logic (which relies on no attempt still running at retry exhaustion)
     * could finalize a live claim.
     */
    public int $timeout = 240;

    /**
     * The per-user lock is held through the whole transport conversation, so
     * its TTL must exceed the job timeout (abandoned locks still expire).
     */
    private const LOCK_TTL_SECONDS = 270;

    /**
     * Minimum remaining code validity required to CLAIM a delivery. Below
     * this, the message would advertise a near-dead (or dead-on-arrival)
     * code — the claim skips instead and the user simply requests a fresh
     * one. A slow-but-progressing transport can still consume part of the
     * remaining window after claim: that residual is unavoidable without
     * extending the code's validity, which would widen the security window.
     */
    private const MIN_DELIVERY_MARGIN_SECONDS = 120;

    /** This attempt's delivery claim — in memory only, never serialized/logged. */
    private ?string $claimToken = null;

    /** Memoized non-secret fingerprint of the config THIS process delivers with. */
    private ?string $configFingerprint = null;

    /** The mail-config fingerprint stamped onto finalized outcomes (see transportLooksLive). */
    private function configFingerprint(): string
    {
        return $this->configFingerprint ??= app(EmailVerificationService::class)->mailConfigFingerprint();
    }

    public function __construct(
        private readonly int $codeId,
        private readonly int $userId,
        private readonly string $email,
        private readonly string $code,
        private readonly int $ttlMinutes,
    ) {
        // Dispatch only after the surrounding DB transaction commits.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        // The user id travels IN the encrypted payload (captured under the
        // issuance lock) — the lock key never depends on a pre-lock query,
        // and claim() re-validates record ownership against it under locks.
        try {
            $lock = Cache::lock(EmailVerificationService::userLockKey($this->userId), self::LOCK_TTL_SECONDS);
            $lock->block(EmailVerificationService::LOCK_WAIT_SECONDS);
        } catch (Throwable) {
            // Contention (LockTimeoutException) or cache outage: retry soon
            // instead of waiting unboundedly or sending unserialized.
            $this->releaseForRetry(10);

            return;
        }

        try {
            try {
                $remainingMinutes = $this->claim();
            } catch (QueryException $e) {
                // Bounded row-lock timeout: pure contention, NOT a delivery
                // failure — release for a retry with backoff, change nothing.
                if (DatabaseLockTimeout::isLockTimeout($e)) {
                    $this->releaseForRetry(10);

                    return;
                }

                throw $e;
            }

            if ($remainingMinutes === null) {
                return;
            }

            // The transport conversation runs OUTSIDE any DB transaction — a
            // slow SMTP server must never pin row locks — but INSIDE the
            // per-user lock, so no address change can interleave mid-send.
            try {
                Mail::to($this->email)->send(new EmailOtpMail($this->code, $remainingMinutes));
            } catch (Throwable $e) {
                // Token-matched failure bookkeeping happens HERE, not in
                // failed(): the framework re-unserializes the job before
                // invoking failed(), so this attempt's in-memory claim token
                // would be lost there. Then re-throw SANITIZED and UNCHAINED —
                // failed_jobs.exception must never store raw SMTP credentials.
                $this->failTransportAttempt($e);

                throw new RuntimeException(MailFailure::summarize('delivery failed', $e));
            }

            $this->finalizeAccepted($lock);
        } finally {
            try {
                // Owner-token release: never force-releases another worker's lock.
                $lock->release();
            } catch (Throwable) {
                // TTL expiry is the backstop for a failed release.
            }
        }
    }

    /**
     * Atomically claim the record for THIS attempt inside one SHORT bounded
     * transaction (User row locked first, then the OTP row): re-validate
     * ownership/state, then transition queued → sending (or re-claim this
     * job's own `sending` leftover on retry) with a FRESH random claim token.
     * Returns the remaining validity in whole minutes (≥1), or null when the
     * send must not proceed (obsolete records are marked skipped with their
     * claim cleared; records owned elsewhere are left untouched).
     */
    private function claim(): ?int
    {
        // First attempt claims from `queued` only — two workers handed the
        // same job can never both pass the conditional update. A RETRY of
        // this same job (attempts > 1) may re-claim its own `sending` record,
        // which a previous attempt left behind — under a NEW token.
        $claimableFrom = [EmailVerificationCode::SEND_STATUS_QUEUED];
        if ($this->attempts() > 1) {
            $claimableFrom[] = EmailVerificationCode::SEND_STATUS_SENDING;
        }

        return DB::transaction(function () use ($claimableFrom) {
            DatabaseLockTimeout::applyLocal();

            // EXACT documented lock order — identical to requestCode/verify/
            // changeAddressTo: the authoritative USER row first, then the
            // code row.
            $user = User::whereKey($this->userId)
                ->lockForUpdate()
                ->first();

            $record = EmailVerificationCode::whereKey($this->codeId)
                ->lockForUpdate()
                ->first();

            if ($record === null || ! in_array($record->send_status, $claimableFrom, true)) {
                return null;
            }

            // Ownership is validated UNDER the locks against the payload —
            // never trusted from any pre-lock read.
            if ($record->user_id !== $this->userId) {
                $this->markSkipped($claimableFrom);

                return null;
            }

            // Obsolete when: consumed/invalidated, expired or WITHOUT a
            // usable delivery margin (a backlogged job claiming a code with
            // seconds left would email something that can expire during the
            // SMTP exchange while the message calls it valid), address
            // changed (record- or user-side), user gone, user ALREADY
            // verified, or a newer active code exists (only the newest may
            // be delivered).
            // The DELIVERY POLICY is re-validated at claim time too: an admin
            // disabling verification (or the mailer degrading to a
            // non-deliverable graph — e.g. production log/array, which would
            // write the plaintext OTP into application logs while "sending")
            // must also stop the queued backlog, not only new issuance.
            $service = app(EmailVerificationService::class);

            $obsolete = $record->used_at !== null
                || ! $service->isEnabled()
                || ! $service->isMailConfigured()
                || now()->diffInSeconds($record->expires_at, false) < self::MIN_DELIVERY_MARGIN_SECONDS
                || strcasecmp($record->email, $this->email) !== 0
                || $user === null
                || $user->email_verified_at !== null
                || strcasecmp((string) $user->email, $this->email) !== 0
                || EmailVerificationCode::where('user_id', $record->user_id)
                    ->whereNull('used_at')
                    ->where('id', '>', $record->id)
                    ->exists();

            if ($obsolete) {
                $this->markSkipped($claimableFrom);

                return null;
            }

            $this->claimToken = bin2hex(random_bytes(32));

            $claimed = EmailVerificationCode::whereKey($this->codeId)
                ->whereIn('send_status', $claimableFrom)
                ->update([
                    'send_status' => EmailVerificationCode::SEND_STATUS_SENDING,
                    'delivery_claim_token' => $this->claimToken,
                    'delivery_claimed_at' => now(),
                ]) === 1;
            if (! $claimed) {
                $this->claimToken = null;

                return null;
            }

            // Advertise only the validity the code ACTUALLY still has.
            return max(1, (int) floor(now()->diffInSeconds($record->expires_at, false) / 60));
        });
    }

    /** Terminal skip inside the claim transaction — claim fields cleared. */
    private function markSkipped(array $fromStatuses): void
    {
        EmailVerificationCode::whereKey($this->codeId)
            ->whereIn('send_status', $fromStatuses)
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_SKIPPED,
                'delivery_claim_token' => null,
                'delivery_claimed_at' => null,
                'delivery_finalized_at' => now(),
            ]);
    }

    /**
     * Post-acceptance finalization in its OWN short bounded transaction with
     * the standard lock order. Mutates the record ONLY while it still carries
     * THIS attempt's exact claim token (timing-safe compare):
     *
     *  - still valid + cache lock still owned  → sent
     *  - still valid + cache-lock ownership lost → accepted_pending
     *    (delivered-but-uncertain; NOT claimable, so no duplicate send)
     *  - became non-actionable mid-send (used/address change/verified)
     *    → skipped (never an actionable `sent`)
     *  - token no longer ours → touch NOTHING (a newer worker owns it)
     *
     * If even the bounded row-lock wait times out (twice, including the
     * accepted_pending fallback), the record stays `sending` under our token:
     * a later retry may re-claim and re-send — the documented at-least-once
     * residual after transport acceptance.
     */
    private function finalizeAccepted(Lock $lock): void
    {
        try {
            DB::transaction(function () use ($lock) {
                DatabaseLockTimeout::applyLocal();

                $user = User::whereKey($this->userId)->lockForUpdate()->first();
                $record = EmailVerificationCode::whereKey($this->codeId)->lockForUpdate()->first();

                if (
                    $record === null
                    || $record->send_status !== EmailVerificationCode::SEND_STATUS_SENDING
                    || ! hash_equals((string) $this->claimToken, (string) $record->delivery_claim_token)
                ) {
                    // Another worker re-claimed (new token) or already reached
                    // a terminal state — its claim is NOT ours to touch.
                    return;
                }

                $stillActionable = $record->used_at === null
                    && $record->user_id === $this->userId
                    && strcasecmp($record->email, $this->email) === 0
                    && $user !== null
                    && strcasecmp((string) $user->email, $this->email) === 0
                    && $user->email_verified_at === null;

                if (! $stillActionable) {
                    $record->forceFill([
                        'send_status' => EmailVerificationCode::SEND_STATUS_SKIPPED,
                        'delivery_claim_token' => null,
                        'delivery_claimed_at' => null,
                        'delivery_finalized_at' => now(),
                    ])->save();

                    return;
                }

                // A cache outage DURING the ownership check (Redis died after
                // the transport accepted) must not roll back finalization and
                // leave `sending` for a duplicate re-send — an unverifiable
                // lock is LOST ownership, honestly terminal as accepted_pending.
                try {
                    $lockStillOwned = $lock->isOwnedByCurrentProcess();
                } catch (Throwable) {
                    $lockStillOwned = false;
                }

                if ($lockStillOwned) {
                    $record->forceFill([
                        'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
                        'send_error' => null,
                        'delivery_claim_token' => null,
                        'delivery_claimed_at' => null,
                        'delivery_finalized_at' => now(),
                        'delivery_config_fingerprint' => $this->configFingerprint(),
                    ])->save();
                } else {
                    // Transport accepted, ownership uncertain: honest terminal
                    // marker that can never be re-claimed into a re-send.
                    $record->forceFill([
                        'send_status' => EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING,
                        'delivery_claim_token' => null,
                        'delivery_claimed_at' => null,
                        'delivery_finalized_at' => now(),
                        'delivery_config_fingerprint' => $this->configFingerprint(),
                    ])->save();
                }
            });
        } catch (QueryException $e) {
            if (! DatabaseLockTimeout::isLockTimeout($e)) {
                throw $e;
            }

            // Transport accepted but finalization contended: ONE bounded
            // fallback recording the honest uncertain state for OUR claim.
            try {
                DB::transaction(function () {
                    DatabaseLockTimeout::applyLocal();
                    EmailVerificationCode::whereKey($this->codeId)
                        ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
                        ->where('delivery_claim_token', $this->claimToken)
                        ->update([
                            'send_status' => EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING,
                            'delivery_claim_token' => null,
                            'delivery_claimed_at' => null,
                            'delivery_finalized_at' => now(),
                            'delivery_config_fingerprint' => $this->configFingerprint(),
                        ]);
                });
            } catch (QueryException $e2) {
                if (! DatabaseLockTimeout::isLockTimeout($e2)) {
                    throw $e2;
                }
                // Sanitized, token-free operational signal: the record stays
                // `sending` under this attempt's claim; a retry may re-send.
                Log::warning('Email OTP finalization contended after transport acceptance', [
                    'code_id' => $this->codeId,
                    'outcome' => 'left_sending_for_retry',
                ]);
            }
        }
    }

    /**
     * Transport-failure bookkeeping WHILE the claim token is still in memory.
     * With retries remaining, the record deliberately stays `sending` under
     * this attempt's token — the retry re-claims it with a fresh one. On the
     * FINAL attempt (or with no queue context to retry in), the record is
     * closed out as `failed` ONLY while it still carries this attempt's exact
     * token, so no newer claim can ever be failed by an older worker. A
     * genuine transport attempt happened, so `failed` (which counts toward
     * the daily cap) is the honest state. Residual: a worker killed so hard
     * that this never runs leaves the record `sending`; a retry re-claims it,
     * and after the last attempt it stays `sending` as an operational signal
     * rather than being guessed into a terminal state.
     */
    private function failTransportAttempt(Throwable $e): void
    {
        // Final when no retry can follow: direct invocation, the sync driver
        // (executes exactly once — attempts() never advances), or the last
        // allowed attempt of a real worker.
        $isFinalAttempt = $this->job === null
            || $this->job instanceof SyncJob
            || $this->attempts() >= $this->tries;
        if (! $isFinalAttempt || $this->claimToken === null) {
            return;
        }

        $safe = MailFailure::summarize('delivery failed', $e);

        EmailVerificationCode::whereKey($this->codeId)
            ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
            ->where('delivery_claim_token', $this->claimToken)
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'send_error' => $safe,
                'delivery_claim_token' => null,
                'delivery_claimed_at' => null,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => $this->configFingerprint(),
            ]);
    }

    /**
     * Release this job back to the queue for a delayed retry — or, when no
     * queue context exists to release into (the sync driver executes exactly
     * once; direct invocation has no job at all), surface the contention as a
     * FAILURE: a silent return would let dispatch() report success while the
     * record stays `queued` forever. The message is static (no exception
     * text, no secrets) and carries the `delivery failed:` prefix so failed()
     * stores it verbatim.
     */
    private function releaseForRetry(int $delaySeconds): void
    {
        // SyncJob executes exactly once: its release() is a no-op, so
        // "releasing" would let dispatch() report success while the record
        // stays `queued` forever — treat it exactly like having no queue
        // context and surface the contention as a failure instead.
        if ($this->job !== null && ! $this->job instanceof SyncJob) {
            $this->release($delaySeconds);

            return;
        }

        throw new RuntimeException('delivery failed: lock_contention (no retry available on the current queue driver)');
    }

    /**
     * The framework invokes this on a RE-UNSERIALIZED instance — the claim
     * token never survives into it — but by the time it fires every retry of
     * THIS job is exhausted, and claims on this codeId are only ever made by
     * this job's own attempts (retry_after > job timeout excludes a
     * still-running attempt). Two token-free mutations are therefore safe:
     *
     *  - an UNOWNED `queued` record (contention exhausted before any claim,
     *    ZERO transport attempts) → `dispatch_failed`, which the daily cap
     *    and cooldown exclude — contention or a cache outage can never burn
     *    a user's OTP allowance;
     *  - an ABANDONED `sending` claim (an early attempt claimed, later
     *    reservations burned out before re-claiming) → `failed` — a real
     *    transport attempt happened, and the record must not stay actionable
     *    until expiry advertising a code no transport accepted.
     *
     * Real transport failures on the final attempt were already closed out
     * token-matched inside handle() (failTransportAttempt).
     */
    public function failed(Throwable $e): void
    {
        // Sanitized only — raw transport text can echo SMTP credentials.
        $safe = str_starts_with($e->getMessage(), 'delivery failed:')
            ? mb_substr($e->getMessage(), 0, MailFailure::MAX_LENGTH)
            : MailFailure::summarize('delivery failed', $e);

        EmailVerificationCode::whereKey($this->codeId)
            ->where('send_status', EmailVerificationCode::SEND_STATUS_QUEUED)
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
                'send_error' => $safe,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => $this->configFingerprint(),
            ]);

        // An ABANDONED `sending` claim (an early attempt claimed, then the
        // remaining reservations burned out on contention before re-claiming)
        // must not stay actionable until expiry — the notice would advertise
        // a code no transport ever accepted while the cooldown blocks a
        // replacement. Claims for this codeId are made ONLY by this job's own
        // attempts, and retry_after (300s) > job timeout (240s) guarantees no
        // attempt is still running when retries are exhausted — so a leftover
        // `sending` here is demonstrably ours and safely final: a REAL
        // transport attempt happened, honest terminal state `failed` (counts
        // toward the cap).
        EmailVerificationCode::whereKey($this->codeId)
            ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'send_error' => $safe,
                'delivery_claim_token' => null,
                'delivery_claimed_at' => null,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => $this->configFingerprint(),
            ]);
    }
}
