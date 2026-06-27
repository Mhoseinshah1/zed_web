<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_inbounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vpn_panel_id')->constrained('vpn_panels')->cascadeOnDelete();
            $table->string('name');
            $table->string('remote_inbound_id')->nullable();
            $table->string('protocol')->nullable(); // vmess | vless | trojan | shadowsocks
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('network')->nullable();   // tcp | ws | grpc | quic
            $table->string('security')->nullable();  // none | tls | reality
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_inbounds');
    }
};
