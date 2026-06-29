<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'theme_preference')) {
                $table->string('theme_preference', 40)->nullable()->after('representative_note');
            }
            if (! Schema::hasColumn('users', 'appearance')) {
                $table->string('appearance', 10)->nullable()->after('theme_preference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['theme_preference', 'appearance']);
        });
    }
};
