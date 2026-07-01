<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The user panel should borrow the active site template's ACCENT ("clothes")
 * while keeping its own fixed structure ("body shape"). These tests lock that in:
 * data-template reaches the panel, a template with a fixed accent (woodmart)
 * brings its accent, templates without one fall back to the theme default, the
 * sidebar structure is identical across templates, and light/dark still flips.
 */
class PanelTemplateAccentTest extends TestCase
{
    use RefreshDatabase;

    private function panel(string $template): string
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, $template);
        return $this->actingAs(User::factory()->create())
            ->get(route('dashboard.index'))
            ->assertSuccessful()
            ->getContent();
    }

    public function test_panel_carries_active_template_on_html(): void
    {
        $this->assertStringContainsString('data-template="woodmart"', $this->panel('woodmart'));
        $this->assertStringContainsString('data-template="classic"', $this->panel('classic'));
    }

    public function test_panel_is_wired_to_the_uniform_accent_scope(): void
    {
        // The body carries the panel accent scope regardless of template…
        $html = $this->panel('woodmart');
        $this->assertStringContainsString('zp-user-panel', $html);
    }

    public function test_woodmart_brings_its_accent_into_the_panel(): void
    {
        $html = $this->panel('woodmart');

        // The woodmart accent styles are injected and set the uniform accent var…
        $this->assertStringContainsString('data-template="woodmart"', $html);
        $this->assertStringContainsString('--zp-tpl-accent', $html);
        $this->assertStringContainsString('--wm-accent', $html);   // fixed orange
    }

    public function test_classic_panel_falls_back_to_the_default_accent(): void
    {
        $html = $this->panel('classic');

        // Classic ships no fixed accent → the woodmart orange must NOT leak in;
        // the panel inherits the theme's own accent instead.
        $this->assertStringContainsString('data-template="classic"', $html);
        $this->assertStringNotContainsString('--wm-accent', $html);
        $this->assertStringContainsString('zp-user-panel', $html);
    }

    public function test_panel_structure_is_identical_across_templates(): void
    {
        $classic  = $this->panel('classic');
        $woodmart = $this->panel('woodmart');

        // Same fixed menu, same order, same labels — the accent must not add,
        // remove, or move any sidebar item.
        foreach (['داشبورد', 'سرویس‌های من', 'سفارش‌های من', 'کیف پول', 'اعلان‌ها', 'تیکت‌های پشتیبانی', 'پروفایل'] as $label) {
            $this->assertStringContainsString($label, $classic);
            $this->assertStringContainsString($label, $woodmart);
        }

        // Identical number of sidebar nav items in both.
        $needle = 'flex items-center gap-3 px-3 py-2 rounded-lg';
        $this->assertSame(
            substr_count($classic, $needle),
            substr_count($woodmart, $needle),
            'The panel sidebar must have the same number of items across templates.'
        );

        // The sidebar container and drawer are still present (structure intact).
        $this->assertStringContainsString('id="panel-sidebar"', $woodmart);
        $this->assertStringContainsString('id="panel-backdrop"', $woodmart);
    }

    public function test_chrome_and_active_item_markup_are_untouched(): void
    {
        $html = $this->panel('woodmart');

        // Chrome classes (light/dark) are still there…
        $this->assertStringContainsString('bg-surface', $html);
        $this->assertStringContainsString('text-content', $html);
        $this->assertStringContainsString('border-line', $html);
        // …and the active sidebar item keeps its original markup (accent comes from
        // the CSS variable, not a markup rewrite).
        $this->assertStringContainsString('bg-indigo-600 text-white', $html);
    }

    public function test_panel_light_dark_still_works(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'woodmart');
        $user = User::factory()->create();

        SiteSetting::set('default_appearance', 'light');
        $this->actingAs($user)->get(route('dashboard.index'))->assertSuccessful()->assertSee('zed-light', false);

        SiteSetting::set('default_appearance', 'dark');
        $this->actingAs($user)->get(route('dashboard.index'))->assertSuccessful()->assertDontSee('zed-light', false);
    }
}
