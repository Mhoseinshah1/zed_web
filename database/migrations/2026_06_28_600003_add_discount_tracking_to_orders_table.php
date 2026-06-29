<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'discount_code_id')) {
                $table->foreignId('discount_code_id')
                    ->nullable()
                    ->after('discount_toman')
                    ->constrained('discount_codes')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'discount_code')) {
                $table->string('discount_code')->nullable()->after('discount_code_id');
            }
            if (! Schema::hasColumn('orders', 'discount_type')) {
                $table->string('discount_type')->nullable()->after('discount_code');
            }
            if (! Schema::hasColumn('orders', 'discount_value')) {
                $table->unsignedInteger('discount_value')->nullable()->after('discount_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_code_id');
            $table->dropColumn(['discount_code', 'discount_type', 'discount_value']);
        });
    }
};
