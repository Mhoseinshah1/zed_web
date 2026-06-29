<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production-safe backfill: assign a unique 6-digit numeric account_id to every
 * existing user that does not yet have one. Never overwrites an existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        $used = DB::table('users')->whereNotNull('account_id')->pluck('account_id')->all();
        $used = array_flip($used);

        DB::table('users')->whereNull('account_id')->orderBy('id')->each(function ($user) use (&$used) {
            do {
                $candidate = (string) random_int(100000, 999999);
            } while (isset($used[$candidate]));

            $used[$candidate] = true;

            DB::table('users')->where('id', $user->id)->update(['account_id' => $candidate]);
        });
    }

    public function down(): void
    {
        // Account IDs are permanent identifiers; do not remove on rollback.
    }
};
