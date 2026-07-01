<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce that a given payment transaction can credit the wallet at most once.
 *
 * Previously `wallet_transactions.payment_transaction_id` was only indexed, so a
 * concurrent NOWPayments IPN + user "check status" could both pass the app-level
 * existence check and double-credit one payment. A UNIQUE constraint closes the
 * race at the database level (with the app catching the violation idempotently).
 *
 * Safe on existing data: duplicates are collapsed first (oldest row kept). The
 * column is nullable and both PostgreSQL and SQLite treat NULLs as distinct, so
 * manual/admin transactions (payment_transaction_id = NULL) are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallet_transactions', 'payment_transaction_id')) {
            return;
        }

        // 1. Collapse any pre-existing duplicates — keep the oldest credit per
        //    payment_transaction_id, delete the rest. (Derived table so the
        //    subquery is materialised independently of the DELETE target.)
        DB::statement(<<<'SQL'
            DELETE FROM wallet_transactions
            WHERE payment_transaction_id IS NOT NULL
              AND id NOT IN (
                SELECT keep_id FROM (
                  SELECT MIN(id) AS keep_id
                  FROM wallet_transactions
                  WHERE payment_transaction_id IS NOT NULL
                  GROUP BY payment_transaction_id
                ) AS keepers
              )
        SQL);

        // 2. Replace the plain index with a UNIQUE constraint.
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_transaction_id']);
            $table->unique('payment_transaction_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('wallet_transactions', 'payment_transaction_id')) {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['payment_transaction_id']);
            $table->index('payment_transaction_id');
        });
    }
};
