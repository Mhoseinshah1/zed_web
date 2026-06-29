<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('dashboard.index'));
        }

        return back()->withErrors([
            'username' => 'نام کاربری یا رمز عبور اشتباه است.',
        ])->onlyInput('username');
    }

    public function showRegister()
    {
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

        Auth::login($user);

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
