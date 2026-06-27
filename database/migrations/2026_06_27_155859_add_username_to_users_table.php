<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add as nullable first so backfill can run without constraint violation
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
        });

        // Backfill: derive a unique username from name for any existing rows
        DB::table('users')->orderBy('id')->each(function (object $user) {
            // Sanitize name → lowercase snake_case alphanumeric
            $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $user->name));
            $base = trim($base, '_') ?: ('user' . $user->id);
            $base = substr($base, 0, 40);

            $username = $base;
            $suffix   = 1;
            while (
                DB::table('users')
                    ->where('username', $username)
                    ->where('id', '!=', $user->id)
                    ->exists()
            ) {
                $username = $base . '_' . $suffix++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });

        // Now enforce NOT NULL + unique index
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
