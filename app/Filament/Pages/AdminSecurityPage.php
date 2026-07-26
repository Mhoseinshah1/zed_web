<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminSecurityAudit;
use App\Services\AdminMfa\AdminStepUpService;
use App\Services\AdminMfa\AdminTotpService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * امنیت حساب ادمین — administrator MFA self-management.
 *
 * Shows ONLY non-secret state. Every mutating flow (recovery-code
 * regeneration, authenticator replacement) requires the CURRENT password plus
 * a FRESH live TOTP code, verified server-side immediately before acting.
 * There is deliberately NO "disable" action: an admin can never leave their
 * account without a confirmed second factor — the replacement flow confirms
 * the NEW secret before the old one is removed, and only the emergency CLI
 * (`php artisan zedproxy:admin-2fa-reset`) can clear a factor outright.
 */
class AdminSecurityPage extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.admin-security';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'سیستم';

    protected static ?string $navigationLabel = 'امنیت حساب ادمین';

    protected static ?string $title = 'امنیت حساب ادمین (ورود دومرحله‌ای)';

    protected static ?string $slug = 'security/admin-mfa';

    protected static ?int $navigationSort = 95;

    /** Replacement in progress: locally rendered QR + manual key. */
    public bool $replacing = false;

    public ?string $replacementQr = null;

    public ?string $replacementKey = null;

    /** Bound to the replacement confirmation input. */
    public ?string $replacement_code = null;

    /** One-time display of freshly generated recovery codes. */
    public ?array $freshRecoveryCodes = null;

    // ── Non-secret state for the view ────────────────────────────────────────

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function totp(): AdminTotpService
    {
        return app(AdminTotpService::class);
    }

    public function totpActive(): bool
    {
        return $this->totp()->hasConfirmedCredential($this->user());
    }

    public function enrolledAt(): ?string
    {
        return $this->totp()->credentialFor($this->user())?->confirmed_at?->format('Y-m-d H:i');
    }

    public function recoveryCodesRemaining(): int
    {
        return $this->totp()->recoveryCodesRemaining($this->user());
    }

    public function recoveryCodesGeneratedAt(): ?string
    {
        return $this->totp()->credentialFor($this->user())?->recovery_codes_generated_at?->format('Y-m-d H:i');
    }

    public function sessionMfaVerified(): bool
    {
        return AdminMfaSession::markerValid($this->user());
    }

    public function sessionViaRecovery(): bool
    {
        return AdminMfaSession::enteredViaRecovery();
    }

    public function stepUpActive(): bool
    {
        return app(AdminStepUpService::class)->hasActiveGrant($this->user());
    }

    // ── Guards shared by both flows ──────────────────────────────────────────

    /**
     * Password + fresh live TOTP, rate-limited — the reauthentication gate in
     * front of every factor-changing operation. Consumes the code's step on
     * success (it cannot be replayed anywhere else).
     */
    private function reauthenticate(string $password, string $code): bool
    {
        $limits = (array) app(\Illuminate\Cache\RateLimiter::class)->limiter('admin-totp')(request());
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                Notification::make()->title('تعداد تلاش بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید.')->danger()->send();

                return false;
            }
        }
        foreach ($limits as $limit) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }

        $user = $this->user();

        if (! Hash::check($password, (string) $user->getAuthPassword())
            || $this->totp()->verifyAndConsume($user, $code) === null) {
            AdminSecurityAudit::record('mfa_management_reauth', $user, 'failure');
            Notification::make()->title('رمز عبور یا کد تایید معتبر نیست.')->danger()->send();

            return false;
        }

        return true;
    }

    // ── Recovery-code regeneration ───────────────────────────────────────────

    public function regenerateRecoveryCodesAction(): Action
    {
        return Action::make('regenerateRecoveryCodes')
            ->label('ساخت مجدد کدهای بازیابی')
            ->color('warning')
            ->icon('heroicon-o-arrow-path')
            ->form([
                Forms\Components\TextInput::make('current_password')
                    ->label('رمز عبور فعلی')->password()->required()->autocomplete('current-password'),
                Forms\Components\TextInput::make('totp_code')
                    ->label('کد ۶ رقمی فعلی Authenticator')->required()->maxLength(6)
                    ->autocomplete('one-time-code'),
            ])
            ->action(function (array $data): void {
                if (! $this->reauthenticate((string) $data['current_password'], (string) $data['totp_code'])) {
                    return;
                }

                $codes = $this->totp()->regenerateRecoveryCodes($this->user());
                if ($codes === null) {
                    Notification::make()->title('ابتدا باید ورود دومرحله‌ای فعال باشد.')->danger()->send();

                    return;
                }

                $this->freshRecoveryCodes = $codes;
                AdminSecurityAudit::record('recovery_codes_regenerated', $this->user(), 'success');
                Notification::make()->title('کدهای بازیابی جدید ساخته شد — کدهای قبلی دیگر معتبر نیستند.')->success()->send();
            });
    }

    // ── Authenticator replacement (new factor confirmed BEFORE old removed) ──

    public function startReplacementAction(): Action
    {
        return Action::make('startReplacement')
            ->label('جایگزینی برنامه Authenticator')
            ->color('danger')
            ->icon('heroicon-o-device-phone-mobile')
            ->form([
                Forms\Components\TextInput::make('current_password')
                    ->label('رمز عبور فعلی')->password()->required()->autocomplete('current-password'),
                Forms\Components\TextInput::make('totp_code')
                    ->label('کد ۶ رقمی فعلی Authenticator')->required()->maxLength(6)
                    ->autocomplete('one-time-code'),
            ])
            ->action(function (array $data): void {
                if (! $this->reauthenticate((string) $data['current_password'], (string) $data['totp_code'])) {
                    return;
                }

                $user = $this->user();
                $enrollment = $this->totp()->startEnrollment($user);

                // The server-side replacement record is the ONLY authorization
                // confirmReplacement() accepts — created here, immediately
                // after password + live-code verification, bound to this
                // session and this exact pending secret. $replacing below is
                // pure presentation state.
                $cred = $this->totp()->credentialFor($user);
                if ($cred === null) {
                    return; // unreachable after reauthenticate(); fail closed
                }
                AdminMfaSession::startReplacement($user, $cred);

                $this->replacing = true;
                $this->replacementQr = (string) QrCode::size(200)->generate($enrollment['otpauth']);
                $this->replacementKey = $enrollment['secret'];

                AdminSecurityAudit::record('totp_replacement_started', $user, 'success');
            });
    }

    public function confirmReplacement(): void
    {
        $user = $this->user();
        $code = (string) $this->replacement_code;
        $this->replacement_code = null;

        // Authorization = the server-side record, never Livewire state: a
        // client-forged $replacing changes nothing. An invalid/expired/
        // mismatched record tears the whole flow down, pending secret
        // included.
        if (! AdminMfaSession::replacementValid($user)) {
            $this->teardownReplacement();
            AdminSecurityAudit::record('totp_replacement_denied', $user, 'failure');
            Notification::make()->title('جلسه جایگزینی معتبر نیست یا منقضی شده است. دوباره شروع کنید.')->danger()->send();

            return;
        }

        $result = $this->totp()->confirmEnrollment($user, $code);
        if ($result === null) {
            // Wrong code on the new device: retry stays possible while the
            // record is valid.
            Notification::make()->title('کد وارد شده معتبر نیست. کد را از دستگاه جدید وارد کنید.')->danger()->send();

            return;
        }

        // New factor is live — consume the record (one replacement per
        // reauthentication). The confirmed_at re-stamp already invalidated
        // every OTHER session's marker and every step-up grant bound to the
        // old factor. Re-stamp THIS session's marker against the new version
        // and drop any local grant explicitly.
        AdminMfaSession::clearReplacement();
        AdminStepUpService::clearGrant();
        $cred = $this->totp()->credentialFor($user);
        AdminMfaSession::markVerified($user, (int) $cred?->last_verified_timestep, 'totp');

        $this->replacing = false;
        $this->replacementQr = null;
        $this->replacementKey = null;
        $this->freshRecoveryCodes = $result['codes'];

        AdminSecurityAudit::record('totp_replaced', $user, 'success');
        Notification::make()->title('برنامه Authenticator با موفقیت جایگزین شد. کدهای بازیابی جدید را ذخیره کنید.')->success()->send();
    }

    public function cancelReplacement(): void
    {
        $this->teardownReplacement();
    }

    /**
     * Abort a replacement everywhere it lives: the server-side record, the
     * database pending secret + timestamp, and the QR/manual-key/code
     * component state.
     */
    private function teardownReplacement(): void
    {
        AdminMfaSession::clearReplacement();
        $this->totp()->abandonPendingSecret($this->user());

        $this->replacing = false;
        $this->replacementQr = null;
        $this->replacementKey = null;
        $this->replacement_code = null;
    }

    /** Explicitly clear the one-time recovery-code display. */
    public function dismissFreshRecoveryCodes(): void
    {
        $this->freshRecoveryCodes = null;
    }
}
