<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit QUEUE-PUBLICATION and TRANSPORT-ATTEMPT evidence:
 *
 *  - queue_published_at — stamped only AFTER SendEmailOtpJob::dispatch()
 *    returned successfully. NULL means no confirmed queue handoff was ever
 *    recorded; a merely-created `queued` row (dispatch still in flight, or
 *    it failed, or the metadata stamp itself failed) proves nothing about
 *    queue health. Publication evidence only — never delivery evidence.
 *  - transport_attempted_at — stamped inside the claim-token-owned window
 *    immediately before Mail::send(). NULL means no real mail transport
 *    call ever began for this issuance, which is what allows a superseded
 *    delivered code to be safely restored after a zero-transport failure.
 *
 * Existing rows stay NULL — no historical evidence is invented from
 * send_status or created_at. Both timestamps are immutable once set.
 *
 * The stall/publication indexes are re-pointed at the new predicates
 * (drop-and-recreate under the SAME names converges environments that ran
 * the earlier created_at-based builds):
 *
 *  - (send_status, used_at, queue_published_at) — queued-backlog stall
 *    probe max(queue_published_at);
 *  - (send_status, delivery_claimed_at, queue_published_at) — unconsumed
 *    retired publications (skipped + claim NULL) and, via its two-column
 *    prefix, the abandoned-claim probe max(delivery_claimed_at);
 *  - (send_status, queue_published_at, delivery_finalized_at) — newest
 *    REAL publication failure (dispatch_failed + queue_published_at NULL,
 *    finalized inside the outage window);
 *  - (queue_published_at) — the publication-recovery existence check
 *    (queue_published_at > newest failure), which carries no status filter.
 *
 * Idempotent both ways; PostgreSQL and SQLite compatible.
 */
return new class extends Migration
{
    private const STALL_INDEX = 'email_verification_codes_stall_probe_index';

    private const CLAIM_INDEX = 'email_verification_codes_abandoned_claim_index';

    private const PUBLICATION_INDEX = 'email_verification_codes_publication_health_index';

    private const PUBLISHED_INDEX = 'email_verification_codes_queue_published_index';

    public function up(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('email_verification_codes', 'queue_published_at')) {
                $table->timestamp('queue_published_at')->nullable()->after('send_error');
            }
            if (! Schema::hasColumn('email_verification_codes', 'transport_attempted_at')) {
                $table->timestamp('transport_attempted_at')->nullable()->after('queue_published_at');
            }
        });

        $this->dropIndexIfExists(self::STALL_INDEX);
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->index(['send_status', 'used_at', 'queue_published_at'], self::STALL_INDEX);
        });

        $this->dropIndexIfExists(self::CLAIM_INDEX);
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->index(['send_status', 'delivery_claimed_at', 'queue_published_at'], self::CLAIM_INDEX);
        });

        if (! Schema::hasIndex('email_verification_codes', self::PUBLICATION_INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->index(['send_status', 'queue_published_at', 'delivery_finalized_at'], self::PUBLICATION_INDEX);
            });
        }

        if (! Schema::hasIndex('email_verification_codes', self::PUBLISHED_INDEX)) {
            Schema::table('email_verification_codes', function (Blueprint $table) {
                $table->index(['queue_published_at'], self::PUBLISHED_INDEX);
            });
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists(self::PUBLICATION_INDEX);
        $this->dropIndexIfExists(self::PUBLISHED_INDEX);

        // Restore the previous (2026_07_26_040000) index shapes so a rollback
        // leaves the earlier migration's state intact.
        $this->dropIndexIfExists(self::STALL_INDEX);
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->index(['send_status', 'used_at', 'created_at'], self::STALL_INDEX);
        });
        $this->dropIndexIfExists(self::CLAIM_INDEX);
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->index(['send_status', 'delivery_claimed_at', 'created_at'], self::CLAIM_INDEX);
        });

        Schema::table('email_verification_codes', function (Blueprint $table) {
            foreach (['queue_published_at', 'transport_attempted_at'] as $column) {
                if (Schema::hasColumn('email_verification_codes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function dropIndexIfExists(string $index): void
    {
        if (Schema::hasIndex('email_verification_codes', $index)) {
            Schema::table('email_verification_codes', function (Blueprint $table) use ($index) {
                $table->dropIndex($index);
            });
        }
    }
};
