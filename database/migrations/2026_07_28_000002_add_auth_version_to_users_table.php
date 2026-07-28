<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic account authentication version — the authoritative session
 * credential version. Every successful login stamps it into the session and
 * every authenticated request re-verifies it; a password reset advances it
 * atomically, revoking every other session.
 *
 * COMPATIBILITY: existing rows receive the documented initial version (1).
 * Sessions created before deployment carry no stamp and are adopted lazily
 * ONLY while the account is still on the initial version — once the version
 * has advanced, an unstamped session fails closed. No mass logout happens at
 * deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('auth_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};
