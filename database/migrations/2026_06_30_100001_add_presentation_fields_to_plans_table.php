<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan presentation fields for the shop: category, badge styling, short
 * description and feature bullets. All guarded so existing data is preserved
 * and the purchase logic is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('slug')->index();
            }
            if (! Schema::hasColumn('plans', 'badge_text')) {
                $table->string('badge_text')->nullable()->after('badge');
            }
            if (! Schema::hasColumn('plans', 'badge_type')) {
                $table->string('badge_type')->nullable()->after('badge_text');
            }
            if (! Schema::hasColumn('plans', 'short_description')) {
                $table->string('short_description')->nullable()->after('description');
            }
            if (! Schema::hasColumn('plans', 'feature_list')) {
                $table->json('feature_list')->nullable()->after('short_description');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
