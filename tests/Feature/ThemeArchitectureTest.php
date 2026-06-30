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

    /** admin_* override wins over the legacy shared key in the resolver. */
    public function test_admin_override_beats_legacy_in_resolver(): void
    {
        SiteSetting::set('icon_size', '1rem');                       // legacy/user
        $this->assertSame('13.6px', AdminAppearanceResolver::resolve()['vars']['--zp-admin-icon-size']); // 16*0.85

        SiteSetting::set('admin_icon_size', '22px');                 // admin override
        $this->assertSame('22px', AdminAppearanceResolver::resolve()['vars']['--zp-admin-icon-size']);
        // …and the user-side key is untouched.
        $this->assertSame('1rem', SiteSetting::get('icon_size'));
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
