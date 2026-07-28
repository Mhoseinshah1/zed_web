<?php

namespace App\Jobs;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetChallenge;
use App\Services\Auth\PasswordResetService;
use App\Services\Sms\SmsService;
use App\Support\DatabaseLockTimeout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers a password-reset OTP over the challenge's bound channel.
 *
 * - ShouldBeEncrypted: the payload carries the plaintext code and the
 *   destination, so it is encrypted at rest in the queue backend.
 * - Delivery happens OUT of the public request so an existing account cannot
 *   be distinguished from a nonexistent one by transport latency.
 *
 * ══ STALE-DELIVERY COORDINATION ═══════════════════════════════════════════
 *
 * A preflight "is it still active?" read is NOT sufficient: a replacement
 * issuance can consume the old challenge between that read and the transport
 * call, so the user would receive an OTP that is already dead. Freshness is
 * therefore guaranteed by a CLAIM held under the SAME per-user cache lock
 * that issuance takes (PasswordResetService::userLockKey), following the one
 * global hierarchy: cache lock → user row → challenge row.
 *
 *   1. acquire the per-user cache lock (bounded wait, bounded lease);
 *   2. CLAIM the exact active challenge in a SHORT transaction — conditional
 *      update to `sending` stamping an opaque owner token; a consumed,
 *      expired, superseded, already-sent/failed or freshly-claimed row cannot
 *      be claimed;
 *   3. COMMIT, then talk to the transport — never inside a transaction, but
 *      still inside the cache lock, so a replacement issuance cannot become
 *      authoritative mid-flight;
 *   4. finalize `sent`/`failed` with a TOKEN-MATCHED conditional update, so a
 *      stale worker can never overwrite a newer attempt's state;
 *   5. release the lock in `finally` (owner-safe; the lease is the backstop).
 *
 * Either issuance wins (this worker finds the row consumed and sends nothing)
 * or this worker wins (issuance waits for the bounded attempt to finish).
 *
 * RECOVERY: a crashed worker leaves a `sending` row whose claim ages out
 * after PasswordResetService::ABANDONED_CLAIM_MINUTES, making it re-claimable
 * — an account can never be permanently stuck. The cache lease expires
 * independently.
 *
 * RETRY POLICY (documented): queue retries exist ONLY to recover from claim
 * contention (lock wait / row-lock timeout), never to repeat a completed
 * transport attempt. Once an attempt finalizes as `sent` OR `failed`, the row
 * is no longer claimable, so a retry sends nothing and the user simply
 * requests a fresh code. One challenge ⇒ at most one transport call.
 */
class SendPasswordResetOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retries recover from claim CONTENTION only (see the retry policy). */
    public int $tries = 2;

    /** @var array<int,int> seconds */
    public array $backoff = [15];

    /** Bounds one complete transport attempt (SMTP conversation / SMS API). */
    public int $timeout = 120;

    /**
     * The per-user lock is held across the whole transport attempt, so its
     * lease must exceed this job's timeout — an abandoned lock still expires.
     */
    public const LOCK_TTL_SECONDS = 150;

    /** This attempt's claim — in memory only, never serialized or logged. */
    private ?string $claimToken = null;

    public function __construct(
        private readonly int $challengeId,
        private readonly string $channel,
        private readonly string $destination,
        private readonly string $code,
        private readonly ?int $userId = null,
    ) {
        // Publication happens only after the issuance transaction commits.
        $this->afterCommit = true;
    }

    public function handle(SmsService $sms): void
    {
        // The user id travels in the ENCRYPTED payload (captured during
        // issuance), so the lock key never depends on a pre-lock query. Older
        // payloads without it fall back to a navigation read whose only use
        // is choosing the lock key.
        $userId = $this->userId ?? PasswordResetChallenge::whereKey($this->challengeId)->value('user_id');
        if ($userId === null) {
            return; // challenge already gone — nothing to deliver
        }

        $this->beforeClaim();

        try {
            $lock = Cache::lock(PasswordResetService::userLockKey((int) $userId), self::LOCK_TTL_SECONDS);
            $lock->block(PasswordResetService::LOCK_WAIT_SECONDS);
        } catch (Throwable) {
            // Contention or cache outage: retry soon rather than waiting
            // unboundedly or sending unserialized.
            $this->releaseForRetry(10);

            return;
        }

        try {
            try {
                $claimed = $this->claim();
            } catch (QueryException $e) {
                if (DatabaseLockTimeout::isLockTimeout($e)) {
                    $this->releaseForRetry(10);

                    return;
                }

                throw $e;
            }

            // Not claimable: consumed by a replacement issuance, expired,
            // superseded, or already delivered/attempted. Send NOTHING.
            if (! $claimed) {
                return;
            }

            $this->beforeTransport();

            // ── Transport: OUTSIDE any database transaction (a slow SMTP
            // server must never pin row locks), INSIDE the per-user lock. ──
            try {
                if ($this->channel === PasswordResetChallenge::CHANNEL_SMS) {
                    $ok = $sms->sendOtp($this->destination, $this->code); // never throws
                    $this->finalizeClaim($ok
                        ? PasswordResetChallenge::SEND_STATUS_SENT
                        : PasswordResetChallenge::SEND_STATUS_FAILED);

                    return;
                }

                Mail::to($this->destination)->send(
                    new PasswordResetOtpMail($this->code, PasswordResetService::CODE_TTL_MINUTES),
                );
                $this->finalizeClaim(PasswordResetChallenge::SEND_STATUS_SENT);
            } catch (Throwable $e) {
                // Token-matched failure bookkeeping happens HERE so the claim
                // is always finalized and never blocks a future issuance.
                $this->finalizeClaim(PasswordResetChallenge::SEND_STATUS_FAILED);
                $this->safeLog('transport_failed', $e);
            }
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The lease is the backstop for a failed release.
            }
        }
    }

    /**
     * Conditionally take ownership of the delivery attempt. Returns true only
     * when THIS worker stamped the claim. Runs in a short transaction and
     * commits before any transport call.
     */
    private function claim(): bool
    {
        $token = bin2hex(random_bytes(32));

        $stamped = DB::transaction(function () use ($token): int {
            DatabaseLockTimeout::applyLocal();

            return PasswordResetChallenge::whereKey($this->challengeId)
                ->whereNull('consumed_at')          // not superseded/consumed
                ->where('expires_at', '>', now())   // still usable on arrival
                ->where(function ($q) {
                    // Fresh (pending/queued) OR an ABANDONED `sending` claim
                    // from a crashed worker — never an already finalized row.
                    $q->whereIn('send_status', [
                        PasswordResetChallenge::SEND_STATUS_PENDING,
                        PasswordResetChallenge::SEND_STATUS_QUEUED,
                    ])->orWhere(function ($q) {
                        $q->where('send_status', PasswordResetChallenge::SEND_STATUS_SENDING)
                            ->where('delivery_claimed_at', '<=', now()->subMinutes(PasswordResetService::ABANDONED_CLAIM_MINUTES));
                    });
                })
                ->update([
                    'send_status' => PasswordResetChallenge::SEND_STATUS_SENDING,
                    'delivery_claim_token' => $token,
                    'delivery_claimed_at' => now(),
                ]);
        });

        if ($stamped === 1) {
            $this->claimToken = $token;

            return true;
        }

        return false;
    }

    /**
     * Monotonic, TOKEN-MATCHED finalization: only the worker that owns the
     * current claim may move `sending` to its terminal state, so a stale
     * worker can never overwrite newer authoritative state.
     */
    private function finalizeClaim(string $status): void
    {
        if ($this->claimToken === null) {
            return;
        }

        try {
            PasswordResetChallenge::whereKey($this->challengeId)
                ->where('send_status', PasswordResetChallenge::SEND_STATUS_SENDING)
                ->where('delivery_claim_token', $this->claimToken)
                ->update(['send_status' => $status, 'delivery_claim_token' => null]);
        } catch (Throwable $e) {
            // The abandoned-claim window recovers an unfinalized row.
            $this->safeLog('finalize_failed', $e);
        }
    }

    public function failed(Throwable $e): void
    {
        // Terminal queue failure: only a claim this job still owns is
        // finalized — never a newer attempt's state.
        $this->finalizeClaim(PasswordResetChallenge::SEND_STATUS_FAILED);
        $this->safeLog('delivery_exhausted', $e);
    }

    /** Deterministic-concurrency seams (no-ops in production). */
    protected function beforeClaim(): void {}

    protected function beforeTransport(): void {}

    /** Positive-listed log: stage, safe reason code, exception class only. */
    private function safeLog(string $reason, Throwable $e): void
    {
        try {
            Log::warning('[password-reset] delivery', [
                'stage' => 'delivery',
                'reason' => $reason,
                'challenge' => substr(hash('sha256', (string) $this->challengeId), 0, 12),
                'exception' => class_basename($e),
            ]);
        } catch (Throwable) {
            // Logging must never break delivery handling.
        }
    }

    /** Put the job back for a bounded retry without changing any state. */
    private function releaseForRetry(int $seconds): void
    {
        if ($this->job !== null) {
            $this->release($seconds);
        }
    }
}
