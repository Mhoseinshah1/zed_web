<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    protected $signature = 'zedproxy:create-admin
        {--email=    : Admin email address}
        {--name=     : Admin display name or username}
        {--password= : Plain-text password (will be hashed). Falls back to ZEDPROXY_ADMIN_PASS env var.}';

    protected $description = 'Create or update the ZedProxy admin user (safe to re-run)';

    public function handle(): int
    {
        $email    = $this->option('email');
        $name     = $this->option('name');
        // Accept password from option OR from env var set by install.sh
        $password = $this->option('password') ?: env('ZEDPROXY_ADMIN_PASS');

        if (empty($email) || empty($name) || empty($password)) {
            $this->error('--email, --name, and --password (or ZEDPROXY_ADMIN_PASS env var) are all required.');
            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'       => $name,
                'password'   => Hash::make($password),
                'is_admin'   => true,
                'email_verified_at' => now(),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->info("Admin user created: {$email}");
        } else {
            $this->info("Admin user updated: {$email}");
        }

        return self::SUCCESS;
    }
}
