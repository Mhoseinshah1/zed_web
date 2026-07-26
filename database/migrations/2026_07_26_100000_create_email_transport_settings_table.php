<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated singleton-style storage for the admin-managed SMTP transport.
 *
 * SMTP credentials NEVER go into the generic plaintext `site_settings` table:
 * username and password live here as authenticated APP_KEY-encrypted payloads
 * (Eloquent `encrypted` casts → Crypt::encryptString, AES-256-CBC + HMAC).
 * TEXT columns because ciphertext (base64 JSON envelope with IV + MAC) is
 * several times longer than the plaintext.
 *
 * No row / disabled `enabled` flag means the environment-backed configuration
 * stays authoritative — existing installs keep working from .env until an
 * administrator explicitly enables the panel override. Nothing is copied out
 * of the environment into this table automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_transport_settings', function (Blueprint $table) {
            $table->id();

            // DATABASE-ENFORCED singleton: every row carries the same fixed
            // key under a unique index, so exactly one logical settings row
            // can ever exist — concurrent first-time saves cannot create two,
            // regardless of application-level checks. Portable across
            // PostgreSQL and SQLite (plain unique column, no partial index).
            $table->string('singleton_key', 16)->default('main');
            $table->unique('singleton_key');

            // Panel override master switch — false = environment fallback.
            $table->boolean('enabled')->default(false);

            // Non-secret operational values. Nullable so an admin can stage a
            // partial draft while the override stays disabled.
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            // Verified Symfony scheme value: 'smtp' (STARTTLS/opportunistic
            // TLS) or 'smtps' (implicit TLS). Never a legacy 'encryption'
            // value — Laravel 12 / Symfony Mailer 7 accept only these two.
            $table->string('security', 16)->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->unsignedSmallInteger('timeout')->nullable();
            $table->string('local_domain')->nullable();

            // Encrypted-at-rest secrets (Eloquent `encrypted` casts). TEXT:
            // the encryption envelope far exceeds the plaintext length.
            $table->text('username')->nullable();
            $table->text('password')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_transport_settings');
    }
};
