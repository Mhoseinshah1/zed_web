<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete()->after('user_id');
            $table->string('proof_path')->nullable()->after('payload');
            $table->string('transaction_reference')->nullable()->after('proof_path');
            $table->text('user_note')->nullable()->after('transaction_reference');
            $table->text('admin_note')->nullable()->after('user_note');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('admin_note');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->timestamp('rejected_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['proof_path', 'transaction_reference', 'user_note', 'admin_note', 'reviewed_at', 'rejected_at']);
        });
    }
};
