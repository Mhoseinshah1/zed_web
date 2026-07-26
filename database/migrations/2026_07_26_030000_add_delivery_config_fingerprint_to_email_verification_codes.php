<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH mail configuration produced each delivery outcome (the
 * non-secret combined config fingerprint, as seen by the process that
 * finalized the row): transportLooksLive() counts only outcomes belonging to
 * the CURRENT configuration, so a long-lived worker still running an old
 * configuration cannot finalize a stale failure AFTER the administrator has
 * certified a replacement and re-suspend enforcement of the healthy config.
 * Nullable; legacy rows (null) stay counted until they age out of the health
 * window. Idempotent both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_verification_codes', 'delivery_config_fingerprint')) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->string('delivery_config_fingerprint', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_verification_codes', 'delivery_config_fingerprint')) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->dropColumn('delivery_config_fingerprint');
            });
        }
    }
};
