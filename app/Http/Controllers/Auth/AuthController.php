<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginThrottleSettings;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
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
                'ip'       => $request->ip(),
                'seconds'  => $seconds,
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
            'ip'       => $request->ip(),
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
        return 'login:' . Str::lower((string) $request->input('username')) . '|' . $request->ip();
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
            \Illuminate\Support\Facades\Cookie::queue(
                'referral_code',
                $code,
                60 * 24 * \App\Services\Referrals\ReferralSettings::referralCookieDays(),
            );
        }

        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'unique:users,username', 'regex:/^[a-zA-Z0-9_]+$/'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'username.regex'  => 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و خط زیر باشد.',
            'username.unique' => 'این نام کاربری قبلاً ثبت شده است.',
            'email.unique'    => 'این ایمیل قبلاً ثبت شده است.',
            'phone.required'  => 'وارد کردن شماره موبایل الزامی است.',
        ]);

        // Normalize and validate the Iranian mobile number.
        $normalized = PhoneNumber::normalize($validated['phone']);
        if ($normalized === null) {
            return back()->withErrors(['phone' => 'شماره موبایل معتبر نیست.'])->onlyInput('name', 'username', 'email');
        }

        if (User::where('normalized_phone', $normalized)->exists()) {
            return back()->withErrors(['phone' => 'این شماره موبایل قبلاً ثبت شده است.'])->onlyInput('name', 'username', 'email');
        }

        $user = User::create([
            'name'             => $validated['name'],
            'username'         => $validated['username'],
            'email'            => $validated['email'],
            'phone'            => $validated['phone'],
            'normalized_phone' => $normalized,
            'password'         => Hash::make($validated['password']),
        ]);

        // Attach the referrer from ?ref= / session / cookie (mode-aware, safe).
        $referralCode = $request->input('ref')
            ?? $request->session()->pull('referral_code')
            ?? $request->cookie('referral_code');
        app(\App\Services\Referrals\ReferralService::class)->attachReferrer($user, $referralCode);
        \Illuminate\Support\Facades\Cookie::queue(\Illuminate\Support\Facades\Cookie::forget('referral_code'));

        Auth::login($user);

        // When OTP verification is mandatory at registration, send the code and
        // route the user to the verification page before they can do anything.
        $phoneVerification = app(\App\Services\Phone\PhoneVerificationService::class);
        if ($phoneVerification->isRequiredOnRegister()) {
            $phoneVerification->requestCode($user, [
                'ip'         => $request->ip(),
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
