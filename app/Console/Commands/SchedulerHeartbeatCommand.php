<?php

namespace App\Console\Commands;

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Records the scheduler heartbeat. Scheduled to run every minute; if cron is
 * driving `schedule:run` correctly this keeps the heartbeat fresh, and
 * zedproxy:scheduler-status / the admin panel can prove the scheduler is alive.
 *
 * Intentionally tiny and side-effect-free beyond the heartbeat write, so it is
 * safe to run every minute and never conflicts with real work.
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'zedproxy:scheduler-heartbeat';

    protected $description = 'Record the scheduler heartbeat (last successful run).';

    public function handle(): int
    {
        SchedulerHeartbeat::record();

        return self::SUCCESS;
    }
}
