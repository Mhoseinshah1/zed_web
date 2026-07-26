<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-administrator TOTP (RFC 6238) credentials for mandatory panel MFA.
 *
 * One row per admin user. The TOTP secret is stored ONLY through the model's
 * `encrypted` cast (authenticated APP_KEY encryption) and recovery codes only
 * as one-way hashes — nothing in this table is ever usable as a plaintext
 * factor. Existing installations boot fine before this migration runs: every
 * consumer treats a missing table/row as "enrollment required", never as a
 * bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_two_factor_credentials', function (Blueprint $table) {
            $table->id();

            // One credential per admin; deleting the user deletes the factor
            // (an orphaned secret must never outlive its account).
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Encrypted ACTIVE (confirmed) TOTP secret (model cast handles
            // encrypt/decrypt). TEXT: ciphertext + MAC is far longer than the
            // base32 secret. NULL only while first enrollment is pending.
            $table->text('secret')->nullable();

            // NULL until the admin proves possession by entering a valid code
            // generated from the pending secret. Doubles as the credential
            // VERSION: replacement re-stamps it, invalidating every session
            // marker and step-up grant bound to the old factor.
            $table->timestamp('confirmed_at')->nullable();

            // Encrypted candidate secret for first enrollment or replacement.
            // Promoted to `secret` only after a valid live code confirms it;
            // the previous factor stays active until that moment.
            $table->text('pending_secret')->nullable();
            $table->timestamp('pending_secret_generated_at')->nullable();

            // Last CONSUMED 30-second TOTP time-step (google2fa counter =
            // unix_time / 30). A code is accepted only when its step is
            // strictly newer — updated under a row lock so parallel
            // submissions of the same code have exactly one winner.
            $table->unsignedBigInteger('last_verified_timestep')->nullable();

            // Encrypted JSON array of one-way (bcrypt) recovery-code hashes.
            $table->text('recovery_codes')->nullable();
            $table->timestamp('recovery_codes_generated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_two_factor_credentials');
    }
};
