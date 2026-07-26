<?php

namespace App\Filament\Pages\Concerns;

use App\Models\User;
use App\Services\AdminMfa\AdminSecurityAudit;
use App\Services\AdminMfa\AdminStepUpService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ONE shared implementation of the `admin_sensitive_communications` step-up
 * for the email + SMS settings pages — the challenge logic is never
 * duplicated per page.
 *
 * Contract for a using page:
 *   - mount() calls initializeStepUpState() FIRST and hydrates its form ONLY
 *     when $stepUpUnlocked is true — a locked page never fills, decrypts,
 *     derives placeholders from, or snapshots any sensitive value.
 *   - EVERY save/test/mutating action calls assertStepUpForAction() as its
 *     first statement and aborts when it returns false.
 *
 * $stepUpUnlocked is PRESENTATION ONLY (which screen renders). Authorization
 * is always the server-side grant re-validated by AdminStepUpService on every
 * call — a crafted Livewire request flipping the property changes nothing.
 */
trait RequiresCommunicationsStepUp
{
    /** Presentation flag — never trusted as authorization. */
    public bool $stepUpUnlocked = false;

    /** Bound to the locked-screen challenge input. */
    public ?string $step_up_code = null;

    protected function stepUpService(): AdminStepUpService
    {
        return app(AdminStepUpService::class);
    }

    protected function stepUpUser(): ?User
    {
        $user = Auth::user();

        return ($user instanceof User && $user->is_admin === true) ? $user : null;
    }

    /** Evaluate the server-side grant for the initial render decision. */
    protected function initializeStepUpState(): void
    {
        $user = $this->stepUpUser();
        $this->stepUpUnlocked = $user !== null && $this->stepUpService()->hasActiveGrant($user);
    }

    /** Locked-screen submit: verify a fresh live TOTP code. */
    public function unlockSensitiveSettings(): void
    {
        $user = $this->stepUpUser();
        if ($user === null) {
            return;
        }

        // Same two-bucket limiter shape as the login challenges, consumed
        // manually because Livewire actions bypass route middleware.
        $limits = (array) app(\Illuminate\Cache\RateLimiter::class)->limiter('admin-step-up')(request());
        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                Notification::make()
                    ->title('تعداد تلاش بیش از حد مجاز است. چند دقیقه دیگر دوباره تلاش کنید.')
                    ->danger()->send();

                return;
            }
        }
        foreach ($limits as $limit) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }

        $code = (string) $this->step_up_code;
        $this->step_up_code = null;

        if (! $this->stepUpService()->attemptStepUp($user, $code)) {
            Notification::make()
                ->title('کد وارد شده معتبر نیست. کد ۶ رقمی فعلی برنامه Authenticator را وارد کنید.')
                ->danger()->send();

            return;
        }

        // Fresh full remount so the unlocked page hydrates from persisted
        // values — never by mutating half-initialized locked state.
        $this->redirect(static::getUrl());
    }

    /** Explicit "lock sensitive settings now". */
    public function lockSensitiveSettingsNow(): void
    {
        AdminStepUpService::clearGrant();
        AdminSecurityAudit::record('step_up_locked', $this->stepUpUser(), 'success', ['scope' => AdminStepUpService::SCOPE]);

        $this->redirect(static::getUrl());
    }

    /**
     * MANDATORY first statement of every sensitive action. Re-validates the
     * server-side grant immediately before execution; on failure nothing may
     * mutate — transient secret fields are cleared, the page relocks, and the
     * admin returns to the challenge.
     */
    protected function assertStepUpForAction(): bool
    {
        $user = $this->stepUpUser();
        if ($user !== null && $this->stepUpService()->hasActiveGrant($user)) {
            return true;
        }

        AdminSecurityAudit::record('step_up', $user, 'expired', ['scope' => AdminStepUpService::SCOPE]);

        $this->clearTransientSecretState();
        $this->stepUpUnlocked = false;

        Notification::make()
            ->title('اعتبار دسترسی به تنظیمات حساس به پایان رسیده است. برای ادامه، دوباره کد تایید را وارد کنید.')
            ->danger()->send();

        $this->redirect(static::getUrl());

        return false;
    }

    /** Seconds left on the grant (for the unlocked-page countdown). */
    public function stepUpRemainingSeconds(): int
    {
        $user = $this->stepUpUser();

        return $user === null ? 0 : $this->stepUpService()->remainingSeconds($user);
    }

    /** Pages override to drop any in-memory secret input on denial. */
    protected function clearTransientSecretState(): void {}
}
