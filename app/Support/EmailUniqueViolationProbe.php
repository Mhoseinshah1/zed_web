<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Driver-aware detection of an EMAIL uniqueness violation on the users table —
 * and ONLY email: username/account_id/referral collisions must keep surfacing
 * as real errors. Inspects the SQLSTATE and the affected constraint/index
 * name; callers never expose either to the user.
 */
final class EmailUniqueViolationProbe
{
    public static function isEmailUniqueViolation(QueryException $e): bool
    {
        $driver = DB::connection()->getDriverName();
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = $e->getMessage();

        if ($driver === 'pgsql') {
            return $sqlState === '23505'
                && (str_contains($message, 'users_email_lower_unique')
                    || str_contains($message, 'users_email_unique'));
        }

        if ($driver === 'sqlite') {
            return str_contains($message, 'UNIQUE constraint failed')
                && (str_contains($message, 'users.email')
                    || str_contains($message, 'users_email_lower_unique'));
        }

        return false;
    }
}
