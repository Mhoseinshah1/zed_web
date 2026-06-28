<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_panels', function (Blueprint $table) {
            if (! Schema::hasColumn('vpn_panels', 'allow_user_sync_service')) {
                $table->boolean('allow_user_sync_service')->default(true)->after('last_error');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_revoke_subscription')) {
                $table->boolean('allow_user_revoke_subscription')->default(true)->after('allow_user_sync_service');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_reset_traffic')) {
                $table->boolean('allow_user_reset_traffic')->default(false)->after('allow_user_revoke_subscription');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_disable_service')) {
                $table->boolean('allow_user_disable_service')->default(false)->after('allow_user_reset_traffic');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_enable_service')) {
                $table->boolean('allow_user_enable_service')->default(false)->after('allow_user_disable_service');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_view_subscription_qr')) {
                $table->boolean('allow_user_view_subscription_qr')->default(true)->after('allow_user_enable_service');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_view_config_qr')) {
                $table->boolean('allow_user_view_config_qr')->default(true)->after('allow_user_view_subscription_qr');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_copy_subscription_link')) {
                $table->boolean('allow_user_copy_subscription_link')->default(true)->after('allow_user_view_config_qr');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_copy_config_link')) {
                $table->boolean('allow_user_copy_config_link')->default(true)->after('allow_user_copy_subscription_link');
            }
            if (! Schema::hasColumn('vpn_panels', 'allow_user_view_all_config_links')) {
                $table->boolean('allow_user_view_all_config_links')->default(true)->after('allow_user_copy_config_link');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vpn_panels', function (Blueprint $table) {
            $columns = [
                'allow_user_sync_service',
                'allow_user_revoke_subscription',
                'allow_user_reset_traffic',
                'allow_user_disable_service',
                'allow_user_enable_service',
                'allow_user_view_subscription_qr',
                'allow_user_view_config_qr',
                'allow_user_copy_subscription_link',
                'allow_user_copy_config_link',
                'allow_user_view_all_config_links',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('vpn_panels', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
