<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('provider')->nullable();
            $table->string('method')->nullable();
            $table->string('status')->default('pending');

            $table->unsignedBigInteger('amount_toman');
            $table->string('currency')->default('toman');

            $table->string('reference_id')->nullable();
            $table->string('external_id')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
