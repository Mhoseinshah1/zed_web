<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_texts', function (Blueprint $table) {
            $table->string('group')->nullable()->after('key');
            $table->string('label')->nullable()->after('group');
            $table->string('type')->default('text')->after('value');
            $table->boolean('is_public')->default(true)->after('type');
            $table->unsignedInteger('sort_order')->default(0)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('site_texts', function (Blueprint $table) {
            $table->dropColumn(['group', 'label', 'type', 'is_public', 'sort_order']);
        });
    }
};
