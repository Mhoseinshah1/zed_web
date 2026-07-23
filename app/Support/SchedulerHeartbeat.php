<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Records and reads the "last successful scheduler run" heartbeat.
 *
 * The heartbeat is written every minute by a scheduled command; a stale (or
 * missing) heartbeat means `php artisan schedule:run` is not firing from cron.
 *
 * It is stored in the file cache store by default (config zedproxy.scheduler.
 * heartbeat_store) so it survives a Redis outage — the very situation in which
 * we most need to know whether the scheduler is alive.
 */
class SchedulerHeartbeat
{
    public const KEY = 'zedproxy:scheduler:last_run_at';

    /** Persist "the scheduler ran just now". */
    public static function record(): void
    {
        self::store()->forever(self::KEY, CarbonImmutable::now()->getTimestamp());
    }

    /** The last recorded run, or null if the scheduler has never run. */
    public static function lastRunAt(): ?CarbonImmutable
    {
        $ts = self::store()->get(self::KEY);

        if ($ts === null || ! is_numeric($ts)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) $ts);
    }

    /** Seconds since the last run, or null if never run. */
    public static function ageSeconds(): ?int
    {
        $last = self::lastRunAt();

        return $last === null ? null : max(0, CarbonImmutable::now()->getTimestamp() - $last->getTimestamp());
    }

    /**
     * Whether the scheduler looks healthy: it has run within the configured
     * threshold. A never-run scheduler is NOT healthy.
     */
    public static function isHealthy(?int $thresholdSeconds = null): bool
    {
        $age = self::ageSeconds();
        if ($age === null) {
            return false;
        }

        $threshold = $thresholdSeconds ?? (int) config('zedproxy.scheduler.heartbeat_threshold', 300);

        return $age <= max(60, $threshold);
    }

    /** Clear the heartbeat (used by tests). */
    public static function clear(): void
    {
        self::store()->forget(self::KEY);
    }

    private static function store(): CacheRepository
    {
        return Cache::store(config('zedproxy.scheduler.heartbeat_store', 'file'));
    }
}
