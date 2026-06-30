<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_services', function (Blueprint $table) {
            if (! Schema::hasColumn('user_services', 'remote_status')) {
                $table->string('remote_status', 30)->nullable()->after('remote_sub_id');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
