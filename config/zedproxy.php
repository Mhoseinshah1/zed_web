<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | The Laravel scheduler is driven in production by a single cron entry
    | (`* * * * * php artisan schedule:run`). These settings keep the health
    | heartbeat working even when Redis — the default cache store — is
    | temporarily unavailable.
    |
    | The scheduler LOCK store (withoutOverlapping + the run-lock) is configured
    | separately via config('cache.schedule_store') ← SCHEDULER_LOCK_STORE, which
    | is the framework-native knob; it is pinned to the file store so overlap
    | prevention keeps working during a Redis outage.
    |
    | heartbeat_store:   Cache store where the last successful scheduler run is
    |                    recorded. File-based so the heartbeat survives Redis
    |                    outages and is readable by health/admin diagnostics.
    | heartbeat_threshold: Seconds after which a missing heartbeat means the
    |                    scheduler is considered NOT running (cron fires every
    |                    minute, so a few minutes of silence is a real failure).
    |
    */

    'scheduler' => [
        'heartbeat_store'     => env('SCHEDULER_HEARTBEAT_STORE', 'file'),
        'heartbeat_threshold' => (int) env('SCHEDULER_HEARTBEAT_THRESHOLD', 300),
    ],

];
