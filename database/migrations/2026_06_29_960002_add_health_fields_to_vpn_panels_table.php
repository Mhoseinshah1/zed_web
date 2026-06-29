<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_panels', function (Blueprint $table) {
            foreach ([
                'last_health_checked_at' => fn () => $table->timestamp('last_health_checked_at')->nullable(),
                'health_status'          => fn () => $table->string('health_status', 20)->nullable(),
                'health_error'           => fn () => $table->text('health_error')->nullable(),
                'system_info'            => fn () => $table->json('system_info')->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('vpn_panels', $column)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_panels', function (Blueprint $table) {
            $table->dropColumn(['last_health_checked_at', 'health_status', 'health_error', 'system_info']);
        });
    }
};
