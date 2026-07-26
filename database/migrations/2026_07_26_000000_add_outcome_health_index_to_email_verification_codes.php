<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports transportLooksLive(): the live-outage signal filters recent rows
 * by send_status + created_at on EVERY required-policy evaluation (middleware
 * for unverified users, registration stamping). Without an index that query
 * degrades into a scan of the whole lifetime OTP history precisely when the
 * table is large and the window is quiet. Composite (send_status, updated_at)
 * serves the whereIn + FINALIZATION-time range/order on both PostgreSQL and
 * SQLite. Drop-and-recreate converges environments that ran an earlier
 * created_at build of this index under the same name. Idempotent both ways.
 */
return new class extends Migration
{
    private const INDEX = 'email_verification_codes_outcome_health_index';

    public function up(): void
    {
        if (Schema::hasIndex('email_verification_codes', self::INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->index(['send_status', 'updated_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('email_verification_codes', self::INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
