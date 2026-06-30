<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Theme\TemplateManager;
use Database\Seeders\DefaultPagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    // ── Task 1: core static pages always resolve ─────────────────────────────

    public function test_about_terms_privacy_404_without_seed(): void
    {
        // Without the seeder (and with no CMS rows) these slugs do not exist.
        $this->get('/pages/about')->assertNotFound();
    }

    public function test_about_terms_privacy_return_200_after_seeder(): void
    {
        $this->seed(DefaultPagesSeeder::class);

        $this->get('/about')->assertRedirect(route('pages.show', 'about'));
        $this->get('/pages/about')->assertSuccessful()->assertSee('درباره ما');
        $this->get('/pages/terms')->assertSuccessful()->assertSee('قوانین و مقررات');
        $this->get('/pages/privacy')->assertSuccessful()->assertSee('حریم خصوصی');
    }

    public function test_seeder_is_idempotent_and_keeps_admin_edits(): void
    {
        $this->seed(DefaultPagesSeeder::class);
        \App\Models\Page::where('slug', 'about')->update(['title' => 'درباره ما (ویرایش‌شده)']);

        $this->seed(DefaultPagesSeeder::class); // re-run

        $this->assertSame(1, \App\Models\Page::where('slug', 'about')->count());
        $this->assertSame('درباره ما (ویرایش‌شده)', \App\Models\Page::where('slug', 'about')->value('title'));
    }

    // ── Task 2: every page gets the active template's shell ──────────────────

    public function test_all_seven_pages_load_under_classic(): void
    {
        $this->seed(DefaultPagesSeeder::class);
        foreach ([
            route('home'), route('plans'), route('tutorials'), route('status'),
            '/pages/about', '/pages/terms', route('contact'),
        ] as $url) {
            $this->get($url)->assertSuccessful();
        }
    }

    public function test_all_seven_pages_load_under_map_template(): void
    {
        $this->seed(DefaultPagesSeeder::class);
        SiteSetting::set(TemplateManager::SETTING_KEY, 'map');

        foreach ([
            route('home'), route('plans'), route('tutorials'), route('status'),
            '/pages/about', '/pages/terms', route('contact'),
        ] as $url) {
            $this->get($url)->assertSuccessful()->assertSee('map-template-marker', false);
        }
    }

    public function test_plans_page_uses_graphical_template_shell(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'matrix');
        $this->get(route('plans'))->assertSuccessful()->assertSee('matrix-template-marker', false);

        SiteSetting::set(TemplateManager::SETTING_KEY, 'shop');
        $this->get(route('plans'))->assertSuccessful()->assertSee('shop-template-marker', false);
    }

    public function test_nav_has_unified_items_across_templates(): void
    {
        foreach (['classic', 'modern', 'shop', 'matrix', 'map'] as $tpl) {
            SiteSetting::set(TemplateManager::SETTING_KEY, $tpl);
            $this->get(route('home'))->assertSuccessful()
                ->assertSee('محصولات')->assertSee('درباره ما')->assertSee('قوانین');
        }
    }

    public function test_no_duplicate_header_on_internal_page(): void
    {
        // Exactly one <header> element on a content page under a graphical template.
        SiteSetting::set(TemplateManager::SETTING_KEY, 'map');
        $html = $this->get(route('plans'))->getContent();
        $this->assertSame(1, substr_count($html, '<header'));
    }
}
