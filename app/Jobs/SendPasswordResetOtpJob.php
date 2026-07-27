<?php

namespace App\Jobs;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetChallenge;
use App\Services\Auth\PasswordResetService;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a password-reset OTP over the challenge's bound channel.
 *
 * - ShouldBeEncrypted: the payload carries the plaintext code and the
 *   destination, so it is encrypted at rest in the queue backend.
 * - Delivery happens OUT of the public request so an existing account cannot
 *   be distinguished from a nonexistent one by transport latency.
 * - The challenge records an honest send_status: queued → sent/failed here;
 *   a queue-publication failure never reaches this job at all
 *   (dispatch_failed, recorded by the service).
 * - A delivery failure only updates state and logs safe fields — it never
 *   surfaces to the requester.
 */
class SendPasswordResetOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [15];

    public function __construct(
        private readonly int $challengeId,
        private readonly string $channel,
        private readonly string $destination,
        private readonly string $code,
    ) {}

    public function handle(SmsService $sms): void
    {
        $challenge = PasswordResetChallenge::find($this->challengeId);
        // Superseded/expired/consumed challenges are never delivered late.
        if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
            return;
        }

        if ($this->channel === PasswordResetChallenge::CHANNEL_SMS) {
            $ok = $sms->sendOtp($this->destination, $this->code); // best-effort, never throws
            $challenge->update(['send_status' => $ok
                ? PasswordResetChallenge::SEND_STATUS_SENT
                : PasswordResetChallenge::SEND_STATUS_FAILED]);

            return;
        }

        Mail::to($this->destination)->send(
            new PasswordResetOtpMail($this->code, PasswordResetService::CODE_TTL_MINUTES),
        );
        $challenge->update(['send_status' => PasswordResetChallenge::SEND_STATUS_SENT]);
    }

    public function failed(\Throwable $e): void
    {
        PasswordResetChallenge::whereKey($this->challengeId)
            ->update(['send_status' => PasswordResetChallenge::SEND_STATUS_FAILED]);

        Log::warning('[password-reset] delivery failed', [
            'stage' => 'delivery',
            'reason' => 'transport_failed',
            'exception' => class_basename($e),
        ]);
    }
}
