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
 * Codes are 6-digit numeric, hashed at rest, expire after 5 minutes, allow at
 * most 5 verification attempts, and resend is rate limited. SMS delivery is
 * best-effort via SmsService (currently disabled safely).
 */
class PhoneVerificationService
{
    public const CODE_TTL_MINUTES   = 5;
    public const MAX_ATTEMPTS       = 5;
    public const RESEND_COOLDOWN_SEC = 60;

    public function __construct(
        private readonly SmsService $sms,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get('phone_verification_enabled', false);
    }

    public function isRequiredOnRegister(): bool
    {
        return $this->isEnabled()
            && (bool) SiteSetting::get('phone_verification_required_on_register', false);
    }

    /**
     * Generate and (best-effort) send an OTP for the given user's phone.
     *
     * @return array{status:string, message:string, code?:string, sms_sent?:bool}
     */
    public function requestCode(User $user, array $meta = []): array
    {
        if (! $user->hasPhone()) {
            return ['status' => 'error', 'message' => 'ابتدا شماره موبایل خود را وارد کنید.'];
        }

        $normalized = $user->normalized_phone ?? PhoneNumber::normalize($user->phone);

        // Rate limit: block resend within the cooldown window.
        $recent = PhoneVerificationCode::where('normalized_phone', $normalized)
            ->whereNull('used_at')
            ->where('created_at', '>=', now()->subSeconds(self::RESEND_COOLDOWN_SEC))
            ->exists();

        if ($recent) {
            return ['status' => 'rate_limited', 'message' => 'لطفاً کمی صبر کنید و دوباره تلاش کنید.'];
        }

        // Invalidate previous unused codes for this phone.
        PhoneVerificationCode::where('normalized_phone', $normalized)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = (string) random_int(100000, 999999);

        PhoneVerificationCode::create([
            'user_id'          => $user->id,
            'phone'            => $user->phone,
            'normalized_phone' => $normalized,
            'code_hash'        => Hash::make($code),
            'expires_at'       => now()->addMinutes(self::CODE_TTL_MINUTES),
            'attempts'         => 0,
            'ip_address'       => $meta['ip'] ?? null,
            'user_agent'       => $meta['user_agent'] ?? null,
        ]);

        $smsSent = $this->sms->send($normalized, "کد تایید زدپروکسی: {$code}");

        return [
            'status'   => 'sent',
            'message'  => 'کد تایید ارسال شد.',
            'code'     => $code, // returned for internal/test use; never shown to end users
            'sms_sent' => $smsSent,
        ];
    }

    /**
     * Verify a submitted OTP for the user.
     *
     * @return array{status:string, message:string}
     */
    public function verify(User $user, string $code): array
    {
        $normalized = $user->normalized_phone ?? PhoneNumber::normalize($user->phone);

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

        if ($record->attempts >= self::MAX_ATTEMPTS) {
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
}
