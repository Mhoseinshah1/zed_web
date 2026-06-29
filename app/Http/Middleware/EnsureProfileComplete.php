<?php

namespace App\Http\Middleware;

use App\Services\Phone\PhoneVerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks sensitive actions (buy/renew/add-ons/wallet top-up) until the user
 * has completed their profile: a phone number is required, and a verified
 * phone is required when admin enabled mandatory verification.
 */
class EnsureProfileComplete
{
    public function __construct(
        private readonly PhoneVerificationService $phoneVerification,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $needsPhone = ! $user->hasPhone();
            $needsVerify = $this->phoneVerification->isRequiredOnRegister() && ! $user->hasVerifiedPhone();

            if ($needsPhone || $needsVerify) {
                return redirect()
                    ->route('dashboard.profile.complete', ['intended' => $request->fullUrl()])
                    ->with('error', 'برای ادامه، لطفاً شماره موبایل حساب کاربری خود را تکمیل کنید.');
            }
        }

        return $next($request);
    }
}
