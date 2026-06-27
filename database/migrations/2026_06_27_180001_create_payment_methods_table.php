<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type');
            $table->text('description')->nullable();
            $table->longText('instructions')->nullable();
            $table->string('account_label')->nullable();
            $table->string('account_value')->nullable();
            $table->string('network')->nullable();
            $table->unsignedBigInteger('min_amount_toman')->nullable();
            $table->unsignedBigInteger('max_amount_toman')->nullable();
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
