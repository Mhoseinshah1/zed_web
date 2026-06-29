<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class BackfillAccountIds extends Command
{
    protected $signature = 'zedproxy:backfill-account-ids';

    protected $description = 'Assign a unique 6-digit numeric account_id to users that do not have one (never overwrites existing IDs).';

    public function handle(): int
    {
        $updated = 0;

        User::whereNull('account_id')->orWhere('account_id', '')->chunkById(200, function ($users) use (&$updated) {
            foreach ($users as $user) {
                // Skip if it already has one (defensive — chunk filter already excludes these).
                if (filled($user->account_id)) {
                    continue;
                }
                $user->account_id = User::generateAccountId();
                $user->saveQuietly();
                $updated++;
            }
        });

        $this->info("Account IDs backfilled for {$updated} user(s).");

        return self::SUCCESS;
    }
}
