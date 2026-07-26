<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports transportLooksLive(): the live-outage signal filters recent rows
 * by send_status + created_at on EVERY required-policy evaluation (middleware
 * for unverified users, registration stamping). Without an index that query
 * degrades into a scan of the whole lifetime OTP history precisely when the
 * table is large and the window is quiet. Composite (send_status, created_at)
 * serves the whereIn + range filter on both PostgreSQL and SQLite. Idempotent
 * both ways.
 */
return new class extends Migration
{
    private const INDEX = 'email_verification_codes_outcome_health_index';

    public function up(): void
    {
        if (! Schema::hasIndex('email_verification_codes', self::INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->index(['send_status', 'created_at'], self::INDEX);
            });
        }
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
