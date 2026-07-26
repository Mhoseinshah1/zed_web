<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable per-user registration policy marker: whether EFFECTIVE required
 * email verification was active at the moment this account was registered.
 *
 * A global "required since" timestamp cannot express fail-safe intervals
 * (expired mail proof, credential drift, unusable transport) during which
 * registrations were let through without a mandatory OTP — those users must
 * never be retroactively locked out when the proof recovers. The flag is
 * decided ONCE at registration from the effective runtime policy and is never
 * recalculated from created_at.
 *
 * Existing users are backfilled false (the column default): they either
 * predate the feature entirely or were grandfathered by the deployment
 * backfill — none of them registered under an enforced policy. No
 * verification timestamps are touched. Adding a nullable-free boolean with a
 * default is metadata-only on PostgreSQL 11+ (no table rewrite) and cheap on
 * SQLite. Idempotent via the column-existence guard; rollback drops the
 * column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_verification_required_at_registration')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('email_verification_required_at_registration')
                    ->default(false)
                    ->after('email_verified_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'email_verification_required_at_registration')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_verification_required_at_registration');
            });
        }
    }
};
