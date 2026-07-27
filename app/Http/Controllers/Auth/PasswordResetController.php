<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

/**
 * Guest-only OTP password reset — thin HTTP layer over
 * PasswordResetService.
 *
 * NON-ENUMERATION: every syntactically valid request step returns the same
 * status, redirect, message and validation shape whether or not the account
 * exists, is eligible, or its delivery channel works. The submitted
 * identifier never appears in logs, URLs or redirect query strings; the
 * flow's only client-side state is one opaque token in the SESSION.
 *
 * All pages are noindex (route middleware) and the OTP/new-password
 * responses carry Cache-Control: no-store.
 */
class PasswordResetController extends Controller
{
    /** Generic message shown for EVERY reset request outcome. */
    public const GENERIC_REQUEST_MESSAGE = 'اگر حسابی با این مشخصات وجود داشته باشد، کد تایید ارسال می‌شود.';

    public function __construct(private readonly PasswordResetService $service) {}

    public function showRequest(): Response
    {
        return $this->noStore(response()->view('auth.forgot-password'));
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ], [
            'identifier.required' => 'ایمیل یا شماره موبایل خود را وارد کنید.',
        ]);

        // Service is never-throw and returns a decoy token for nonexistent /
        // ineligible accounts, so this path is IDENTICAL for everyone.
        $token = $this->service->request((string) $request->input('identifier'));
        $request->session()->put(PasswordResetService::SESSION_TOKEN_KEY, $token);

        return redirect()
            ->route('password.verify')
            ->with('status', self::GENERIC_REQUEST_MESSAGE);
    }

    public function showVerify(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has(PasswordResetService::SESSION_TOKEN_KEY)) {
            return redirect()->route('password.request');
        }

        return $this->noStore(response()->view('auth.reset-verify'));
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'کد تایید را وارد کنید.',
            'code.digits' => 'کد تایید باید ۶ رقم باشد.',
        ]);

        $token = $request->session()->get(PasswordResetService::SESSION_TOKEN_KEY);

        $proof = $this->service->verifyCode(
            is_string($token) ? $token : null,
            (string) $request->input('code'),
        );

        if ($proof === null) {
            return back()->withErrors([
                'code' => 'کد وارد شده نامعتبر یا منقضی شده است.',
            ]);
        }

        // The authorization proof lives ONLY in this guest session: finalize
        // must present it again, so no other session can use the challenge.
        $request->session()->put(PasswordResetService::SESSION_PROOF_KEY, $proof);

        return redirect()->route('password.reset');
    }

    public function showReset(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has(PasswordResetService::SESSION_TOKEN_KEY)) {
            return redirect()->route('password.request');
        }

        return $this->noStore(response()->view('auth.reset-password'));
    }

    public function update(Request $request): RedirectResponse
    {
        // Same policy as registration: confirmed + minimum length 8.
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $token = $request->session()->get(PasswordResetService::SESSION_TOKEN_KEY);
        $proof = $request->session()->get(PasswordResetService::SESSION_PROOF_KEY);

        $ok = $this->service->finalize(
            is_string($token) ? $token : null,
            is_string($proof) ? $proof : null,
            (string) $validated['password'],
        );

        if (! $ok) {
            // One generic terminal failure for every invalid/expired/stolen/
            // concurrent-loser state — restart the flow.
            $request->session()->forget([PasswordResetService::SESSION_TOKEN_KEY, PasswordResetService::SESSION_PROOF_KEY]);

            return redirect()
                ->route('password.request')
                ->withErrors(['identifier' => 'فرایند بازیابی نامعتبر یا منقضی شده است. لطفاً دوباره تلاش کنید.']);
        }

        // Success: clear the guest reset state and rotate the session — and
        // deliberately DO NOT authenticate the user.
        $request->session()->forget([PasswordResetService::SESSION_TOKEN_KEY, PasswordResetService::SESSION_PROOF_KEY]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'رمز عبور با موفقیت تغییر کرد. اکنون می‌توانید وارد شوید.');
    }

    /** Sensitive step responses must never be cached anywhere. */
    private function noStore(Response $response): Response
    {
        return $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
