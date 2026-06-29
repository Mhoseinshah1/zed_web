<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content management system tables: banners, FAQs, static pages, tutorials,
 * landing sections and plan categories. All creation is guarded with
 * hasTable() so the migration is safe to re-run and never wipes existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('banners')) {
            Schema::create('banners', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->string('background_image')->nullable();
                $table->string('button_text')->nullable();
                $table->string('button_url')->nullable();
                $table->string('placement')->default('home_top')->index();
                $table->string('theme_style')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('faqs')) {
            Schema::create('faqs', function (Blueprint $table) {
                $table->id();
                $table->string('question');
                $table->text('answer');
                $table->string('category')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('title');
                $table->longText('content')->nullable();
                $table->string('excerpt')->nullable();
                $table->string('meta_title')->nullable();
                $table->string('meta_description')->nullable();
                $table->string('meta_keywords')->nullable();
                $table->string('og_title')->nullable();
                $table->string('og_description')->nullable();
                $table->string('og_image')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('show_in_footer')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tutorials')) {
            Schema::create('tutorials', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('platform')->default('general')->index();
                $table->string('short_description')->nullable();
                $table->longText('content')->nullable();
                $table->string('video_url')->nullable();
                $table->string('image')->nullable();
                $table->string('meta_title')->nullable();
                $table->string('meta_description')->nullable();
                $table->string('og_image')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('landing_sections')) {
            Schema::create('landing_sections', function (Blueprint $table) {
                $table->id();
                $table->string('key')->nullable()->unique();
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->text('content')->nullable();
                $table->string('type')->default('custom')->index();
                $table->string('image')->nullable();
                $table->string('background_image')->nullable();
                $table->string('icon')->nullable();
                $table->string('button_text')->nullable();
                $table->string('button_url')->nullable();
                $table->string('secondary_button_text')->nullable();
                $table->string('secondary_button_url')->nullable();
                $table->json('items')->nullable();
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('plan_categories')) {
            Schema::create('plan_categories', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->string('icon')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally left non-destructive: content tables are not dropped on
        // rollback to avoid accidental data loss in shared environments.
    }
};
