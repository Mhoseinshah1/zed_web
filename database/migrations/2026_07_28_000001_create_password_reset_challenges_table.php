<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purpose-scoped password-reset challenges. Deliberately a DEDICATED table:
 * an email-verification or phone-verification OTP must never authorize a
 * password reset, and a reset OTP must never verify contact information —
 * separate storage makes cross-purpose reuse structurally impossible.
 *
 * Stores ONLY: a server-generated opaque token (session-carried, never in a
 * URL), a bcrypt hash of the 6-digit OTP (never plaintext), expiry/attempt/
 * consumption state, delivery state, and — after OTP verification — the
 * short-lived reset-authorization binding (guest-session hash + the sha256
 * fingerprint of the account's password hash at authorization time). No
 * destination address/number is persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 8); // email | sms
            $table->string('token', 64)->unique(); // opaque, session-carried
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('send_status', 24)->default('pending');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('authorization_expires_at')->nullable();
            $table->string('auth_session_hash', 64)->nullable();
            $table->string('password_fingerprint', 64)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_challenges');
    }
};
