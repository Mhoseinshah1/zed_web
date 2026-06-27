<?php

namespace App\Console\Commands;

use App\Models\UserService;
use Illuminate\Console\Command;

class ExpireServicesCommand extends Command
{
    protected $signature   = 'services:expire';
    protected $description = 'Mark services as expired where expires_at is in the past';

    public function handle(): int
    {
        $count = UserService::where('status', UserService::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => UserService::STATUS_EXPIRED]);

        $this->info("Marked {$count} service(s) as expired.");

        return Command::SUCCESS;
    }
}
