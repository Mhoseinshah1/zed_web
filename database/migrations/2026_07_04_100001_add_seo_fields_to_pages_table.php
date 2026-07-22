<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend CMS pages with the full SEO field set. Additive and guarded so
 * existing meta_title/meta_description/og_* values are preserved. Old columns
 * are NOT dropped — the new system reads them as fallbacks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'canonical_url')) {
                $table->string('canonical_url')->nullable()->after('og_image');
            }
            if (! Schema::hasColumn('pages', 'robots_index')) {
                $table->boolean('robots_index')->default(true)->after('canonical_url');
            }
            if (! Schema::hasColumn('pages', 'robots_follow')) {
                $table->boolean('robots_follow')->default(true)->after('robots_index');
            }
            if (! Schema::hasColumn('pages', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('robots_follow');
            }
            if (! Schema::hasColumn('pages', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }
            if (! Schema::hasColumn('pages', 'twitter_image')) {
                $table->string('twitter_image')->nullable()->after('twitter_description');
            }
            if (! Schema::hasColumn('pages', 'schema_type')) {
                $table->string('schema_type')->nullable()->after('twitter_image');
            }
            if (! Schema::hasColumn('pages', 'include_in_sitemap')) {
                $table->boolean('include_in_sitemap')->default(true)->after('schema_type');
            }
            if (! Schema::hasColumn('pages', 'sitemap_priority')) {
                $table->decimal('sitemap_priority', 2, 1)->default(0.6)->after('include_in_sitemap');
            }
            if (! Schema::hasColumn('pages', 'sitemap_change_frequency')) {
                $table->string('sitemap_change_frequency')->default('monthly')->after('sitemap_priority');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
