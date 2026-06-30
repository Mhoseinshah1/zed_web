<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3X-UI client identity/state fields on user_services. All nullable & additive;
 * existing Marzban services are untouched (they keep using remote_username etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_services', function (Blueprint $table) {
            if (! Schema::hasColumn('user_services', 'remote_inbound_id')) {
                $table->unsignedInteger('remote_inbound_id')->nullable()->after('vpn_inbound_id');
            }
            if (! Schema::hasColumn('user_services', 'remote_uuid')) {
                $table->string('remote_uuid')->nullable()->after('remote_client_id');
            }
            if (! Schema::hasColumn('user_services', 'remote_sub_id')) {
                $table->string('remote_sub_id')->nullable()->after('remote_uuid');
            }
            if (! Schema::hasColumn('user_services', 'links_json')) {
                $table->json('links_json')->nullable()->after('subscription_link');
            }
            if (! Schema::hasColumn('user_services', 'remote_raw')) {
                $table->json('remote_raw')->nullable()->after('links_json');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
