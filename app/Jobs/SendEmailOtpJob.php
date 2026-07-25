<?php

namespace App\Jobs;

use App\Mail\EmailOtpMail;
use App\Models\EmailVerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Queued OTP email delivery on the default (Redis) queue.
 *
 * - ShouldBeEncrypted: the payload carries the plaintext code, so it is
 *   encrypted at rest in Redis (the database only ever holds the hash).
 * - afterCommit: never dispatched before the surrounding transaction commits.
 * - Marks send_status on the persisted record when delivery finally succeeds
 *   or exhausts its retries — the UI and admins see the honest outcome.
 * - Never logs the message body or the OTP.
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
        Mail::to($this->email)->send(new EmailOtpMail($this->code, $this->ttlMinutes));

        EmailVerificationCode::whereKey($this->codeId)->update([
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
            'send_error' => null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        // Record the failure WITHOUT the message body or the code.
        EmailVerificationCode::whereKey($this->codeId)->update([
            'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
            'send_error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
