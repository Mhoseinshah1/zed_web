<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remnawave panel configuration. Additive and guarded so existing Marzban /
 * Sanaei panels are untouched. The JWT is stored in the existing encrypted
 * api_token column; only the default squad UUID is new here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_panels', function (Blueprint $table) {
            if (! Schema::hasColumn('vpn_panels', 'default_squad_uuid')) {
                $table->string('default_squad_uuid')->nullable()->after('default_inbound_id');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
