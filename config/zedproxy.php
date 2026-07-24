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
        'heartbeat_store' => env('SCHEDULER_HEARTBEAT_STORE', 'file'),
        'heartbeat_threshold' => (int) env('SCHEDULER_HEARTBEAT_THRESHOLD', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Purchase idempotency
    |--------------------------------------------------------------------------
    |
    | intent_ttl_minutes:    how long a signed purchase token / intent stays
    |                        valid after the form is rendered (default 30). A
    |                        submission after this window is rejected and the
    |                        user must reload the form.
    | pending_reuse_minutes: if the user already has a recent UNPAID order for
    |                        the same purchase intent, a new submission is
    |                        redirected to that order instead of creating another
    |                        (default 30).
    |
    */

    'purchase' => [
        'intent_ttl_minutes' => (int) env('PURCHASE_INTENT_TTL_MINUTES', 30),
        'pending_reuse_minutes' => (int) env('PURCHASE_PENDING_REUSE_MINUTES', 30),
        'prune_batch_size' => (int) env('PURCHASE_PRUNE_BATCH_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discount reservations
    |--------------------------------------------------------------------------
    |
    | reservation_ttl_minutes: how long a `reserved` discount temporarily holds
    | capacity before an abandoned reservation is expired by
    | `zedproxy:expire-discount-reservations` (default 30 minutes). A reserved
    | code that is not paid within this window is released back to the pool.
    |
    | deadlock_retries / deadlock_backoff_ms: bounded retry for a DB deadlock or
    | serialization failure while applying/replacing a discount under load.
    | Validation failures are NEVER retried.
    |
    | expire_batch_size: rows processed per batch by the expiration command.
    |
    */

    'discounts' => [
        'reservation_ttl_minutes' => (int) env('DISCOUNT_RESERVATION_TTL_MINUTES', 30),
        'deadlock_retries' => (int) env('DISCOUNT_DEADLOCK_RETRIES', 3),
        'deadlock_backoff_ms' => (int) env('DISCOUNT_DEADLOCK_BACKOFF_MS', 100),
        'expire_batch_size' => (int) env('DISCOUNT_EXPIRE_BATCH_SIZE', 500),
    ],

];
