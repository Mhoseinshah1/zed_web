<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a Plan pin which VPN panel its purchases provision on. Nullable and
 * additive — existing plans keep null and fall back to the default panel, so
 * behaviour is unchanged for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'vpn_panel_id')) {
                $table->foreignId('vpn_panel_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('vpn_panels')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
