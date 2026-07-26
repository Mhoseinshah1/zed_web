<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminSecurityAudit;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Pages\Auth\Login
{
    /**
     * Precomputed VALID bcrypt hash (cost 12, random preimage discarded at
     * generation time) used to burn one real password verification when the
     * username doesn't exist. Precomputed so the unknown-username path never
     * pays bcrypt() twice (hash + check) while the known-username path pays
     * it once — per-request hash generation was itself a timing signal.
     */
    private const TIMING_EQUALIZER_HASH = '$2y$12$9V/AYo80GSp8O.kYzSY6kOlzjOA3wQu.E1MkyYb9ojpgV2RKnTkb2';

    /**
     * PHASE ONE of the mandatory two-phase admin login. A valid username +
     * password NEVER creates an authenticated session here — it only proves
     * the password, regenerates the session id (fixation), stores the
     * minimal pending-MFA state, and hands off to the TOTP challenge /
     * forced-enrollment routes. Auth::login() happens exclusively after MFA
     * succeeds, which is also the only moment a remember cookie may be
     * issued. Failure output is the ONE generic message for every cause —
     * unknown username, wrong password, non-admin — no enumeration.
     */
    public function authenticate(): ?LoginResponse
    {
        try {
            // Independent password-stage limiter (Filament's per-component,
            // per-IP bucket) — the TOTP and recovery challenges have their
            // own separate limiters on their routes.
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);

        $provider = Filament::auth()->getProvider();
        $user = $provider->retrieveByCredentials(['username' => $credentials['username']]);

        // Constant-shape failure path: when the username doesn't exist, still
        // burn one hash verification so response timing can't enumerate
        // accounts.
        if ($user === null) {
            Hash::check((string) $credentials['password'], self::TIMING_EQUALIZER_HASH);

            $this->throwFailureValidationException();
        }

        if (! $provider->validateCredentials($user, ['password' => $credentials['password']])) {
            $this->throwFailureValidationException();
        }

        if (
            ! ($user instanceof FilamentUser)
            || ! ($user instanceof User)
            || ! $user->canAccessPanel(Filament::getCurrentPanel())
            || $user->is_admin !== true
        ) {
            // Same generic error as a wrong password — admin-ness is not
            // disclosed.
            $this->throwFailureValidationException();
        }

        session()->regenerate();
        AdminMfaSession::startPending($user, (bool) ($data['remember'] ?? false));
        AdminSecurityAudit::record('mfa_password_stage', $user, 'success');

        $this->redirect(route('zed-admin.mfa.challenge'));

        return null;
    }

    /**
     * Replace the email field with a plain username text input.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('نام کاربری')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Pass username+password credentials to Laravel's auth driver.
     * EloquentUserProvider::retrieveByCredentials() will query WHERE username = ?
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    /**
     * Point the validation error at data.username (not data.email).
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
