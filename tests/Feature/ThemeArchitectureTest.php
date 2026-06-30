<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Theme\AdminAppearanceResolver;
use App\Services\Theme\ThemeRegistry;
use App\Services\Theme\ThemeSettingsService;
use App\Support\Theme\CssVariableBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** ThemeRegistry returns all 15 presets, each fully enriched. */
    public function test_theme_registry_returns_all_presets(): void
    {
        $all = ThemeRegistry::all();
        $this->assertCount(15, $all);
        $this->assertArrayHasKey('zed-ocean', $all);

        foreach ($all as $slug => $p) {
            foreach (['slug', 'title', 'name', 'group', 'description', 'colors', 'card_shadow', 'button_style', 'badge_style'] as $field) {
                $this->assertArrayHasKey($field, $p, "{$slug} missing {$field}");
            }
            foreach (['primary', 'secondary', 'accent', 'success', 'warning', 'danger', 'gradient'] as $c) {
                $this->assertArrayHasKey($c, $p['colors'], "{$slug} colors missing {$c}");
            }
        }
    }

    /** Settings service reads live and invalidates its memo on write. */
    public function test_settings_service_invalidates_on_write(): void
    {
        ThemeSettingsService::set('admin_icon_size', '16px');
        $this->assertSame('16px', ThemeSettingsService::get('admin_icon_size'));

        // A plain model write must also flush the memo (no stale reads).
        SiteSetting::set('admin_icon_size', '20px');
        $this->assertSame('20px', ThemeSettingsService::get('admin_icon_size'));
    }

    /** firstOf implements admin_* → legacy fallback ordering. */
    public function test_first_of_prefers_admin_then_legacy(): void
    {
        SiteSetting::set('icon_size', '1.5rem');
        $this->assertSame('1.5rem', ThemeSettingsService::firstOf(['admin_icon_size', 'icon_size'], 'x'));

        SiteSetting::set('admin_icon_size', '18px');
        $this->assertSame('18px', ThemeSettingsService::firstOf(['admin_icon_size', 'icon_size'], 'x'));
    }

    /** AppearanceManager exposes exactly the 5 practical presets. */
    public function test_appearance_manager_has_five_presets(): void
    {
        $keys = \App\Services\Theme\AppearanceManager::presetKeys();
        $this->assertSame(
            ['default_dark', 'minimal_light', 'luxury_gold', 'professional_blue', 'graphite_admin'],
            $keys
        );
    }

    /**
     * Preset selection drives the ACCENT colour variables. The neutral chrome
     * ramp (bg/surface/text/border) is intentionally NOT emitted here — it is
     * owned by the light/dark appearance so the toggle can flip it.
     */
    public function test_preset_changes_accent_vars(): void
    {
        SiteSetting::set('site_theme_preset', 'minimal_light');
        $vars = \App\Services\Theme\AppearanceManager::colorVars();
        $this->assertSame('#2563eb', $vars['--zp-primary']);
        $this->assertSame('#0ea5e9', $vars['--zp-accent']);
        // Neutral chrome must NOT be part of the injected colour vars.
        $this->assertArrayNotHasKey('--zp-surface', $vars);
        $this->assertArrayNotHasKey('--zp-bg', $vars);
    }

    /** primary_color / accent_color override the preset colours. */
    public function test_brand_colors_override_preset(): void
    {
        SiteSetting::set('site_theme_preset', 'default_dark');
        SiteSetting::set('primary_color', '#ff0000');
        SiteSetting::set('accent_color', '00ff00');
        $vars = \App\Services\Theme\AppearanceManager::colorVars();
        $this->assertSame('#ff0000', $vars['--zp-primary']);
        $this->assertSame('#00ff00', $vars['--zp-accent']);
    }

    /** CssVariableBuilder strips injection characters and normalises names. */
    public function test_css_variable_builder_is_safe(): void
    {
        $out = CssVariableBuilder::declarations([
            'zp-x'           => '16px',
            '--zp-y'         => 'red;}<script>',
            '--zp-z'         => 'calc(100% * 1)',
        ]);
        $this->assertStringContainsString('--zp-x:16px;', $out);
        $this->assertStringContainsString('--zp-z:calc(100% * 1);', $out);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString('}', $out);
    }
}
