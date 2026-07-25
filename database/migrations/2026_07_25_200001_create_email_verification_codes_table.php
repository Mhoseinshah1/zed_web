<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_verification_codes')) {
            return;
        }

        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            // Deleting a user removes their verification-code records.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            // Only the Hash of the code is ever stored — never the plaintext.
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->string('send_status', 20)->default('pending');
            $table->string('send_error', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
