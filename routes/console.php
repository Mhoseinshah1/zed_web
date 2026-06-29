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
