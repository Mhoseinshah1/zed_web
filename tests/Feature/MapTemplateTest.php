<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\SiteSetting;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function activateMap(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'map');
    }

    private function location(array $overrides = []): Location
    {
        return Location::create(array_merge([
            'country_name' => 'آلمان',
            'country_code' => 'DE',
            'flag_emoji'   => '🇩🇪',
            'latitude'     => 51.1657,
            'longitude'    => 10.4515,
            'ping_ms'      => 18,
            'is_active'    => true,
            'sort_order'   => 1,
        ], $overrides));
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function test_map_is_registered(): void
    {
        $this->assertArrayHasKey('map', TemplateManager::templates());
        $this->assertSame('قالب نقشه‌ای', TemplateManager::templates()['map']['title']);
        $this->assertTrue(TemplateManager::isValid('map'));
    }

    public function test_map_homepage_renders(): void
    {
        $this->activateMap();
        $this->get(route('home'))->assertSuccessful()
            ->assertSee('map-home-marker', false)
            ->assertSee('map-template-marker', false);
    }

    // ── Coordinates / dots ───────────────────────────────────────────────────

    public function test_one_location_with_coordinates_makes_one_dot(): void
    {
        $this->activateMap();
        $this->location(['country_name' => 'آلمان-تست']);

        $html = $this->get(route('home'))->assertSuccessful()->getContent();
        $this->assertSame(1, substr_count($html, 'class="zmap-dot"'));
        $this->assertStringContainsString('data-name="آلمان-تست 🇩🇪 · 18ms"', $html);
    }

    public function test_location_without_coordinates_has_no_dot_but_shows_in_grid(): void
    {
        $this->activateMap();
        $this->location(['country_name' => 'بی‌مختصات', 'latitude' => null, 'longitude' => null]);

        $html = $this->get(route('home'))->assertSuccessful()->getContent();
        $this->assertSame(0, substr_count($html, 'class="zmap-dot"'));
        $this->assertStringContainsString('بی‌مختصات', $html); // still in the grid
    }

    public function test_ping_falls_back_to_online_label(): void
    {
        $this->activateMap();
        $this->location(['country_name' => 'بدون-پینگ', 'ping_ms' => null]);

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('data-name="بدون-پینگ 🇩🇪 · آنلاین"', false);
    }

    // ── Site-wide shell on internal pages ────────────────────────────────────

    public function test_internal_page_uses_map_shell(): void
    {
        $this->activateMap();
        // The plans page extends layouts.app → must now show the map header/footer.
        $this->get(route('plans'))->assertSuccessful()
            ->assertSee('map-template-marker', false);
        // …but the heavy map partial must NOT load on non-map pages.
        $this->get(route('plans'))->assertDontSee('class="zmap-dot"', false);
    }

    public function test_deactivating_location_removes_it_from_map_and_grid(): void
    {
        $this->activateMap();
        $loc = $this->location(['country_name' => 'حذف-شونده']);

        $this->get(route('home'))->assertSee('حذف-شونده');

        $loc->update(['is_active' => false]);

        $html = $this->get(route('home'))->getContent();
        $this->assertStringNotContainsString('حذف-شونده', $html);
        $this->assertSame(0, substr_count($html, 'class="zmap-dot"'));
    }

    // ── Earlier templates still work via the new dispatcher ──────────────────

    public function test_classic_and_other_templates_still_render(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'classic');
        $this->get(route('home'))->assertSuccessful()->assertSee('classic-template-marker', false);

        SiteSetting::set(TemplateManager::SETTING_KEY, 'matrix');
        $this->get(route('home'))->assertSuccessful()->assertSee('matrix-home-marker', false);

        SiteSetting::set(TemplateManager::SETTING_KEY, 'shop');
        $this->get(route('home'))->assertSuccessful()->assertSee('shop-home-marker', false);
    }

    public function test_classic_internal_page_has_no_template_marker(): void
    {
        // Default classic: internal pages must not carry other templates' markers.
        $this->get(route('plans'))->assertSuccessful()
            ->assertDontSee('map-template-marker', false)
            ->assertDontSee('modern-template-marker', false);
    }
}
