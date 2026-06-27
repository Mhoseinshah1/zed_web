<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            // Statuses
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('unpaid');

            // Plan snapshot at purchase time — immutable after creation
            $table->string('plan_name');
            $table->string('plan_slug')->nullable();
            $table->unsignedInteger('traffic_gb')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedBigInteger('price_toman');

            // Pricing
            $table->unsignedBigInteger('final_price_toman');
            $table->unsignedBigInteger('discount_toman')->default(0);
            $table->string('currency')->default('toman');

            // Notes
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();

            // Timestamps for state transitions
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
