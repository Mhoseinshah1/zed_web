<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginThrottleSettings;
use App\Services\Email\EmailVerificationService;
use App\Services\Phone\PhoneVerificationService;
use App\Services\Referrals\ReferralService;
use App\Services\Referrals\ReferralSettings;
use App\Services\Seo\SeoManager;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Support\EmailUniqueViolationProbe;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
        app(SeoManager::class)->forKey('login');

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Brute-force protection: throttle by username + IP. After too many
        // failed attempts the pair is locked for a configurable cooldown.
        $throttleKey = $this->loginThrottleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, LoginThrottleSettings::maxAttempts())) {
            $seconds = RateLimiter::availableIn($throttleKey);

            Log::warning('Login throttled — too many failed attempts', [
                'username' => $request->input('username'),
                'ip' => $request->ip(),
                'seconds' => $seconds,
            ]);

            return back()->withErrors([
                'username' => $this->lockoutMessage($seconds),
            ])->onlyInput('username');
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard.index'));
        }

        // Count this failure and (never the password) log it for monitoring.
        RateLimiter::hit($throttleKey, LoginThrottleSettings::lockoutSeconds());

        Log::warning('Failed login attempt', [
            'username' => $request->input('username'),
            'ip' => $request->ip(),
        ]);

        return back()->withErrors([
            'username' => 'نام کاربری یا رمز عبور اشتباه است.',
        ])->onlyInput('username');
    }

    /**
     * Throttle key for the login limiter — scoped to the submitted username
     * and the client IP so one attacker can't lock out every account.
     */
    private function loginThrottleKey(Request $request): string
    {
        return 'login:'.Str::lower((string) $request->input('username')).'|'.$request->ip();
    }

    /**
     * Persian lockout message including the remaining wait time.
     */
    private function lockoutMessage(int $seconds): string
    {
        if ($seconds >= 60) {
            $minutes = (int) ceil($seconds / 60);

            return "تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً پس از {$minutes} دقیقه دوباره تلاش کنید.";
        }

        return "تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً پس از {$seconds} ثانیه دوباره تلاش کنید.";
    }

    public function showRegister(Request $request)
    {
        // Remember a referral code from ?ref= so it survives until registration.
        if ($request->filled('ref')) {
            $code = strtoupper(trim((string) $request->query('ref')));
            $request->session()->put('referral_code', $code);
            Cookie::queue(
                'referral_code',
                $code,
                60 * 24 * ReferralSettings::referralCookieDays(),
            );
        }

        app(SeoManager::class)->forKey('register');

        return view('auth.register');
    }

    public function register(Request $request)
    {
        // Normalize BEFORE validation: the uniqueness check must run against
        // the exact value that will be stored, and it must be CASE-INSENSITIVE
        // — PostgreSQL's plain varchar unique index would otherwise accept
        // Victim@X alongside victim@x and 500 on the eventual collision.
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'unique:users,username', 'regex:/^[a-zA-Z0-9_]+$/'],
            'email' => [
                'required', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::whereRaw('lower(email) = ?', [strtolower(trim((string) $value))])->exists()) {
                        $fail('این ایمیل قبلاً ثبت شده است.');
                    }
                },
            ],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'username.regex' => 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و خط زیر باشد.',
            'username.unique' => 'این نام کاربری قبلاً ثبت شده است.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
            'phone.required' => 'وارد کردن شماره موبایل الزامی است.',
        ]);

        // Normalize and validate the Iranian mobile number.
        $normalized = PhoneNumber::normalize($validated['phone']);
        if ($normalized === null) {
            return back()->withErrors(['phone' => 'شماره موبایل معتبر نیست.'])->onlyInput('name', 'username', 'email');
        }

        if (User::where('normalized_phone', $normalized)->exists()) {
            return back()->withErrors(['phone' => 'این شماره موبایل قبلاً ثبت شده است.'])->onlyInput('name', 'username', 'email');
        }

        $referralCode = $request->input('ref')
            ?? $request->session()->pull('referral_code')
            ?? $request->cookie('referral_code');

        // ONE effective-policy decision for this whole registration: the
        // EFFECTIVE requirement (enabled + raw toggle + usable mail + valid
        // transport-test proof + live transport health) is resolved exactly
        // once INSIDE the registration transaction — under a shared lock on
        // the policy rows, so a concurrent admin policy save serializes with
        // the marker write — persisted on the user as an immutable marker,
        // and reused unchanged for every post-commit flow decision.
        $emailVerification = app(EmailVerificationService::class);
        $phoneRequired = app(PhoneVerificationService::class)->isRequiredOnRegister();
        $emailRequiredForThisRegistration = false;

        // ONE transaction for the whole registration write set: the user row
        // and its referral attachment commit together or not at all — no
        // half-registered users with lost referrals. Side effects (Telegram,
        // OTP dispatch, login) run strictly AFTER the commit, so a rollback
        // produces no notification and no queued email.
        try {
            $user = DB::transaction(function () use ($validated, $normalized, $referralCode, $emailVerification, &$emailRequiredForThisRegistration) {
                $emailRequiredForThisRegistration = $emailVerification->captureRequiredPolicyForRegistration();

                $user = User::create([
                    'name' => $validated['name'],
                    'username' => $validated['username'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'normalized_phone' => $normalized,
                    'password' => Hash::make($validated['password']),
                ]);

                // The immutable per-user obligation marker commits WITH the
                // user row: registered under enforced verification or not —
                // never rewritten by later policy/proof changes.
                $user->forceFill([
                    'email_verification_required_at_registration' => $emailRequiredForThisRegistration,
                ])->save();

                // Attach the referrer from ?ref= / session / cookie (mode-aware, safe).
                app(ReferralService::class)->attachReferrer($user, $referralCode);

                return $user;
            });
        } catch (QueryException $e) {
            // TOCTOU race: two registrations can both pass the pre-validation
            // and collide on the DB's email uniqueness (the final authority).
            // Only EMAIL unique violations become a normal validation error —
            // anything else (username/account_id/referral/…) stays a real
            // exception. The losing transaction rolled back completely: no
            // user, no referral, no Telegram, no OTP, no job.
            EmailUniqueViolationProbe::translateOrRethrow($e);
        }

        Cookie::queue(Cookie::forget('referral_code'));

        // Admin Telegram — new registration (safe summary only; no
        // credentials). After commit: a rolled-back registration never
        // notifies, a committed one notifies exactly once.
        app(TelegramAdminNotifier::class)->event('user_registered', [
            'user' => $user->name ?? $user->username,
            'account' => (string) $user->id,
        ], $user);

        Auth::login($user);

        // Email verification comes FIRST in the onboarding order (email →
        // phone/profile → dashboard). The user is authenticated in a limited
        // unverified state; the OTP job dispatches after the transaction
        // commits (afterCommit) and the message is HONEST about the outcome.
        // When the feature is disabled this block is skipped entirely — no
        // code, no records, unchanged legacy behavior.
        //
        // A REQUIRED phone step is never abandoned: when email verification is
        // merely OPTIONAL while phone verification is required, the mandatory
        // phone flow below runs exactly as before (email verification stays
        // available from the profile). When email verification is REQUIRED,
        // the phone OTP is sent right after the email step succeeds (see
        // EmailVerificationController::verify).
        // All flow decisions reuse the SAME captured policy values resolved
        // before the transaction — never re-evaluated mid-request.
        if ($emailRequiredForThisRegistration
            || ($emailVerification->isEnabled() && ! $phoneRequired)) {
            if ($emailVerification->isMailConfigured()) {
                $result = $emailVerification->requestCode($user, [
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ]);

                return redirect()
                    ->route('verification.notice')
                    ->with(
                        ($result['email_sent'] ?? false) ? 'success' : 'error',
                        $result['message'],
                    );
            }

            // Enabled but the mailer is UNUSABLE: create no OTP record and
            // never strand the user on a page whose codes can't arrive —
            // straight to the dashboard with an honest warning. (A "required"
            // flag can't reach here: isRequiredOnRegister() already demands a
            // usable mail configuration.)
            return redirect()
                ->route('dashboard.index')
                ->with('warning', 'ارسال ایمیل تایید در حال حاضر امکان‌پذیر نیست. می‌توانید بعداً از صفحه پروفایل، ایمیل خود را تایید کنید.');
        }

        // When OTP verification is mandatory at registration, send the code and
        // route the user to the verification page before they can do anything.
        $phoneVerification = app(PhoneVerificationService::class);
        if ($phoneVerification->isRequiredOnRegister()) {
            $phoneVerification->requestCode($user, [
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return redirect()
                ->route('dashboard.profile.complete')
                ->with('success', 'کد تایید ارسال شد.');
        }

        return redirect()->route('dashboard.index');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
