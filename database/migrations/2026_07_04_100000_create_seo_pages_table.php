<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page manageable SEO records for the public static pages (home, plans,
 * faq, tutorials index, status, contact, about, terms, privacy, login,
 * register …). Identified by a stable `page_key`; the matching route is stored
 * for canonical generation and sitemap output.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_pages')) {
            return;
        }

        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_key')->unique();          // e.g. "home", "faq"
            $table->string('label')->nullable();           // admin-facing name
            $table->string('route_name')->nullable();      // e.g. "home", "faq"

            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();

            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            // When true, admins cannot flip robots_index to true (forced noindex
            // for auth pages); an explicit override still requires a warning.
            $table->boolean('lock_noindex')->default(false);

            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->string('og_type')->default('website');

            $table->string('twitter_card')->default('summary_large_image');
            $table->string('twitter_title')->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image')->nullable();

            $table->string('schema_type')->nullable();      // WebPage, FAQPage, …
            $table->longText('schema_json_override')->nullable();

            $table->boolean('include_in_sitemap')->default(true);
            $table->decimal('sitemap_priority', 2, 1)->default(0.5);
            $table->string('sitemap_change_frequency')->default('weekly');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
