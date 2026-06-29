<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discount_redemptions')) {
            return;
        }

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status')->default('reserved'); // reserved, used, cancelled, expired
            $table->unsignedBigInteger('original_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('final_amount');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['discount_code_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
    }
};
