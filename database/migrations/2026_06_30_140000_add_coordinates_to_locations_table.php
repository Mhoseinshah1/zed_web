<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map coordinates for the "map" homepage template. All nullable / default null
 * so existing location rows are untouched; only locations that have both
 * latitude and longitude are plotted on the map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'latitude')) {
                $table->decimal('latitude', 9, 6)->nullable()->after('flag_emoji');
            }
            if (! Schema::hasColumn('locations', 'longitude')) {
                $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('locations', 'ping_ms')) {
                $table->unsignedInteger('ping_ms')->nullable()->after('longitude');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
