<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

            // DATABASE-ENFORCED singleton, part 1: the key is NOT NULL,
            // defaults to the canonical value, and is unique — two rows can
            // never share it. Part 2 (below, after create) makes the
            // canonical value the ONLY permitted one, so "unique key" plus
            // "only one legal key value" = at most one row, enforced by the
            // database against raw inserts too — never only by Eloquent
            // hooks or application validation.
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

        // Part 2 of the singleton invariant: the database rejects ANY row
        // whose key is not the canonical 'main' — including raw query-builder
        // or psql/sqlite3 inserts that never touch Eloquent. PostgreSQL uses
        // a CHECK constraint; SQLite cannot ALTER TABLE .. ADD CONSTRAINT,
        // so equivalent BEFORE INSERT/UPDATE triggers raise instead (both are
        // dropped together with the table on rollback). The column is NOT
        // NULL, so the equality comparison can never be bypassed via NULL.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER email_transport_settings_singleton_ins
                BEFORE INSERT ON email_transport_settings
                FOR EACH ROW WHEN NEW.singleton_key <> 'main'
                BEGIN
                    SELECT RAISE(ABORT, 'email_transport_settings.singleton_key must be ''main''');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER email_transport_settings_singleton_upd
                BEFORE UPDATE ON email_transport_settings
                FOR EACH ROW WHEN NEW.singleton_key <> 'main'
                BEGIN
                    SELECT RAISE(ABORT, 'email_transport_settings.singleton_key must be ''main''');
                END
                SQL);
        } else {
            DB::statement(
                "alter table email_transport_settings add constraint email_transport_settings_singleton_check check (singleton_key = 'main')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_transport_settings');
    }
};
