<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COMPATIBILITY DECISION — grandfather existing users.
 *
 * Email-OTP verification ships AFTER these accounts were created: none of them
 * ever received a verification email, so leaving email_verified_at NULL would
 * suddenly lock every existing production user out of purchases and the
 * dashboard the moment "required" verification is enabled.
 *
 * This one-time backfill marks every existing account as verified AT ITS OWN
 * CREATION TIME (created_at), which is honest ("verified since registration"
 * under the old rules) and preserves all existing non-null timestamps.
 * Users registering after deployment start with NULL and must enter the OTP.
 *
 * `UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS
 * NULL` is plain SQL-92 — safe on both PostgreSQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Irreversible by design: after the backfill we cannot distinguish
        // grandfathered accounts from genuinely verified ones, and clearing
        // timestamps would lock real users out. Intentionally a no-op.
    }
};
