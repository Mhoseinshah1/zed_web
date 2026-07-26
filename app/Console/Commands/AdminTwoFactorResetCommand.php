<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminMfa\AdminSecurityAudit;
use App\Services\AdminMfa\AdminTotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Emergency operational reset of an administrator's TOTP factor — the ONLY
 * way to remove a second factor outright (the panel supports replacement, not
 * removal). Clears the credential so the next login forces fresh enrollment,
 * and rotates the remember token so remembered sessions die; live panel
 * sessions lose access immediately because their MFA marker no longer
 * validates against any credential version. Never prints or logs a secret.
 */
class AdminTwoFactorResetCommand extends Command
{
    protected $signature = 'zedproxy:admin-2fa-reset
        {username : The administrator username}
        {--force : Skip the interactive confirmation (for scripted recovery)}';

    protected $description = 'Emergency reset of an administrator\'s two-factor (TOTP) credential — forces re-enrollment at next login';

    public function handle(AdminTotpService $totp): int
    {
        $username = (string) $this->argument('username');

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error('No user with that username exists.');

            return self::FAILURE;
        }

        if ($user->is_admin !== true) {
            $this->error('That user is not an administrator — nothing to reset.');

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Reset admin 2FA for '{$username}'? Their authenticator and recovery codes stop working immediately and enrollment is forced at next login.")) {
            $this->info('Aborted — nothing changed.');

            return self::FAILURE;
        }

        $totp->resetFor($user);

        // Remembered sessions must not outlive the factor reset.
        $user->setRememberToken(Str::random(60));
        $user->save();

        AdminSecurityAudit::record('admin_2fa_cli_reset', null, 'success', [
            'target_user_id' => $user->id,
            'command' => 'zedproxy:admin-2fa-reset',
        ]);

        $this->info('Two-factor credential cleared. The administrator must enroll a new authenticator at their next login.');

        return self::SUCCESS;
    }
}
