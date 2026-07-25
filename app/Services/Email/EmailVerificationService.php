<?php

namespace App\Services\Email;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\MailFailure;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * OTP-based email verification (mirrors the PhoneVerificationService
 * architecture; no signed-link flow — the user types a 6-digit code).
 *
 * Codes are 6-digit numeric, hashed at rest, single-use, TTL-bound, with a
 * resend cooldown and a rolling daily cap. Delivery goes through a queued,
 * encrypted job that records an honest send_status — a failed dispatch is
 * reported as a failure, never as "code sent".
 */
class EmailVerificationService
{
    // Defaults — overridden by admin settings at runtime.
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SEC = 60;

    public const DAILY_CAP = 10;

    // ── Settings ─────────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get('email_verification_enabled', false);
    }

    /**
     * Required-at-registration only takes effect while the mail configuration
     * is actually usable — a misconfigured "required" flag can never lock
     * users out of their accounts.
     */
    public function isRequiredOnRegister(): bool
    {
        return $this->isEnabled()
            && (bool) SiteSetting::get('email_verification_required_on_register', false)
            && $this->isMailConfigured();
    }

    /**
     * Whether outbound mail looks genuinely deliverable. In production the
     * `log` and `array` mailers are NOT configuration — they silently discard
     * mail, so treating them as configured would strand unverified users.
     * COMPOSITE mailers (failover/roundrobin) are expanded: the repository's
     * default `failover` chains smtp → log, and a log fallback would report a
     * "successful" send without delivering the OTP — so any composite that
     * contains a non-delivery transport is rejected in production too.
     */
    public function isMailConfigured(): bool
    {
        // null = the mailer graph itself is invalid (undefined name — e.g. the
        // classic `smpt` typo — a cycle, or an empty composite). Conservative:
        // an invalid graph is NEVER "configured".
        $transports = $this->effectiveTransports((string) config('mail.default'));
        if ($transports === null || $transports === []) {
            return false;
        }

        if (app()->environment('production') && array_intersect($transports, ['log', 'array']) !== []) {
            return false;
        }

        $from = (string) config('mail.from.address');
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // EVERY effective transport must look usable — a failover chain is
        // only as deliverable as its members.
        foreach ($transports as $mailerName => $transport) {
            if (! $this->transportLooksUsable((string) $mailerName, $transport)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Expand a mailer name into its effective transports (mailer-name ⇒
     * transport), unwrapping failover/roundrobin composites recursively with
     * CYCLE detection via the visited set.
     *
     * Returns null when the graph is invalid: undefined mailer name, a
     * composite that references itself (directly or indirectly), an empty
     * composite, or a member without a transport. Callers must treat null as
     * NOT configured — Laravel's mail manager would reject it at send time.
     *
     * @param  array<int,string>  $visited
     * @return array<string,string>|null
     */
    private function effectiveTransports(string $mailer, array $visited = []): ?array
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            return null;
        }
        $visited[] = $mailer;

        $conf = config("mail.mailers.{$mailer}");
        if (! is_array($conf)) {
            return null;
        }

        $transport = (string) ($conf['transport'] ?? '');
        if ($transport === '') {
            return null;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = array_values(array_filter((array) ($conf['mailers'] ?? [])));
            if ($children === []) {
                return null;
            }
            $out = [];
            foreach ($children as $child) {
                $childTransports = $this->effectiveTransports((string) $child, $visited);
                if ($childTransports === null) {
                    return null;
                }
                $out += $childTransports;
            }

            return $out;
        }

        return [$mailer => $transport];
    }

    /**
     * Per-transport plausibility check WITHOUT exposing any credential values:
     * only presence/shape is inspected, nothing is returned or logged.
     * Unknown transports fail conservatively.
     */
    private function transportLooksUsable(string $mailerName, string $transport): bool
    {
        return match ($transport) {
            // Non-delivery transports: rejected in production by the caller;
            // in dev/test they are intentionally accepted.
            'log', 'array' => true,
            'smtp' => (string) config("mail.mailers.{$mailerName}.host") !== ''
                && (int) config("mail.mailers.{$mailerName}.port") > 0,
            'sendmail' => (string) config("mail.mailers.{$mailerName}.path", '/usr/sbin/sendmail -bs') !== '',
            // config/services.php has a region DEFAULT (us-east-1), so region
            // alone proves nothing — require the explicit key pair this
            // repository's configuration actually reads.
            'ses', 'ses-v2' => (string) config('services.ses.key') !== ''
                && (string) config('services.ses.secret') !== ''
                && (string) config('services.ses.region') !== '',
            'postmark' => (string) config('services.postmark.key') !== '',
            'resend' => (string) config('services.resend.key') !== '',
            'mailgun' => (string) config('services.mailgun.secret') !== ''
                && (string) config('services.mailgun.domain') !== '',
            default => false,
        };
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) SiteSetting::get('email_otp_ttl_minutes', self::CODE_TTL_MINUTES));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) SiteSetting::get('email_otp_max_attempts', self::MAX_ATTEMPTS));
    }

    public function resendCooldownSeconds(): int
    {
        return max(0, (int) SiteSetting::get('email_otp_resend_cooldown_seconds', self::RESEND_COOLDOWN_SEC));
    }

    /** Maximum OTP emails per user/email in a rolling 24h window. */
    public function dailyCap(): int
    {
        return max(1, (int) SiteSetting::get('email_otp_daily_cap', self::DAILY_CAP));
    }

    // ── Resend gating ────────────────────────────────────────────────────────

    public function canResend(User $user): bool
    {
        return $this->resendCooldownRemaining($user) === 0;
    }

    /** Seconds until the user may request another code (0 = allowed now). */
    public function resendCooldownRemaining(User $user): int
    {
        $latest = EmailVerificationCode::where('user_id', $user->id)
            ->where('email', $user->email)
            ->whereNull('used_at')
            ->latest('id')
            ->first();
        if ($latest === null) {
            return 0;
        }
        $availableAt = $latest->created_at->addSeconds($this->resendCooldownSeconds());
        $remaining = now()->diffInSeconds($availableAt, false);

        return $remaining > 0 ? (int) ceil($remaining) : 0;
    }

    public function reachedDailyCap(User $user): bool
    {
        return EmailVerificationCode::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('email', $user->email);
        })
            ->where('created_at', '>=', now()->subDay())
            // Dispatch-failed records never queued an email — counting them
            // would let a transient queue outage burn the daily budget.
            ->where('send_status', '!=', EmailVerificationCode::SEND_STATUS_FAILED)
            ->count() >= $this->dailyCap();
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    /**
     * Generate, store (hashed) and queue an OTP email for the user.
     *
     * HONEST result contract: `email_sent` is true only when the message was
     * successfully handed to the queue (final delivery is tracked on the
     * record's send_status by the job). A failed dispatch or unusable mail
     * configuration returns status=error — never "code sent".
     *
     * @return array{status:string, message:string, email_sent?:bool}
     */
    public function requestCode(User $user, array $meta = []): array
    {
        // The administrator's disable switch is authoritative even for direct
        // POSTs to the resend endpoint — no records, no mail while disabled.
        if (! $this->isEnabled()) {
            return ['status' => 'error', 'message' => 'تایید ایمیل در حال حاضر غیرفعال است.', 'email_sent' => false];
        }

        if (! $this->isMailConfigured()) {
            return ['status' => 'error', 'message' => 'ارسال ایمیل در حال حاضر پیکربندی نشده است. لطفاً بعداً تلاش کنید یا با پشتیبانی تماس بگیرید.', 'email_sent' => false];
        }

        // Serialize issuance per user: the resend/daily-cap gates, the
        // invalidation, and the insert all run while holding the user row
        // lock, so two concurrent requests cannot both pass the gates.
        [$record, $code, $limited] = DB::transaction(function () use ($user, $meta) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if (! $this->canResend($user)) {
                return [null, null, 'برای ارسال مجدد کد کمی صبر کنید.'];
            }
            if ($this->reachedDailyCap($user)) {
                return [null, null, 'تعداد درخواست کد تایید در شبانه‌روز به حداکثر رسیده است. لطفاً فردا دوباره تلاش کنید.'];
            }

            // Single active code: invalidate every previous unused code first.
            $this->invalidateCodes($user);

            $code = (string) random_int(100000, 999999);

            // Recorded as QUEUED up front: the sync driver (and a fast Redis
            // worker) can run the job before this method resumes, and the
            // job's terminal `sent` state must never be overwritten.
            $record = EmailVerificationCode::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'attempts' => 0,
                'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
                'ip_address' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ]);

            return [$record, $code, null];
        });

        if ($limited !== null) {
            return ['status' => 'rate_limited', 'message' => $limited, 'email_sent' => false];
        }

        try {
            // afterCommit inside the job: it is dispatched only after the
            // surrounding database transaction (if any) commits.
            SendEmailOtpJob::dispatch($record->id, $user->email, $code, $this->ttlMinutes());
        } catch (Throwable $e) {
            // NEVER pretend the code was sent when the dispatch itself failed.
            // The record is consumed (used_at) so this never-queued attempt
            // doesn't hold the resend cooldown against an immediate retry, and
            // the stored error is a SANITIZED category — raw transport/queue
            // exception text can echo credentials.
            $record->forceFill([
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'send_error' => MailFailure::summarize('queue dispatch failed', $e),
                'used_at' => now(),
            ])->save();

            return ['status' => 'error', 'message' => 'ارسال ایمیل با خطا مواجه شد. لطفاً دوباره تلاش کنید.', 'email_sent' => false];
        }

        // HONEST wording: the code is QUEUED for delivery — nothing has been
        // handed to a mail transport yet, so we never claim it was "sent".
        return ['status' => 'queued', 'message' => 'کد تایید در صف ارسال قرار گرفت.', 'email_sent' => true];
    }

    // ── Verify ───────────────────────────────────────────────────────────────

    /**
     * Verify a submitted OTP for the user's CURRENT email address.
     *
     * @return array{status:string, message:string}
     */
    public function verify(User $user, string $code): array
    {
        // ATOMIC consumption: the USER row is locked first (serializing this
        // path against changeAddress(), which takes the same lock), then the
        // code row; the attempt counter increments under the lock, success
        // claims the code with a conditional update, and email_verified_at is
        // written in the SAME transaction — so a racing address change can
        // never end with the new, unproven address marked verified by a code
        // issued to the old one.
        $outcome = DB::transaction(function () use ($user, $code) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                return ['status' => 'error', 'message' => 'کد تایید یافت نشد. یک کد جدید درخواست کنید.'];
            }

            $record = EmailVerificationCode::where('user_id', $lockedUser->id)
                ->where('email', $lockedUser->email)
                ->whereNull('used_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return ['status' => 'error', 'message' => 'کد تایید یافت نشد. یک کد جدید درخواست کنید.'];
            }

            if ($record->isExpired()) {
                return ['status' => 'expired', 'message' => 'کد تایید منقضی شده است. یک کد جدید درخواست کنید.'];
            }

            if ($record->attempts >= $this->maxAttempts()) {
                return ['status' => 'too_many_attempts', 'message' => 'تعداد تلاش‌ها بیش از حد مجاز است. یک کد جدید درخواست کنید.'];
            }

            $record->increment('attempts');

            if (! Hash::check($code, $record->code_hash)) {
                return ['status' => 'invalid', 'message' => 'کد تایید اشتباه است.'];
            }

            // Claim the single-use code: only one request can flip used_at.
            $claimed = EmailVerificationCode::whereKey($record->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            if ($claimed !== 1) {
                return ['status' => 'error', 'message' => 'کد تایید یافت نشد. یک کد جدید درخواست کنید.'];
            }
            $this->invalidateCodes($lockedUser);

            $newlyVerified = false;
            if ($lockedUser->email_verified_at === null) {
                $lockedUser->forceFill(['email_verified_at' => now()])->save();
                $newlyVerified = true;
            }

            return [
                'status' => 'verified',
                'message' => 'ایمیل شما با موفقیت تایید شد.',
                'newly_verified' => $newlyVerified,
            ];
        });

        if (($outcome['newly_verified'] ?? false) === true) {
            event(new Verified($user->refresh()));
        }
        unset($outcome['newly_verified']);

        return $outcome;
    }

    /** Mark every unused code for this user as consumed. */
    public function invalidateCodes(User $user): void
    {
        EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }
}
