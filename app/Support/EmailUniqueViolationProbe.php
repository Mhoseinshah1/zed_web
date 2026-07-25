<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Driver-aware detection of an EMAIL uniqueness violation on the users table —
 * and ONLY email: username/account_id/referral collisions must keep surfacing
 * as real errors. Inspects the SQLSTATE and the affected constraint/index
 * name; callers never expose either to the user.
 */
final class EmailUniqueViolationProbe
{
    /** The single shared user-facing message for a lost email-uniqueness race. */
    public const MESSAGE = 'این ایمیل قبلاً ثبت شده است.';

    /**
     * The ONE translation every email-writing path uses: an email unique
     * violation becomes a normal field validation error (no SQLSTATE, index
     * name, or database text ever reaches the user); anything else is
     * rethrown untouched so unrelated failures still surface.
     *
     * @return never
     */
    public static function translateOrRethrow(QueryException $e, string $attribute = 'email'): void
    {
        if (self::isEmailUniqueViolation($e)) {
            throw ValidationException::withMessages([$attribute => self::MESSAGE]);
        }

        throw $e;
    }

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
