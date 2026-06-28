<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provisioning_attempts')) {
            return;
        }

        Schema::create('provisioning_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vpn_panel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('user_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_attempts');
    }
};
