<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'gateway_amount')) {
                $table->unsignedBigInteger('gateway_amount')->nullable()
                    ->comment('Amount sent to gateway (in Toman for CentralPay)');
            }
            if (! Schema::hasColumn('payment_transactions', 'gateway_currency')) {
                $table->string('gateway_currency', 20)->nullable()
                    ->comment('Currency sent to gateway (TOMAN for CentralPay)');
            }
            if (! Schema::hasColumn('payment_transactions', 'verified_at')) {
                $table->dateTime('verified_at')->nullable()
                    ->comment('When the gateway verification succeeded');
            }
            if (! Schema::hasColumn('payment_transactions', 'failed_at')) {
                $table->dateTime('failed_at')->nullable()
                    ->comment('When the transaction was determined failed');
            }
            if (! Schema::hasColumn('payment_transactions', 'failure_reason')) {
                $table->text('failure_reason')->nullable()
                    ->comment('Reason for failure (e.g. amount_mismatch, invalid_orderId)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            foreach (['gateway_amount', 'gateway_currency', 'verified_at', 'failed_at', 'failure_reason'] as $col) {
                if (Schema::hasColumn('payment_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
