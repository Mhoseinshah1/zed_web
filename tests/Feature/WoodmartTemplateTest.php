<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Plan;
use App\Models\PlanCategory;
use App\Models\SiteSetting;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WoodmartTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function activateWoodmart(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'woodmart');
    }

    // ── Registration in the existing selector ────────────────────────────────

    public function test_woodmart_is_registered_as_a_template(): void
    {
        $this->assertArrayHasKey('woodmart', TemplateManager::templates());
        $this->assertSame('قالب وودمارت', TemplateManager::templates()['woodmart']['title']);
        $this->assertTrue(TemplateManager::isValid('woodmart'));
        // The earlier six templates are untouched.
        $this->assertGreaterThanOrEqual(6, count(TemplateManager::templates()));
        foreach (['classic', 'modern', 'shop', 'matrix', 'map'] as $existing) {
            $this->assertArrayHasKey($existing, TemplateManager::templates());
        }
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function test_woodmart_homepage_renders_when_active(): void
    {
        $this->activateWoodmart();

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('woodmart-home-marker', false)
            ->assertSee('woodmart-template-marker', false)   // header/nav
            ->assertDontSee('shop-home-marker', false)
            ->assertDontSee('classic-template-marker', false);
    }

    public function test_switching_to_woodmart_changes_output(): void
    {
        $classic = $this->get(route('home'))->getContent();
        $this->activateWoodmart();
        $woodmart = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('woodmart-home-marker', $woodmart);
        $this->assertNotSame($classic, $woodmart);
    }

    public function test_internal_page_uses_woodmart_header_and_footer(): void
    {
        $this->activateWoodmart();

        // The plans page only supplies its BODY; the woodmart shell (header marker)
        // must wrap it, while the homepage body marker must NOT appear.
        $this->get(route('plans'))->assertSuccessful()
            ->assertSee('woodmart-template-marker', false)
            ->assertDontSee('woodmart-home-marker', false);
    }

    // ── Real data ────────────────────────────────────────────────────────────

    public function test_product_grid_is_populated_from_real_plans(): void
    {
        $this->activateWoodmart();
        Plan::factory()->create(['name' => 'پلن-وودمارت-آزمایشی', 'is_active' => true]);
        Plan::factory()->create(['name' => 'پلن-مخفی-غیرفعال', 'is_active' => false]);

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('پلن-وودمارت-آزمایشی')
            ->assertDontSee('پلن-مخفی-غیرفعال');
    }

    public function test_category_cards_show_real_categories_with_active_plan_counts(): void
    {
        $this->activateWoodmart();
        $cat = PlanCategory::create(['title' => 'دسته-وودمارت-تست', 'is_active' => true, 'sort_order' => 1]);
        Plan::factory()->create(['name' => 'پلن-دسته', 'category_id' => $cat->id, 'is_active' => true]);
        // A category with no active plans must NOT appear.
        PlanCategory::create(['title' => 'دسته-خالی-تست', 'is_active' => true, 'sort_order' => 2]);

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('دسته-وودمارت-تست')
            ->assertSee('1 محصول')              // real active-plan count for this category
            ->assertDontSee('دسته-خالی-تست');
    }

    public function test_discount_shows_struck_old_price_only_when_applicable(): void
    {
        $this->activateWoodmart();
        // A real discount: old > new.
        Plan::factory()->create([
            'name' => 'پلن-تخفیف-دار', 'is_active' => true,
            'price_toman' => 80000, 'old_price_toman' => 100000, 'sort_order' => 1,
        ]);
        // No discount.
        Plan::factory()->create([
            'name' => 'پلن-بدون-تخفیف', 'is_active' => true,
            'price_toman' => 50000, 'old_price_toman' => null, 'sort_order' => 2,
        ]);

        $html = $this->get(route('home'))->assertSuccessful()->getContent();

        // 20% off ribbon + struck old price appear for the discounted plan.
        $this->assertStringContainsString('٪ تخفیف', $html);
        $this->assertStringContainsString(number_format(100000), $html);   // struck old price
        $this->assertStringContainsString(number_format(50000), $html);    // plain price for the other plan
    }

    public function test_no_fake_star_ratings_are_rendered(): void
    {
        $this->activateWoodmart();
        Plan::factory()->create(['name' => 'پلن-بدون-ستاره', 'is_active' => true]);

        // No rating system exists, so the product cards must not print star glyphs.
        $this->get(route('home'))->assertSuccessful()->assertDontSee('★');
    }

    // ── Theme compatibility (light/dark) ─────────────────────────────────────

    public function test_light_mode_adds_zed_light_and_dark_mode_does_not(): void
    {
        $this->activateWoodmart();

        SiteSetting::set('default_appearance', 'light');
        $this->get(route('home'))->assertSuccessful()->assertSee('zed-light', false);

        SiteSetting::set('default_appearance', 'dark');
        $this->get(route('home'))->assertSuccessful()->assertDontSee('zed-light', false);
    }

    public function test_woodmart_uses_theme_classes_and_no_hardcoded_chrome(): void
    {
        $this->activateWoodmart();
        $html = $this->get(route('home'))->assertSuccessful()->getContent();

        // Chrome comes from the project theme classes (flip with zed-light)…
        $this->assertStringContainsString('bg-surface', $html);
        $this->assertStringContainsString('text-content', $html);
        $this->assertStringContainsString('border-line', $html);
        // …and never from hardcoded grey/slate utilities.
        $this->assertStringNotContainsString('bg-gray-', $html);
        $this->assertStringNotContainsString('bg-slate-', $html);
        $this->assertStringNotContainsString('text-gray-', $html);
    }
}
