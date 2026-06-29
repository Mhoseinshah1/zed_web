<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('renewal_enabled')->default(true)->after('sort_order');
            $table->unsignedBigInteger('renewal_price')->nullable()->after('renewal_enabled');
            $table->unsignedInteger('renewal_duration_days')->nullable()->after('renewal_price');
            $table->boolean('renewal_cashback_enabled')->default(false)->after('renewal_duration_days');
            $table->string('renewal_cashback_type')->nullable()->after('renewal_cashback_enabled');
            $table->unsignedBigInteger('renewal_cashback_value')->nullable()->after('renewal_cashback_type');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'renewal_enabled',
                'renewal_price',
                'renewal_duration_days',
                'renewal_cashback_enabled',
                'renewal_cashback_type',
                'renewal_cashback_value',
            ]);
        });
    }
};
