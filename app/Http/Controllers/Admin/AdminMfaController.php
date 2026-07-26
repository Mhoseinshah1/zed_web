<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminSecurityAudit;
use App\Services\AdminMfa\AdminTotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrator MFA challenge + forced first-time enrollment.
 *
 * These routes are OUTSIDE the Filament panel (a pending user is not
 * authenticated, so panel routes reject them) and serve two subjects:
 *   1. the pending-login user (password verified, MFA outstanding), and
 *   2. an already-authenticated admin whose session lacks a valid MFA marker
 *      (e.g. they logged in through the customer form) — the panel middleware
 *      sends them here before any admin page renders.
 *
 * All responses are no-store: enrollment shows a provisioning QR/key and the
 * one-time recovery codes, none of which may land in a browser or proxy cache.
 * Generic Persian errors only — nothing reveals whether a username exists, is
 * an admin, or has TOTP configured.
 */
class AdminMfaController extends Controller
{
    public const GENERIC_ERROR = 'کد وارد شده معتبر نیست.';

    public function __construct(private readonly AdminTotpService $totp) {}

    // ── TOTP challenge ───────────────────────────────────────────────────────

    public function challenge(Request $request): Response
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        if (! $this->totp->hasConfirmedCredential($user)) {
            return redirect()->route('zed-admin.mfa.enroll');
        }

        return $this->noStore(response()->view('admin.mfa.challenge'));
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        $code = (string) $request->string('code');

        $step = $this->totp->verifyAndConsume($user, $code);
        if ($step === null) {
            AdminSecurityAudit::record('mfa_challenge', $user, 'failure', ['via' => 'totp']);

            return back()->withErrors(['code' => self::GENERIC_ERROR]);
        }

        AdminSecurityAudit::record('mfa_challenge', $user, 'success', ['via' => 'totp']);

        return $this->completeLogin($user, $step, 'totp');
    }

    // ── Recovery-code challenge (login only — never step-up) ─────────────────

    public function recovery(Request $request): Response
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        if (! $this->totp->hasConfirmedCredential($user)) {
            return redirect()->route('zed-admin.mfa.enroll');
        }

        return $this->noStore(response()->view('admin.mfa.recovery'));
    }

    public function verifyRecovery(Request $request): RedirectResponse
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        $code = (string) $request->string('recovery_code');

        if (! $this->totp->consumeRecoveryCode($user, $code)) {
            AdminSecurityAudit::record('mfa_challenge', $user, 'failure', ['via' => 'recovery']);

            return back()->withErrors(['recovery_code' => self::GENERIC_ERROR]);
        }

        AdminSecurityAudit::record('recovery_code_used', $user, 'success', [
            'remaining_recovery_codes' => $this->totp->recoveryCodesRemaining($user),
        ]);

        // No step consumed: a recovery entry can never satisfy step-up.
        return $this->completeLogin($user, null, 'recovery');
    }

    // ── Forced enrollment ────────────────────────────────────────────────────

    public function enroll(Request $request): Response
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        if ($this->totp->hasConfirmedCredential($user)) {
            return redirect()->route('zed-admin.mfa.challenge');
        }

        AdminSecurityAudit::record('mfa_enrollment_started', $user, 'success');

        $enrollment = $this->totp->startEnrollment($user);

        // QR is rendered LOCALLY (bacon-qr-code via simple-qrcode); the
        // otpauth secret never leaves this server.
        $qrSvg = (string) QrCode::size(220)->generate($enrollment['otpauth']);

        return $this->noStore(response()->view('admin.mfa.enroll', [
            'qrSvg' => $qrSvg,
            'manualKey' => $enrollment['secret'],
        ]));
    }

    public function confirmEnroll(Request $request): Response
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        if ($this->totp->hasConfirmedCredential($user)) {
            return redirect()->route('zed-admin.mfa.challenge');
        }

        $result = $this->totp->confirmEnrollment($user, (string) $request->string('code'));
        if ($result === null) {
            AdminSecurityAudit::record('mfa_enrollment_confirm', $user, 'failure');

            return back()->withErrors(['code' => self::GENERIC_ERROR]);
        }

        AdminSecurityAudit::record('mfa_enrollment_completed', $user, 'success');

        // The ONLY place an enrollment-completion record is created: a live
        // code just proved possession and promoted the pending secret. The
        // record (no secrets — ids, version, consumed step, expiry) is what
        // authorizes the acknowledge hand-off. Recovery codes are rendered
        // DIRECTLY in this response, exactly once — never flashed into the
        // session.
        $cred = $this->totp->credentialFor($user);
        if ($cred !== null) {
            AdminMfaSession::putEnrollmentCompletion($user, $cred);
        }

        return $this->noStore(response()->view('admin.mfa.recovery-codes', [
            'codes' => $result['codes'],
        ]));
    }

    public function acknowledgeRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $this->subject();
        if ($user === null) {
            return redirect('/zed-admin/login');
        }

        // Only a one-time completion record minted by a successful
        // confirmEnrollment() in THIS session, for THIS user, against the
        // CURRENT credential version, and still inside its window may finish
        // the login here. Being authenticated is NOT sufficient: an admin
        // whose factor was already confirmed earlier takes the normal code
        // challenge instead. The record is consumed on this attempt either
        // way — it can never authorize twice.
        $step = AdminMfaSession::consumeEnrollmentCompletion($user);
        if ($step === null || ! $this->totp->hasConfirmedCredential($user)) {
            return redirect()->route('zed-admin.mfa.challenge');
        }

        return $this->completeLogin($user, $step, 'totp');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The MFA subject: the pending-login user, or an authenticated admin
     * whose session lacks a valid marker (routed here by the panel gate).
     */
    private function subject(): ?User
    {
        $pending = AdminMfaSession::pendingUser();
        if ($pending !== null) {
            return $pending;
        }

        $user = Auth::user();

        return ($user instanceof User && $user->is_admin === true) ? $user : null;
    }

    /**
     * MFA proven: authenticate (if not already), regenerate the session id
     * again, clear ALL pending state, and stamp the server-side marker.
     */
    private function completeLogin(User $user, ?int $step, string $via): RedirectResponse
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $user->id) {
            // The remember cookie is issued only NOW — never before MFA.
            Auth::login($user, AdminMfaSession::pendingRemember());
        }

        session()->regenerate();
        AdminMfaSession::clearPending();
        AdminMfaSession::markVerified($user, $step, $via);

        if ($via === 'recovery') {
            session()->flash('zp_admin_recovery_warning', true);
        }

        return redirect()->intended('/zed-admin');
    }

    /** Sensitive auth flows must never be cached by browsers or proxies. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
