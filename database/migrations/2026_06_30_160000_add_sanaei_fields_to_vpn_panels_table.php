<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanaei / 3X-UI panel configuration fields. All additive and guarded so
 * existing Marzban panels are untouched. Credentials (api_token) are stored
 * encrypted via the model cast — never in plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_panels', function (Blueprint $table) {
            if (! Schema::hasColumn('vpn_panels', 'panel_path')) {
                $table->string('panel_path')->nullable()->after('base_url');
            }
            if (! Schema::hasColumn('vpn_panels', 'auth_method')) {
                $table->string('auth_method', 20)->default('api_token')->after('panel_path');
            }
            if (! Schema::hasColumn('vpn_panels', 'api_token')) {
                $table->text('api_token')->nullable()->after('token'); // encrypted cast
            }
            if (! Schema::hasColumn('vpn_panels', 'default_inbound_id')) {
                $table->unsignedInteger('default_inbound_id')->nullable()->after('api_token');
            }
            if (! Schema::hasColumn('vpn_panels', 'subscription_base_url')) {
                $table->string('subscription_base_url')->nullable()->after('default_inbound_id');
            }
            if (! Schema::hasColumn('vpn_panels', 'subscription_path')) {
                $table->string('subscription_path')->nullable()->after('subscription_base_url');
            }
            if (! Schema::hasColumn('vpn_panels', 'verify_ssl')) {
                $table->boolean('verify_ssl')->default(true)->after('subscription_path');
            }
            if (! Schema::hasColumn('vpn_panels', 'timeout_seconds')) {
                $table->unsignedSmallInteger('timeout_seconds')->default(15)->after('verify_ssl');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
