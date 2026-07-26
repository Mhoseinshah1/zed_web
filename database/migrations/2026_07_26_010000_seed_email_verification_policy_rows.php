<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees the email-verification policy PAIR physically exists (default
 * 'false'): captureRequiredPolicyForRegistration() serializes registrations
 * against admin policy saves with a SHARED row lock on these rows — on a
 * fresh installation without them, sharedLock() would lock nothing and a
 * concurrent first policy save could commit between a registration's read
 * and its marker write. insertOrIgnore + the unique `key` index make this
 * idempotent and race-safe; existing values are never modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_settings')->insertOrIgnore([
            ['key' => 'email_verification_enabled', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'email_verification_required_on_register', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        // Deliberately no-op: removing policy rows on rollback could delete
        // an operator's live configuration.
    }
};
