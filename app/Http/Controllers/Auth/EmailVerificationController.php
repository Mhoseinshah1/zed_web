<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Services\Phone\PhoneVerificationService;
use Illuminate\Http\Request;

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

        $remainingMinutes = $this->verification->activeCodeRemainingMinutes($user);

        return view('auth.verify-email', [
            'maskedEmail' => $this->maskEmail((string) $user->email),
            // The REMAINING seconds only — refreshing must never restart the
            // client countdown past what the server actually enforces.
            'cooldownSeconds' => $this->verification->resendCooldownRemaining($user),
            // The REMAINING lifetime of the current ACTIONABLE code; with no
            // actionable code the configured TTL is only explanatory text for
            // the code the user is about to request.
            'ttlMinutes' => $remainingMinutes ?? $this->verification->ttlMinutes(),
            'hasActiveCode' => $remainingMinutes !== null,
            // The skip affordance mirrors the middleware exactly: only a user
            // CARRYING the per-user obligation (registration-stamped or
            // admin-imposed), while enforcement is currently possible, is
            // denied the shortcut.
            'isRequired' => $this->verification->isEnforceableNow()
                && (bool) $user->email_verification_required_at_registration,
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
        // The phone-required flow CONTINUES here: its OTP is sent now (the
        // registration handler deferred it while the email step ran).
        $phone = app(PhoneVerificationService::class);
        if ($phone->isRequiredOnRegister() && ! $user->refresh()->hasVerifiedPhone()) {
            $phone->requestCode($user, [
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

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

        // This endpoint exists ONLY for the active verification flow (fixing
        // a mistyped address before the code arrives). It must never act as a
        // hidden self-service email changer: with verification disabled, or
        // for an already-verified account, refuse before touching anything.
        if ($user->hasVerifiedEmailAddress() || ! $this->verification->isEnabled()) {
            return redirect()->route('dashboard.index');
        }

        // Normalize BEFORE validation so uniqueness is checked against the
        // value that will be stored (and case-insensitively — PostgreSQL's
        // varchar unique index would otherwise let Victim@X and victim@x
        // coexist as two accounts for one mailbox).
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'password' => ['required', 'current_password'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    $exists = User::whereRaw('lower(email) = ?', [strtolower(trim((string) $value))])
                        ->whereKeyNot($user->id)
                        ->exists();
                    if ($exists) {
                        $fail('این ایمیل قبلاً ثبت شده است.');
                    }
                },
            ],
        ], [
            'password.current_password' => 'رمز عبور فعلی اشتباه است.',
            'email.email' => 'آدرس ایمیل معتبر نیست.',
        ]);

        $email = strtolower(trim($validated['email']));

        if ($email === strtolower((string) $user->email)) {
            return back()->withErrors(['email' => 'این همان آدرس ایمیل فعلی شماست.']);
        }

        // Changing the address restarts verification from zero. The service
        // serializes this under the SAME bounded per-user lock as issuance,
        // verification and in-flight delivery — a code issued to the old
        // mailbox can never mark the new, unproven address verified, and a
        // job mid-SMTP-send blocks this briefly. On contention NOTHING
        // changes and the user simply retries.
        if (! $this->verification->changeAddressTo($user, $email)) {
            return back()->withErrors(['email' => EmailVerificationService::BUSY_MESSAGE]);
        }

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
