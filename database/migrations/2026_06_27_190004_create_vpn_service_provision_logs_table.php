<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_service_provision_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_service_id')->constrained('user_services')->cascadeOnDelete();
            $table->foreignId('vpn_panel_id')->nullable()->constrained('vpn_panels')->nullOnDelete();
            $table->string('action');           // create_placeholder_service | manual_activate | disable | cancel | expire
            $table->string('status');           // skipped | success | failed
            $table->text('message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_service_provision_logs');
    }
};
