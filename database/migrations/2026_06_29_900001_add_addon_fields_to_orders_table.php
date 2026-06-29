<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds extra-traffic / extra-time (add-on) purchase fields to the orders table.
 * These are additive and nullable so existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'extra_traffic_gb')) {
                $table->unsignedInteger('extra_traffic_gb')->nullable()->after('renewal_cashback_status');
            }
            if (! Schema::hasColumn('orders', 'extra_time_days')) {
                $table->unsignedInteger('extra_time_days')->nullable()->after('extra_traffic_gb');
            }
            if (! Schema::hasColumn('orders', 'unit_price')) {
                $table->unsignedBigInteger('unit_price')->nullable()->after('extra_time_days');
            }
            if (! Schema::hasColumn('orders', 'original_data_limit')) {
                $table->unsignedBigInteger('original_data_limit')->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('orders', 'new_data_limit')) {
                $table->unsignedBigInteger('new_data_limit')->nullable()->after('original_data_limit');
            }
            if (! Schema::hasColumn('orders', 'addon_applied_at')) {
                $table->timestamp('addon_applied_at')->nullable()->after('new_data_limit');
            }
            if (! Schema::hasColumn('orders', 'addon_apply_failed_reason')) {
                $table->text('addon_apply_failed_reason')->nullable()->after('addon_applied_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'extra_traffic_gb',
                'extra_time_days',
                'unit_price',
                'original_data_limit',
                'new_data_limit',
                'addon_applied_at',
                'addon_apply_failed_reason',
            ]);
        });
    }
};
