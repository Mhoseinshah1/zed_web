<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_type')->default('new_service')->after('order_number');
            $table->foreignId('user_service_id')->nullable()->constrained('user_services')->nullOnDelete()->after('plan_id');
            $table->foreignId('renewal_package_id')->nullable()->constrained('renewal_packages')->nullOnDelete()->after('user_service_id');
            $table->unsignedInteger('renewal_days')->nullable()->after('renewal_package_id');
            $table->timestamp('original_expire_at')->nullable()->after('renewal_days');
            $table->timestamp('new_expire_at')->nullable()->after('original_expire_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_service_id']);
            $table->dropForeign(['renewal_package_id']);
            $table->dropColumn([
                'order_type',
                'user_service_id',
                'renewal_package_id',
                'renewal_days',
                'original_expire_at',
                'new_expire_at',
            ]);
        });
    }
};
