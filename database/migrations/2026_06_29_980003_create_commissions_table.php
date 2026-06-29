<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commissions')) {
            return;
        }

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('representative_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            // One commission per order — prevents duplicate IPN/callbacks doubling it.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('order_type', 30);
            $table->unsignedBigInteger('original_amount')->default(0);
            $table->unsignedBigInteger('final_amount')->default(0);
            $table->string('commission_type', 20);
            $table->unsignedInteger('commission_value')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
