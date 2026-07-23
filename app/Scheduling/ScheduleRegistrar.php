<?php

namespace App\Scheduling;

use App\Models\SiteSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for the application's scheduled tasks.
 *
 * routes/console.php delegates here so the exact same definition can be built
 * against a throwaway Schedule in tests (with settings toggled) — proving which
 * tasks are registered under which conditions.
 *
 * Every task uses withoutOverlapping(); the scheduler mutex store is pinned to
 * the file cache (see AppServiceProvider) so overlap prevention keeps working
 * during a Redis outage.
 */
class ScheduleRegistrar
{
    public function __invoke(Schedule $schedule): void
    {
        // Heartbeat — always registered so scheduler health can be monitored.
        $schedule->command('zedproxy:scheduler-heartbeat')
            ->everyMinute()
            ->withoutOverlapping();

        // The rest depend on admin settings; guard the table so this is safe to
        // build before migrations have run.
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        // Background Marzban sync + hourly panel health checks.
        if (SiteSetting::get('marzban_background_sync_enabled', false)) {
            $schedule->command('zedproxy:sync-marzban-services')
                ->everyFifteenMinutes()
                ->withoutOverlapping()
                ->runInBackground();

            $schedule->command('zedproxy:check-marzban-panels')
                ->hourly()
                ->withoutOverlapping();
        }

        // Automatic server backup — the command itself decides whether a backup
        // is due (mode + time/interval + last run), so admins schedule from the
        // panel without touching cron. This is the ONLY system that runs backups.
        if (SiteSetting::get('backup_enabled', false) && SiteSetting::get('backup_auto_enabled', false)) {
            $schedule->command('zedproxy:backup --scheduled')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        }

        // Daily Telegram report — only when enabled.
        if (SiteSetting::get('telegram_admin_enabled', false) && SiteSetting::get('daily_report_enabled', false)) {
            $time = (string) SiteSetting::get('daily_report_time', '21:00');
            $time = preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '21:00';

            $schedule->command('zedproxy:telegram-daily-report')
                ->dailyAt($time)
                ->withoutOverlapping();
        }
    }
}
