<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_methods', 'config')) {
                $table->json('config')->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn('payment_methods', 'api_key')) {
                $table->text('api_key')->nullable()->after('config');
            }
            if (! Schema::hasColumn('payment_methods', 'ipn_secret')) {
                $table->text('ipn_secret')->nullable()->after('api_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            foreach (['config', 'api_key', 'ipn_secret'] as $col) {
                if (Schema::hasColumn('payment_methods', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
