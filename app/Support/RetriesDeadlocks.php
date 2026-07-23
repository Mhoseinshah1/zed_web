<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Bounded retry for transient DB deadlocks / serialization failures.
 *
 * Only these transient errors are retried — a validation failure (RuntimeException)
 * or any non-deadlock error propagates immediately and is NEVER retried. Retries
 * are limited and log only counts/sqlstate (no sensitive data).
 */
trait RetriesDeadlocks
{
    /**
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    protected function runWithDeadlockRetries(callable $callback, string $context = 'db')
    {
        $maxRetries = (int) config('zedproxy.discounts.deadlock_retries', 3);
        $backoffMs  = (int) config('zedproxy.discounts.deadlock_backoff_ms', 100);

        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (QueryException $e) {
                $attempt++;
                if (! $this->isTransientDeadlock($e) || $attempt > $maxRetries) {
                    throw $e;
                }

                Log::warning('db.deadlock_retry', [
                    'context'  => $context,
                    'attempt'  => $attempt,
                    'max'      => $maxRetries,
                    'sqlstate' => $e->getCode(),
                ]);

                // Linear backoff with a little jitter; usleep takes microseconds.
                usleep(($backoffMs * 1000 * $attempt) + random_int(0, 5000));
            }
        }
    }

    /**
     * A deadlock (40P01 / MySQL 1213) or serialization failure (40001).
     */
    protected function isTransientDeadlock(QueryException $e): bool
    {
        $sqlState = (string) ($e->getCode() ?? '');
        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return true;
        }

        // Driver-specific error code (MySQL 1213 deadlock, 1205 lock wait timeout).
        $driverCode = $e->errorInfo[1] ?? null;
        if (in_array($driverCode, [1213, 1205], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'could not serialize')
            || str_contains($message, 'serialization failure');
    }
}
