<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the transportLooksLive() stall probes, which run on EVERY
 * registration and every protected request by an obligated user:
 *
 *  - stalled backlog: max(created_at) over send_status='queued' rows with
 *    used_at IS NULL — covered by (send_status, used_at, created_at), which
 *    also serves the query as a backward index scan for the MAX;
 *  - abandoned claims: max(delivery_claimed_at) over send_status='sending'
 *    rows — covered by (send_status, delivery_claimed_at).
 *
 * Without these, each probe scans the entire status bucket, whose `queued`
 * portion grows precisely while the queue is degraded (optional
 * registrations and resends keep publishing rows no worker consumes) — the
 * probes would get linearly slower exactly when they run on every request.
 * The existing (send_status, delivery_finalized_at) outcome-health index
 * already covers the worker-recovery-proof lookup. Idempotent both ways.
 */
return new class extends Migration
{
    private const STALL_INDEX = 'email_verification_codes_stall_probe_index';

    private const CLAIM_INDEX = 'email_verification_codes_abandoned_claim_index';

    public function up(): void
    {
        if (! Schema::hasIndex('email_verification_codes', self::STALL_INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->index(['send_status', 'used_at', 'created_at'], self::STALL_INDEX);
            });
        }
        if (! Schema::hasIndex('email_verification_codes', self::CLAIM_INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->index(['send_status', 'delivery_claimed_at'], self::CLAIM_INDEX);
            });
        }
    }

    public function down(): void
    {
        foreach ([self::STALL_INDEX, self::CLAIM_INDEX] as $index) {
            if (Schema::hasIndex('email_verification_codes', $index)) {
                Schema::table('email_verification_codes', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }
};
