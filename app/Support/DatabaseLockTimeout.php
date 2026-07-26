<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Bounded row-lock waiting for the email-verification transactions.
 *
 * The per-user cache lock bounds contention BETWEEN this application's own
 * flows, but a `lockForUpdate()` can still wait on rows held by any other
 * transaction (console commands, admin tooling, a stuck connection) for the
 * database's default — effectively unbounded — lock wait. On PostgreSQL a
 * transaction-local `SET LOCAL lock_timeout` caps that wait; the setting dies
 * with the transaction, so nothing leaks to other queries on the connection.
 *
 * SQLite (tests) has no lock_timeout syntax and its whole-database locking
 * already errors out quickly — applyLocal() is a deliberate no-op there.
 */
final class DatabaseLockTimeout
{
    /** Maximum milliseconds a row-lock acquisition may wait (constant — never interpolated user input). */
    public const LOCK_TIMEOUT_MS = 2500;

    /** PostgreSQL SQLSTATE for `lock_not_available` (raised on lock_timeout). */
    public const PG_LOCK_NOT_AVAILABLE = '55P03';

    /** Apply the bounded transaction-local lock timeout. Call INSIDE the transaction, before the first lockForUpdate(). */
    public static function applyLocal(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf("SET LOCAL lock_timeout = '%dms'", self::LOCK_TIMEOUT_MS));
        }
    }

    /** Narrow detection: ONLY a lock-wait timeout, never other query errors. */
    public static function isLockTimeout(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === self::PG_LOCK_NOT_AVAILABLE;
    }
}
