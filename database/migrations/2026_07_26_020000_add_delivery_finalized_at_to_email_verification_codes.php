<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated, immutable delivery-outcome timestamp: `updated_at` is the
 * model's GENERAL mutation time — verify()/invalidateCodes() touching an old
 * `sent` row would make it look like a freshly finalized success and resume
 * enforcement during a live outage. Every terminal send_status transition
 * (sent / accepted_pending / failed / skipped / dispatch_failed) now stamps
 * `delivery_finalized_at`, and transportLooksLive() windows/orders on it.
 * The outcome-health index follows (drop-and-recreate converges earlier
 * builds under the same name). Idempotent both ways.
 */
return new class extends Migration
{
    private const INDEX = 'email_verification_codes_outcome_health_index';

    public function up(): void
    {
        if (! Schema::hasColumn('email_verification_codes', 'delivery_finalized_at')) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->timestamp('delivery_finalized_at')->nullable();
            });
        }

        if (Schema::hasIndex('email_verification_codes', self::INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->index(['send_status', 'delivery_finalized_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('email_verification_codes', self::INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        if (Schema::hasColumn('email_verification_codes', 'delivery_finalized_at')) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->dropColumn('delivery_finalized_at');
            });
        }
    }
};
