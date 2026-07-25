<?php

namespace App\Jobs;

use App\Mail\EmailOtpMail;
use App\Models\EmailVerificationCode;
use App\Support\MailFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Queued OTP email delivery on the default (Redis) queue.
 *
 * - ShouldBeEncrypted: the payload carries the plaintext code, so it is
 *   encrypted at rest in Redis (the database only ever holds the hash).
 * - afterCommit: never dispatched before the surrounding transaction commits.
 * - RACE-SAFE claiming: before any network I/O the job atomically claims its
 *   record (queued → sending) in a short transaction, verifying it is still
 *   the newest active code for a still-unverified user whose address still
 *   matches. The SMTP conversation happens with NO database transaction open;
 *   the terminal `sent` state is written only if the job still owns the claim.
 *   States move monotonically: queued → sending → sent, queued/sending →
 *   failed, queued → skipped.
 * - Never logs or stores the message body, the OTP, or raw transport errors.
 */
class SendEmailOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> seconds */
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(
        private readonly int $codeId,
        private readonly string $email,
        private readonly string $code,
        private readonly int $ttlMinutes,
    ) {
        // Dispatch only after the surrounding DB transaction commits.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        if (! $this->claim()) {
            return;
        }

        // The transport conversation runs OUTSIDE any DB transaction — a slow
        // SMTP server must never pin row locks or a connection-level snapshot.
        Mail::to($this->email)->send(new EmailOtpMail($this->code, $this->ttlMinutes));

        // Terminal state only if this job still owns the claim: a competing
        // writer that skipped/failed the record in the meantime wins.
        EmailVerificationCode::whereKey($this->codeId)
            ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
                'send_error' => null,
            ]);
    }

    /**
     * Atomically claim the record (queued → sending) after re-validating it,
     * all inside one SHORT transaction. Returns false when the job must not
     * send: obsolete records are marked skipped, records claimed by another
     * worker are simply left alone.
     */
    private function claim(): bool
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
            $record = EmailVerificationCode::whereKey($this->codeId)
                ->lockForUpdate()
                ->first();

            if ($record === null || ! in_array($record->send_status, $claimableFrom, true)) {
                return false;
            }

            $user = $record->user()->first();

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

                return false;
            }

            return EmailVerificationCode::whereKey($this->codeId)
                ->whereIn('send_status', $claimableFrom)
                ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SENDING]) === 1;
        });
    }

    public function failed(Throwable $e): void
    {
        // Record the failure WITHOUT the message body, the code, or raw
        // transport text (which can echo SMTP credentials) — only a sanitized
        // category + bounded redacted diagnostic. Terminal states stay final.
        EmailVerificationCode::whereKey($this->codeId)
            ->whereIn('send_status', [
                EmailVerificationCode::SEND_STATUS_QUEUED,
                EmailVerificationCode::SEND_STATUS_SENDING,
            ])
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'send_error' => MailFailure::summarize('delivery failed', $e),
            ]);
    }
}
