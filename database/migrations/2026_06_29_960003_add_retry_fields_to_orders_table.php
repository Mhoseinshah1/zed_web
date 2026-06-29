<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds failed-operation management fields to orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'last_retry_at')) {
                $table->timestamp('last_retry_at')->nullable()->after('addon_apply_failed_reason');
            }
            if (! Schema::hasColumn('orders', 'failure_reviewed_at')) {
                $table->timestamp('failure_reviewed_at')->nullable()->after('last_retry_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['last_retry_at', 'failure_reviewed_at']);
        });
    }
};
