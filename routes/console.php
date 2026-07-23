<?php

use App\Scheduling\ScheduleRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All scheduled tasks are defined in App\Scheduling\ScheduleRegistrar so the
// exact definition can be unit-tested against a throwaway Schedule. Driven in
// production by a single cron entry: `* * * * * php artisan schedule:run`.
App::make(ScheduleRegistrar::class)(App::make(Schedule::class));
