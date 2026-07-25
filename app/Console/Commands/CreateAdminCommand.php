<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CreateAdminCommand extends Command
{
    protected $signature = 'zedproxy:create-admin
        {--email=    : Admin email address (stored; not used for login)}
        {--username= : Admin username for panel login}
        {--name=     : Admin display name (defaults to --username when omitted)}
        {--password= : Plain-text password (will be hashed). Falls back to ZEDPROXY_ADMIN_PASS env var.}';

    protected $description = 'Create or update the ZedProxy admin user (safe to re-run)';

    public function handle(): int
    {
        // Same normalization the User model applies on save: without it, a
        // re-run with different letter casing would miss the case-sensitive
        // lookup and then collide with the lower(email) unique index instead
        // of updating the existing admin.
        $email = strtolower(trim((string) $this->option('email')));
        $username = $this->option('username');
        $name = $this->option('name') ?: $username;
        $password = $this->option('password') ?: env('ZEDPROXY_ADMIN_PASS');

        if (empty($email) || empty($username) || empty($password)) {
            $this->error('--email, --username, and --password (or ZEDPROXY_ADMIN_PASS env var) are all required.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        // Look up by email OR username to avoid duplicate admin records on
        // re-runs. Case-insensitive on email: rows written before the
        // normalization invariant may still carry mixed case.
        $user = User::whereRaw('lower(email) = ?', [$email])->orWhere('username', $username)->first();

        $attributes = [
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ];

        if ($user) {
            // Route the update through the SAME lock-protected path as every
            // other address writer (per-user cache lock → User row → OTP
            // rows): a rerun changing the email can never race an in-flight
            // OTP delivery into sending to the replaced mailbox. The explicit
            // mark_verified action reproduces the command's re-verify
            // semantics and invalidates stale codes with the same commit.
            try {
                app(EmailVerificationService::class)->applyAdminUpdate($user, [
                    ...$attributes,
                    'is_admin' => true,
                    'email_verification_action' => 'mark_verified',
                ]);
            } catch (ValidationException $e) {
                $this->error('Admin update failed: '.implode(' ', Arr::flatten($e->errors())));

                return self::FAILURE;
            }
            $this->info("Admin user updated: {$username} <{$email}>");
        } else {
            $user = User::create($attributes);
            $this->info("Admin user created: {$username} <{$email}>");

            // is_admin is not mass-assignable (privilege field) — set it explicitly.
            $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        }

        return self::SUCCESS;
    }
}
