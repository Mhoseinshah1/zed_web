<?php

namespace App\Console\Commands;

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * zedproxy:scheduler-status — operator diagnostic that reports whether the
 * Laravel scheduler is actually running (i.e. cron is firing `schedule:run`
 * every minute). Exit code 0 when healthy, 1 when stale or never run, so it can
 * be used in install/update verification and monitoring.
 */
class SchedulerStatusCommand extends Command
{
    protected $signature = 'zedproxy:scheduler-status {--json : Machine-readable output}';

    protected $description = 'Report Laravel scheduler health (last heartbeat + app timezone).';

    public function handle(): int
    {
        $last      = SchedulerHeartbeat::lastRunAt();
        $age       = SchedulerHeartbeat::ageSeconds();
        $healthy   = SchedulerHeartbeat::isHealthy();
        $timezone  = (string) config('app.timezone', 'UTC');
        $threshold = (int) config('zedproxy.scheduler.heartbeat_threshold', 300);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'healthy'            => $healthy,
                'last_run_at'        => $last?->toIso8601String(),
                'age_seconds'        => $age,
                'threshold_seconds'  => $threshold,
                'app_timezone'       => $timezone,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->info('وضعیت زمان‌بندی وظایف');
        $this->line('منطقه زمانی برنامه (App timezone): ' . $timezone);

        if ($last === null) {
            $this->error('زمان‌بندی وظایف به‌درستی اجرا نمی‌شود.');
            $this->line('آخرین اجرای موفق: —');
            return self::FAILURE;
        }

        $this->line('آخرین اجرای موفق: ' . $last->setTimezone($timezone)->format('Y-m-d H:i:s') . " ({$age} ثانیه قبل)");

        if (! $healthy) {
            $this->error('زمان‌بندی وظایف به‌درستی اجرا نمی‌شود.');
            return self::FAILURE;
        }

        $this->info('زمان‌بندی وظایف فعال است. ✅');

        return self::SUCCESS;
    }
}
