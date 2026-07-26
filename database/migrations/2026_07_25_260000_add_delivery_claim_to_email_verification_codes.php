<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-attempt delivery claim for the OTP job: a cryptographically random
 * token stored while a worker owns the transport attempt, so finalization
 * (sent/failed/skipped) can only ever touch the claim the SAME worker made —
 * a worker resuming after its cache lock expired can never overwrite another
 * worker's state.
 *
 * Both columns are nullable: existing (and terminal) records simply carry
 * null. No OTP/plaintext semantics change; the token itself is random,
 * secret-free material but is still hidden from serialized output. Nullable
 * column adds are metadata-only on PostgreSQL and cheap on SQLite; idempotent
 * via column-existence guards; rollback drops the columns. No index: every
 * lookup goes through the primary key. Deliberately NO uniqueness constraint —
 * retries legitimately re-claim the same record with a fresh token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('email_verification_codes', 'delivery_claim_token')) {
                $table->string('delivery_claim_token', 64)->nullable()->after('send_error');
            }
            if (! Schema::hasColumn('email_verification_codes', 'delivery_claimed_at')) {
                $table->timestamp('delivery_claimed_at')->nullable()->after('delivery_claim_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            if (Schema::hasColumn('email_verification_codes', 'delivery_claim_token')) {
                $table->dropColumn('delivery_claim_token');
            }
            if (Schema::hasColumn('email_verification_codes', 'delivery_claimed_at')) {
                $table->dropColumn('delivery_claimed_at');
            }
        });
    }
};
