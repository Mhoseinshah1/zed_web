<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'provider_reference')) {
                $table->string('provider_reference', 191)->nullable()->after('external_id')
                    ->comment('NOWPayments payment_id or invoice_id');
            }
            if (! Schema::hasColumn('payment_transactions', 'gateway_url')) {
                $table->string('gateway_url', 2048)->nullable()->after('provider_reference')
                    ->comment('NOWPayments invoice_url');
            }
            if (! Schema::hasColumn('payment_transactions', 'gateway_status')) {
                $table->string('gateway_status', 50)->nullable()->after('gateway_url')
                    ->comment('Raw status from NOWPayments');
            }
            if (! Schema::hasColumn('payment_transactions', 'gateway_price_amount')) {
                $table->decimal('gateway_price_amount', 15, 8)->nullable()->after('gateway_status');
            }
            if (! Schema::hasColumn('payment_transactions', 'gateway_price_currency')) {
                $table->string('gateway_price_currency', 20)->nullable()->after('gateway_price_amount');
            }
            if (! Schema::hasColumn('payment_transactions', 'pay_amount')) {
                $table->decimal('pay_amount', 18, 8)->nullable()->after('gateway_price_currency')
                    ->comment('Amount in crypto that user must pay');
            }
            if (! Schema::hasColumn('payment_transactions', 'pay_currency')) {
                $table->string('pay_currency', 20)->nullable()->after('pay_amount');
            }
            if (! Schema::hasColumn('payment_transactions', 'pay_address')) {
                $table->string('pay_address', 500)->nullable()->after('pay_currency');
            }
            if (! Schema::hasColumn('payment_transactions', 'fee_amount')) {
                $table->decimal('fee_amount', 15, 4)->nullable()->after('pay_address');
            }
            if (! Schema::hasColumn('payment_transactions', 'payable_amount')) {
                $table->decimal('payable_amount', 15, 4)->nullable()->after('fee_amount');
            }
            if (! Schema::hasColumn('payment_transactions', 'expires_at')) {
                $table->dateTime('expires_at')->nullable()->after('payable_amount');
            }
            if (! Schema::hasColumn('payment_transactions', 'request_payload')) {
                $table->json('request_payload')->nullable()->after('payload')
                    ->comment('Sanitized request sent to gateway');
            }
            if (! Schema::hasColumn('payment_transactions', 'response_payload')) {
                $table->json('response_payload')->nullable()->after('request_payload')
                    ->comment('Sanitized response from gateway');
            }
            if (! Schema::hasColumn('payment_transactions', 'callback_payload')) {
                $table->json('callback_payload')->nullable()->after('response_payload')
                    ->comment('IPN callback payload from gateway');
            }
            if (! Schema::hasColumn('payment_transactions', 'callback_received_at')) {
                $table->dateTime('callback_received_at')->nullable()->after('callback_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $cols = [
                'provider_reference', 'gateway_url', 'gateway_status',
                'gateway_price_amount', 'gateway_price_currency',
                'pay_amount', 'pay_currency', 'pay_address',
                'fee_amount', 'payable_amount', 'expires_at',
                'request_payload', 'response_payload', 'callback_payload',
                'callback_received_at',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('payment_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
