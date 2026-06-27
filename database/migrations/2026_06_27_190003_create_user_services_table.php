<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_services', function (Blueprint $table) {
            $table->id();
            $table->string('service_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->string('name')->nullable();
            $table->string('status')->default('pending_provision');
            $table->string('provision_status')->default('pending');

            // Plan snapshot
            $table->string('plan_name')->nullable();
            $table->unsignedInteger('traffic_total_gb')->nullable();
            $table->unsignedInteger('traffic_used_gb')->default(0);
            $table->unsignedInteger('traffic_remaining_gb')->nullable();
            $table->unsignedInteger('duration_days')->nullable();

            // Lifecycle timestamps
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Connection info
            $table->text('config_link')->nullable();
            $table->text('subscription_link')->nullable();
            $table->string('qr_code_path')->nullable();

            // VPN panel references
            $table->foreignId('vpn_panel_id')->nullable()->constrained('vpn_panels')->nullOnDelete();
            $table->foreignId('vpn_inbound_id')->nullable()->constrained('vpn_inbounds')->nullOnDelete();
            $table->string('remote_client_id')->nullable();
            $table->string('remote_username')->nullable();

            $table->text('admin_notes')->nullable();
            $table->text('user_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_services');
    }
};
