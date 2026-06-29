<?php

namespace App\Services\Phone;

use App\Models\PhoneVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Sms\SmsService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;

/**
 * OTP-based phone verification.
 *
 * Codes are 6-digit numeric, hashed at rest. TTL, max attempts and resend
 * cooldown are configurable from the admin SMS settings (with safe defaults).
 * SMS delivery goes through SmsService and never throws.
 */
class PhoneVerificationService
{
    // Defaults — overridden by admin settings at runtime.
    public const CODE_TTL_MINUTES    = 5;
    public const MAX_ATTEMPTS        = 5;
    public const RESEND_COOLDOWN_SEC = 60;

    public function __construct(
        private readonly SmsService $sms,
    ) {}

    // ── Settings ─────────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get('phone_verification_enabled', false);
    }

    public function isRequiredOnRegister(): bool
    {
        // Required state only takes effect when verification AND SMS sending are
        // both available, so a misconfigured "required" flag can never lock users out.
        return $this->isEnabled()
            && (bool) SiteSetting::get('phone_verification_required_on_register', false)
            && $this->sms->isConfigured();
    }

    /** Alias matching the task spec. */
    public function isVerificationRequired(): bool
    {
        return $this->isRequiredOnRegister();
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) SiteSetting::get('otp_ttl_minutes', self::CODE_TTL_MINUTES));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) SiteSetting::get('otp_max_attempts', self::MAX_ATTEMPTS));
    }

    public function resendCooldownSeconds(): int
    {
        return max(0, (int) SiteSetting::get('otp_resend_cooldown_seconds', self::RESEND_COOLDOWN_SEC));
    }

    public function normalizePhone(string $phone): ?string
    {
        return PhoneNumber::normalize($phone);
    }

    // ── Resend gating ────────────────────────────────────────────────────────

    public function canResend(User $user): bool
    {
        $normalized = $user->normalized_phone ?? PhoneNumber::normalize((string) $user->phone);
        if ($normalized === null) {
            return false;
        }

        return ! PhoneVerificationCode::where('normalized_phone', $normalized)
            ->whereNull('used_at')
            ->where('created_at', '>=', now()->subSeconds($this->resendCooldownSeconds()))
            ->exists();
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    /**
     * Generate, store (hashed) and send an OTP for the user's phone.
     *
     * @return array{status:string, message:string, code?:string, sms_sent?:bool}
     */
    public function requestCode(User $user, array $meta = []): array
    {
        if (! $user->hasPhone()) {
            return ['status' => 'error', 'message' => 'ابتدا شماره موبایل خود را وارد کنید.'];
        }

        if (! $this->canResend($user)) {
            return ['status' => 'rate_limited', 'message' => 'برای ارسال مجدد کد کمی صبر کنید.'];
        }

        $normalized = $user->normalized_phone ?? PhoneNumber::normalize((string) $user->phone);

        // Invalidate previous unused codes for this phone.
        PhoneVerificationCode::where('normalized_phone', $normalized)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = (string) random_int(100000, 999999);

        $record = PhoneVerificationCode::create([
            'user_id'          => $user->id,
            'phone'            => $user->phone,
            'normalized_phone' => $normalized,
            'code_hash'        => Hash::make($code),
            'expires_at'       => now()->addMinutes($this->ttlMinutes()),
            'attempts'         => 0,
            'ip_address'       => $meta['ip'] ?? null,
            'user_agent'       => $meta['user_agent'] ?? null,
        ]);

        // Best-effort SMS delivery — never throws.
        $smsSent = $this->sms->sendOtp($normalized, $code);

        $record->update([
            'send_status' => $smsSent
                ? PhoneVerificationCode::SEND_STATUS_SENT
                : ($this->sms->isConfigured()
                    ? PhoneVerificationCode::SEND_STATUS_FAILED
                    : PhoneVerificationCode::SEND_STATUS_SKIPPED),
            'send_error'  => $smsSent ? null : ($this->sms->isConfigured() ? 'ارسال پیامک ناموفق بود.' : null),
        ]);

        return [
            'status'   => 'sent',
            'message'  => 'کد تایید ارسال شد.',
            'code'     => $code, // returned for internal/test use; never shown to end users
            'sms_sent' => $smsSent,
        ];
    }

    /** Alias matching the task spec — returns whether the OTP was dispatched. */
    public function sendCode(User $user, ?string $phone = null, array $meta = []): bool
    {
        if ($phone !== null) {
            $normalized = PhoneNumber::normalize($phone);
            if ($normalized !== null) {
                $user->update([
                    'phone'                => $phone,
                    'normalized_phone'     => $normalized,
                    'profile_completed_at' => $user->profile_completed_at ?? now(),
                ]);
            }
        }

        $result = $this->requestCode($user, $meta);
        return ($result['sms_sent'] ?? false) === true;
    }

    // ── Verify ───────────────────────────────────────────────────────────────

    /**
     * Verify a submitted OTP for the user.
     *
     * @return array{status:string, message:string}
     */
    public function verify(User $user, string $code): array
    {
        $normalized = $user->normalized_phone ?? PhoneNumber::normalize((string) $user->phone);

        $record = PhoneVerificationCode::where('normalized_phone', $normalized)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $record) {
            return ['status' => 'error', 'message' => 'کد تایید منقضی شده است.'];
        }

        if ($record->isExpired()) {
            return ['status' => 'expired', 'message' => 'کد تایید منقضی شده است.'];
        }

        if ($record->attempts >= $this->maxAttempts()) {
            return ['status' => 'too_many_attempts', 'message' => 'تعداد تلاش‌ها بیش از حد مجاز است.'];
        }

        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            return ['status' => 'invalid', 'message' => 'کد تایید اشتباه است.'];
        }

        $record->update(['used_at' => now()]);
        $user->update([
            'phone_verified_at'    => now(),
            'profile_completed_at' => $user->profile_completed_at ?? now(),
        ]);

        return ['status' => 'verified', 'message' => 'شماره موبایل با موفقیت تایید شد.'];
    }

    /** Alias matching the task spec — returns whether verification succeeded. */
    public function verifyCode(User $user, string $code): bool
    {
        return $this->verify($user, $code)['status'] === 'verified';
    }
}
