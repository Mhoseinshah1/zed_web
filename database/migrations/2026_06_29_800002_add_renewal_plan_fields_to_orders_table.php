<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('original_plan_id')->nullable()->after('renewal_package_id');
            $table->unsignedBigInteger('renewal_cashback_amount')->nullable()->after('original_plan_id');
            $table->string('renewal_cashback_status')->nullable()->after('renewal_cashback_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['original_plan_id', 'renewal_cashback_amount', 'renewal_cashback_status']);
        });
    }
};
