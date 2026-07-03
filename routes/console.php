<?php

use App\Models\SiteSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Background Marzban sync — registered only when admin enabled it, and limited
// to failed + pending services (never a full sync of every service).
// Guarded by Schema check so it is safe to load before migrations run.
if (Schema::hasTable('site_settings') && SiteSetting::get('marzban_background_sync_enabled', false)) {
    Schedule::command('zedproxy:sync-marzban-services')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->runInBackground();

    // Hourly panel health checks.
    Schedule::command('zedproxy:check-marzban-panels')
        ->hourly()
        ->withoutOverlapping();
}

// Automatic server backup — registered every minute; the command itself
// decides whether a backup is due (fixed_time vs interval mode, last run),
// so admins can change scheduling from the panel without touching cron.
// Not-due runs exit immediately and cost nothing.
if (Schema::hasTable('site_settings') && SiteSetting::get('backup_enabled', false) && SiteSetting::get('backup_auto_enabled', false)) {
    Schedule::command('zedproxy:backup --scheduled')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}

// Daily Telegram report — only when enabled.
if (Schema::hasTable('site_settings') && SiteSetting::get('telegram_admin_enabled', false) && SiteSetting::get('daily_report_enabled', false)) {
    $time = (string) SiteSetting::get('daily_report_time', '21:00');
    $time = preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '21:00';

    Schedule::command('zedproxy:telegram-daily-report')
        ->dailyAt($time)
        ->withoutOverlapping();
}
