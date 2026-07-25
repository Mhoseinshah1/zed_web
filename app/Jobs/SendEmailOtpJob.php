<?php

namespace App\Jobs;

use App\Mail\EmailOtpMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Support\DatabaseLockTimeout;
use App\Support\MailFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Queued OTP email delivery on the default (Redis) queue.
 *
 * - ShouldBeEncrypted: the payload carries the plaintext code, so it is
 *   encrypted at rest in Redis (the database only ever holds the hash).
 * - afterCommit: never dispatched before the surrounding transaction commits.
 * - RACE-SAFE claiming: the job first takes the SAME per-user distributed
 *   lock used by issuance/verification/address changes and holds it through
 *   claim → transport send → finalize, so an address change can never commit
 *   while an already-validated send is in flight. Laravel cache locks carry
 *   an OWNER token internally: release() only ever releases this worker's
 *   own acquisition (never another worker's), and the TTL (45s) exceeds the
 *   job timeout (30s) so an abandoned lock always expires. Inside the lock,
 *   a short DB transaction (with a bounded row-lock wait) atomically claims
 *   the record (queued → sending) after re-validating it is still the newest
 *   active code for a still-unverified user whose address still matches; the
 *   SMTP conversation itself runs with NO database transaction open. States
 *   move monotonically: queued → sending → sent, queued/sending → failed,
 *   queued/sending → skipped.
 * - The advertised validity in the email is the REMAINING lifetime at claim
 *   time, not the full configured TTL — a queue delay must not promise
 *   minutes the code no longer has.
 * - Transport exceptions are re-thrown SANITIZED (category + scrubbed text,
 *   no chained original), so the framework's failed_jobs.exception column can
 *   never store raw SMTP credentials.
 * - HONEST residual limitation (at-least-once): if the transport ACCEPTS the
 *   message and the worker dies before recording `sent`, a retry may send
 *   the same still-valid code again. The code itself stays single-use and
 *   the claim re-validation minimizes the window, but exactly-once delivery
 *   over SMTP is not achievable.
 */
class SendEmailOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> seconds */
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    /**
     * The per-user lock is held through the whole transport conversation, so
     * its TTL must exceed the job timeout (abandoned locks still expire).
     */
    private const LOCK_TTL_SECONDS = 45;

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
            $this->release(10);

            return;
        }

        try {
            try {
                $remainingMinutes = $this->claim();
            } catch (QueryException $e) {
                // Bounded row-lock timeout: pure contention, NOT a delivery
                // failure — release for a retry with backoff, change nothing.
                if (DatabaseLockTimeout::isLockTimeout($e)) {
                    $this->release(10);

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
                // Re-throw SANITIZED and UNCHAINED: the framework persists the
                // thrown exception into failed_jobs.exception verbatim, and
                // raw transport text can echo SMTP credentials.
                throw new RuntimeException(MailFailure::summarize('delivery failed', $e));
            }

            // Terminal `sent` only while this worker still owns its claim AND
            // the per-user lock: a record invalidated after lock expiry (an
            // address change won the expired lock) is closed out as skipped —
            // never reported as a delivered, still-actionable code.
            if ($lock->isOwnedByCurrentProcess()) {
                EmailVerificationCode::whereKey($this->codeId)
                    ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
                    ->whereNull('used_at')
                    ->update([
                        'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
                        'send_error' => null,
                    ]);
            }
            EmailVerificationCode::whereKey($this->codeId)
                ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
                ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SKIPPED]);
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
     * Atomically claim the record (queued → sending) after re-validating it,
     * all inside one SHORT transaction with a bounded row-lock wait. Returns
     * the REMAINING validity in whole minutes (≥1) when the send may proceed,
     * or null when it must not: obsolete records are marked skipped, records
     * claimed by another worker are simply left alone.
     */
    private function claim(): ?int
    {
        // First attempt claims from `queued` only — two workers handed the
        // same job can never both pass the conditional update. A RETRY of
        // this same job (attempts > 1) may re-claim its own `sending` record,
        // which a previous attempt left behind when the transport threw.
        $claimableFrom = [EmailVerificationCode::SEND_STATUS_QUEUED];
        if ($this->attempts() > 1) {
            $claimableFrom[] = EmailVerificationCode::SEND_STATUS_SENDING;
        }

        return DB::transaction(function () use ($claimableFrom) {
            DatabaseLockTimeout::applyLocal();

            // EXACT documented lock order — identical to requestCode/verify/
            // changeAddressTo: the authoritative USER row first, then the
            // code row. A transaction outside the cache-lock protocol
            // (commands, imports, admin tooling) contends here, bounded by
            // the local lock timeout.
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
                EmailVerificationCode::whereKey($this->codeId)
                    ->whereIn('send_status', $claimableFrom)
                    ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SKIPPED]);

                return null;
            }

            // Obsolete when: consumed/invalidated, expired, address changed
            // (record- or user-side), user gone, user ALREADY verified, or a
            // newer active code exists (only the newest may be delivered).
            $obsolete = $record->used_at !== null
                || $record->expires_at->isPast()
                || strcasecmp($record->email, $this->email) !== 0
                || $user === null
                || $user->email_verified_at !== null
                || strcasecmp((string) $user->email, $this->email) !== 0
                || EmailVerificationCode::where('user_id', $record->user_id)
                    ->whereNull('used_at')
                    ->where('id', '>', $record->id)
                    ->exists();

            if ($obsolete) {
                EmailVerificationCode::whereKey($this->codeId)
                    ->whereIn('send_status', $claimableFrom)
                    ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SKIPPED]);

                return null;
            }

            $claimed = EmailVerificationCode::whereKey($this->codeId)
                ->whereIn('send_status', $claimableFrom)
                ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SENDING]) === 1;
            if (! $claimed) {
                return null;
            }

            // Advertise only the validity the code ACTUALLY still has.
            return max(1, (int) floor(now()->diffInSeconds($record->expires_at, false) / 60));
        });
    }

    public function failed(Throwable $e): void
    {
        // Record the failure WITHOUT the message body, the code, or raw
        // transport text (which can echo SMTP credentials). handle() already
        // re-throws sanitized summaries — store those verbatim; anything else
        // is summarized (category + scrubbed bounded diagnostic) here.
        $safe = str_starts_with($e->getMessage(), 'delivery failed:')
            ? mb_substr($e->getMessage(), 0, MailFailure::MAX_LENGTH)
            : MailFailure::summarize('delivery failed', $e);

        EmailVerificationCode::whereKey($this->codeId)
            ->whereIn('send_status', [
                EmailVerificationCode::SEND_STATUS_QUEUED,
                EmailVerificationCode::SEND_STATUS_SENDING,
            ])
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'send_error' => $safe,
            ]);
    }
}
