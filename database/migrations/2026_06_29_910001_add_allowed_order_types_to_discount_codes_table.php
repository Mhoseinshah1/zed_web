<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allows a discount code to be restricted to specific order types
 * (new_service, renewal, extra_traffic, extra_time). Null/empty = all
 * real purchase types (wallet top-up is always excluded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('discount_codes', 'allowed_order_types')) {
                $table->json('allowed_order_types')->nullable()->after('allowed_plan_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('discount_codes', function (Blueprint $table) {
            $table->dropColumn('allowed_order_types');
        });
    }
};
