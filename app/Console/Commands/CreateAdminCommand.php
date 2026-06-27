<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

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
        $email    = $this->option('email');
        $username = $this->option('username');
        $name     = $this->option('name') ?: $username;
        $password = $this->option('password') ?: env('ZEDPROXY_ADMIN_PASS');

        if (empty($email) || empty($username) || empty($password)) {
            $this->error('--email, --username, and --password (or ZEDPROXY_ADMIN_PASS env var) are all required.');
            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");
            return self::FAILURE;
        }

        // Look up by email OR username to avoid duplicate admin records on re-runs
        $user = User::where('email', $email)->orWhere('username', $username)->first();

        $attributes = [
            'username'          => $username,
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make($password),
            'is_admin'          => true,
            'email_verified_at' => now(),
        ];

        if ($user) {
            $user->update($attributes);
            $this->info("Admin user updated: {$username} <{$email}>");
        } else {
            User::create($attributes);
            $this->info("Admin user created: {$username} <{$email}>");
        }

        return self::SUCCESS;
    }
}
