<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make order_id nullable — wallet topup transactions have no order
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();

            if (! Schema::hasColumn('payment_transactions', 'payment_purpose')) {
                $table->string('payment_purpose')->default('order_payment')->after('user_id');
            }
        });

        // Add payment_transaction_id to wallet_transactions for idempotent crediting
        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_transactions', 'payment_transaction_id')) {
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->after('admin_id');
                $table->index('payment_transaction_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
            if (Schema::hasColumn('payment_transactions', 'payment_purpose')) {
                $table->dropColumn('payment_purpose');
            }
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_transactions', 'payment_transaction_id')) {
                $table->dropIndex(['payment_transaction_id']);
                $table->dropColumn('payment_transaction_id');
            }
        });
    }
};
