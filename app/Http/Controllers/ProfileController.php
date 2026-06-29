<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Phone\PhoneVerificationService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly PhoneVerificationService $phoneVerification,
    ) {}

    public function index(): View
    {
        return view('dashboard.profile', [
            'user'                  => auth()->user(),
            'verificationEnabled'   => $this->phoneVerification->isEnabled(),
        ]);
    }

    /**
     * Profile completion gate shown before sensitive actions when the user
     * has no phone (or needs to verify it when verification is required).
     */
    public function complete(Request $request): View|RedirectResponse
    {
        $user = auth()->user();

        // Already complete → bounce back to where they were going (or dashboard).
        if ($this->isProfileComplete($user)) {
            return redirect()->intended(route('dashboard.index'));
        }

        return view('dashboard.profile-complete', [
            'user'                => $user,
            'verificationEnabled' => $this->phoneVerification->isEnabled(),
            'verificationRequired'=> $this->phoneVerification->isRequiredOnRegister(),
            'intended'            => $request->query('intended'),
        ]);
    }

    public function savePhone(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $request->validate(
            ['phone' => ['required', 'string', 'max:32']],
            ['phone.required' => 'وارد کردن شماره موبایل الزامی است.'],
        );

        $normalized = PhoneNumber::normalize($request->input('phone'));
        if ($normalized === null) {
            return back()->withErrors(['phone' => 'شماره موبایل معتبر نیست.']);
        }

        $exists = User::where('normalized_phone', $normalized)
            ->where('id', '!=', $user->id)
            ->exists();
        if ($exists) {
            return back()->withErrors(['phone' => 'این شماره موبایل قبلاً ثبت شده است.']);
        }

        // Changing the phone resets verification.
        $user->update([
            'phone'                => $request->input('phone'),
            'normalized_phone'     => $normalized,
            'phone_verified_at'    => null,
            'profile_completed_at' => $user->profile_completed_at ?? now(),
        ]);

        return back()->with('success', 'شماره موبایل ذخیره شد.');
    }

    public function sendOtp(): RedirectResponse
    {
        if (! $this->phoneVerification->isEnabled()) {
            return back()->withErrors(['otp' => 'تایید شماره موبایل غیرفعال است.']);
        }

        $result = $this->phoneVerification->requestCode(auth()->user(), [
            'ip'         => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);

        if ($result['status'] === 'rate_limited') {
            return back()->withErrors(['otp' => $result['message']]);
        }

        if ($result['status'] !== 'sent') {
            return back()->withErrors(['otp' => $result['message']]);
        }

        return back()->with('success', $result['message']);
    }

    public function verifyPhone(Request $request): RedirectResponse
    {
        $request->validate(
            ['code' => ['required', 'string', 'max:6']],
            ['code.required' => 'کد تایید را وارد کنید.'],
        );

        $result = $this->phoneVerification->verify(auth()->user(), $request->input('code'));

        if ($result['status'] === 'verified') {
            return redirect()->intended(route('dashboard.profile'))->with('success', $result['message']);
        }

        return back()->withErrors(['code' => $result['message']]);
    }

    private function isProfileComplete(User $user): bool
    {
        if (! $user->hasPhone()) {
            return false;
        }
        if ($this->phoneVerification->isRequiredOnRegister() && ! $user->hasVerifiedPhone()) {
            return false;
        }
        return true;
    }
}
