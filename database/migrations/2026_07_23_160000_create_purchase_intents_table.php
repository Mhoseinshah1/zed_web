<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-enforced idempotency records for order creation.
 *
 * Each purchase form carries a signed, opaque token; on submit an intent row is
 * claimed by its unique `key` inside a locked transaction. A consumed intent
 * returns the exact order it created, so double-clicks, retries, duplicate tabs
 * and concurrent requests never create more than one order.
 *
 * Stores NO secrets: `key` is a random nonce (not the full signed token) and no
 * price/discount authority lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_intents')) {
            return;
        }

        Schema::create('purchase_intents', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                 // random nonce; the DB concurrency guarantee
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation_type');                // new_service | renewal | extra_traffic | extra_time
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('user_service_id')->nullable()->constrained('user_services')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('request_fingerprint', 64);       // sha256 of immutable params (never a price)
            $table->string('status')->default('pending');    // pending | consumed | expired
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('request_fingerprint');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_intents');
    }
};
