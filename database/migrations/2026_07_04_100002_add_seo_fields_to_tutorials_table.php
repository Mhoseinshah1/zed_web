<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend tutorials with the full SEO field set + article metadata. Additive and
 * guarded so existing meta_title/meta_description/og_image are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutorials', function (Blueprint $table) {
            if (! Schema::hasColumn('tutorials', 'canonical_url')) {
                $table->string('canonical_url')->nullable()->after('og_image');
            }
            if (! Schema::hasColumn('tutorials', 'robots_index')) {
                $table->boolean('robots_index')->default(true)->after('canonical_url');
            }
            if (! Schema::hasColumn('tutorials', 'robots_follow')) {
                $table->boolean('robots_follow')->default(true)->after('robots_index');
            }
            if (! Schema::hasColumn('tutorials', 'og_title')) {
                $table->string('og_title')->nullable()->after('robots_follow');
            }
            if (! Schema::hasColumn('tutorials', 'og_description')) {
                $table->text('og_description')->nullable()->after('og_title');
            }
            if (! Schema::hasColumn('tutorials', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('og_description');
            }
            if (! Schema::hasColumn('tutorials', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }
            if (! Schema::hasColumn('tutorials', 'twitter_image')) {
                $table->string('twitter_image')->nullable()->after('twitter_description');
            }
            if (! Schema::hasColumn('tutorials', 'schema_type')) {
                // Article | TechArticle | HowTo — HowTo only when real steps exist.
                $table->string('schema_type')->default('Article')->after('twitter_image');
            }
            if (! Schema::hasColumn('tutorials', 'author_name')) {
                $table->string('author_name')->nullable()->after('schema_type');
            }
            if (! Schema::hasColumn('tutorials', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('author_name');
            }
            if (! Schema::hasColumn('tutorials', 'include_in_sitemap')) {
                $table->boolean('include_in_sitemap')->default(true)->after('published_at');
            }
            if (! Schema::hasColumn('tutorials', 'sitemap_priority')) {
                $table->decimal('sitemap_priority', 2, 1)->default(0.6)->after('include_in_sitemap');
            }
            if (! Schema::hasColumn('tutorials', 'sitemap_change_frequency')) {
                $table->string('sitemap_change_frequency')->default('monthly')->after('sitemap_priority');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback.
    }
};
