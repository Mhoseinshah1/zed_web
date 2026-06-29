<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_packages', function (Blueprint $table) {
            $table->json('allowed_plan_ids')->nullable()->after('sort_order');
            $table->text('admin_note')->nullable()->after('allowed_plan_ids');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('renewal_applied_at')->nullable()->after('new_expire_at');
        });
    }

    public function down(): void
    {
        Schema::table('renewal_packages', function (Blueprint $table) {
            $table->dropColumn(['allowed_plan_ids', 'admin_note']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('renewal_applied_at');
        });
    }
};
