<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds account_id (unique 6-digit numeric) and phone fields to users.
 * All additive/nullable so existing rows are untouched; account_id is
 * backfilled in a later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'account_id')) {
                $table->string('account_id', 6)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable()->index()->after('email');
            }
            if (! Schema::hasColumn('users', 'normalized_phone')) {
                $table->string('normalized_phone', 20)->nullable()->index()->after('phone');
            }
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('normalized_phone');
            }
            if (! Schema::hasColumn('users', 'profile_completed_at')) {
                $table->timestamp('profile_completed_at')->nullable()->after('phone_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'account_id',
                'phone',
                'normalized_phone',
                'phone_verified_at',
                'profile_completed_at',
            ]);
        });
    }
};
