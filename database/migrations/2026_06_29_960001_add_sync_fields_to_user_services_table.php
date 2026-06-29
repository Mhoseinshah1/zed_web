<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Marzban sync status / cached telemetry to user_services.
 * (last_synced_at already exists from an earlier migration.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_services', function (Blueprint $table) {
            foreach ([
                'sync_status'          => fn () => $table->string('sync_status', 20)->nullable()->index(),
                'sync_error'           => fn () => $table->text('sync_error')->nullable(),
                'marzban_status'       => fn () => $table->string('marzban_status', 30)->nullable(),
                'marzban_used_traffic' => fn () => $table->unsignedBigInteger('marzban_used_traffic')->nullable(),
                'marzban_data_limit'   => fn () => $table->unsignedBigInteger('marzban_data_limit')->nullable(),
                'marzban_expire_at'    => fn () => $table->timestamp('marzban_expire_at')->nullable(),
                'marzban_online_at'    => fn () => $table->timestamp('marzban_online_at')->nullable(),
                'marzban_raw'          => fn () => $table->json('marzban_raw')->nullable(),
                'last_manual_sync_at'  => fn () => $table->timestamp('last_manual_sync_at')->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('user_services', $column)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_services', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status', 'sync_error', 'marzban_status', 'marzban_used_traffic',
                'marzban_data_limit', 'marzban_expire_at', 'marzban_online_at',
                'marzban_raw', 'last_manual_sync_at',
            ]);
        });
    }
};
