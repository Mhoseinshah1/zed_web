<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Email\EmailVerificationService;
use App\Services\Phone\PhoneVerificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The email-OTP verification flow: notice page (never sends on GET), code
 * submission, explicit POST resend, and mistyped-address correction. All
 * routes are auth + noindex + rate-limited (see routes/web.php).
 */
class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verification,
    ) {}

    /** GET /email/verify — shows the form only; NEVER sends a code. */
    public function notice(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmailAddress() || ! $this->verification->isEnabled()) {
            return redirect()->route('dashboard.index');
        }

        return view('auth.verify-email', [
            'maskedEmail' => $this->maskEmail((string) $user->email),
            'cooldownSeconds' => $this->verification->canResend($user) ? 0 : $this->verification->resendCooldownSeconds(),
            'ttlMinutes' => $this->verification->ttlMinutes(),
        ]);
    }

    /** POST /email/verify — check the submitted 6-digit code. */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'کد تایید را وارد کنید.',
            'code.digits' => 'کد تایید باید ۶ رقم باشد.',
        ]);

        $user = $request->user();

        if ($user->hasVerifiedEmailAddress()) {
            return redirect()->route('dashboard.index');
        }

        $result = $this->verification->verify($user, $validated['code']);

        if ($result['status'] !== 'verified') {
            return back()->withErrors(['code' => $result['message']]);
        }

        // Onboarding order: email → phone/profile completion → dashboard.
        $phone = app(PhoneVerificationService::class);
        if ($phone->isRequiredOnRegister() && ! $user->refresh()->hasVerifiedPhone()) {
            return redirect()->route('dashboard.profile.complete')
                ->with('success', $result['message']);
        }

        // Safe intended-destination handling: redirect()->intended only ever
        // uses the session value captured by our own middleware (same app).
        return redirect()->intended(route('dashboard.index'))
            ->with('success', $result['message']);
    }

    /** POST /email/verification/resend — explicit, rate-limited resend. */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmailAddress()) {
            return redirect()->route('dashboard.index');
        }

        $result = $this->verification->requestCode($user, [
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return back()->with(
            ($result['email_sent'] ?? false) ? 'success' : 'error',
            $result['message'],
        );
    }

    /** PATCH /email/verification/change-address — fix a mistyped email. */
    public function changeAddress(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'password' => ['required', 'current_password'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ], [
            'password.current_password' => 'رمز عبور فعلی اشتباه است.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
            'email.email' => 'آدرس ایمیل معتبر نیست.',
        ]);

        $email = strtolower(trim($validated['email']));

        if ($email === strtolower((string) $user->email)) {
            return back()->withErrors(['email' => 'این همان آدرس ایمیل فعلی شماست.']);
        }

        // Changing the address restarts verification from zero.
        $this->verification->invalidateCodes($user);
        $user->forceFill([
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        $result = $this->verification->requestCode($user, [
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return redirect()->route('verification.notice')->with(
            ($result['email_sent'] ?? false) ? 'success' : 'error',
            ($result['email_sent'] ?? false)
                ? 'آدرس ایمیل به‌روزرسانی شد و کد تایید به آدرس جدید ارسال شد.'
                : 'آدرس ایمیل به‌روزرسانی شد، اما '.$result['message'],
        );
    }

    /** Partially mask an email: ab****@example.com. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(2, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
