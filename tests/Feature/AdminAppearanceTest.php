<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Theme\AdminAppearanceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAppearanceTest extends TestCase
{
    use RefreshDatabase;

    /** The resolver returns the saved values, not shipped defaults. */
    public function test_resolver_reflects_saved_settings(): void
    {
        SiteSetting::set('icon_size', '1.5rem');
        SiteSetting::set('logo_size', '1.4rem');
        SiteSetting::set('card_density', 'compact');
        SiteSetting::set('table_density', 'comfortable');
        SiteSetting::set('font_scale', '120');

        $r = AdminAppearanceResolver::resolve();

        $this->assertSame('compact', $r['card_density']);
        $this->assertSame('comfortable', $r['table_density']);
        // 1.5rem * 16 * 0.85 = 20.4px (within 12–24 clamp)
        $this->assertSame('20.4px', $r['vars']['--zp-admin-icon-size']);
        // compact card padding preset
        $this->assertSame('12px', $r['vars']['--zp-admin-card-padding']);
        // comfortable table row preset
        $this->assertSame('56px', $r['vars']['--zp-admin-table-row-height']);
        // font scale 120% clamped to 1.15
        $this->assertSame('1.15', $r['vars']['--zp-admin-font-scale']);
    }

    /** Out-of-range / garbage values are clamped to safe CSS, never broken. */
    public function test_resolver_clamps_extreme_values(): void
    {
        SiteSetting::set('icon_size', '9999px');
        SiteSetting::set('logo_size', '0px');
        SiteSetting::set('card_radius', '500rem');
        SiteSetting::set('font_scale', '900');
        SiteSetting::set('admin_sidebar_width', '9999px');
        SiteSetting::set('admin_sidebar_item_height', '2px');

        $r = AdminAppearanceResolver::resolve();

        $this->assertSame('24px', $r['vars']['--zp-admin-icon-size']);   // max 24
        $this->assertSame('24px', $r['vars']['--zp-admin-logo-size']);   // min 24
        $this->assertSame('28px', $r['vars']['--zp-admin-card-radius']); // max 28
        $this->assertSame('1.15', $r['vars']['--zp-admin-font-scale']);  // max 1.15
        $this->assertSame('340px', $r['vars']['--zp-admin-sidebar-width']);       // max 340
        $this->assertSame('34px', $r['vars']['--zp-admin-sidebar-item-height']);  // min 34
    }

    /** Admin sidebar controls resolve and reflect saved values. */
    public function test_resolver_resolves_sidebar_controls(): void
    {
        SiteSetting::set('admin_sidebar_brand_size', '20px');
        SiteSetting::set('admin_sidebar_font_size', '13px');
        SiteSetting::set('admin_sidebar_width', '320px');

        $vars = AdminAppearanceResolver::resolve()['vars'];

        $this->assertSame('20px', $vars['--zp-admin-sidebar-brand-size']);
        $this->assertSame('13px', $vars['--zp-admin-sidebar-font-size']);
        $this->assertSame('320px', $vars['--zp-admin-sidebar-width']);
        $this->assertSame('320px', $vars['--sidebar-width']); // Filament layout var
    }

    /** Every documented admin variable is present and non-empty. */
    public function test_resolver_emits_all_admin_variables(): void
    {
        $vars = AdminAppearanceResolver::resolve()['vars'];
        foreach ([
            '--zp-admin-icon-size', '--zp-admin-sidebar-icon-size', '--zp-admin-action-icon-size',
            '--zp-admin-form-icon-size', '--zp-admin-select-caret-size', '--zp-admin-logo-size',
            '--zp-admin-font-scale', '--zp-admin-card-radius', '--zp-admin-button-radius',
            '--zp-admin-card-padding', '--zp-admin-table-row-height', '--zp-admin-form-control-height',
            '--zp-admin-density-gap', '--zp-admin-animation-speed',
        ] as $name) {
            $this->assertArrayHasKey($name, $vars, "missing {$name}");
            $this->assertNotSame('', $vars[$name]);
        }
    }

    /** /zed-admin renders the declarative style tag carrying the saved values. */
    public function test_admin_page_injects_resolved_theme_vars(): void
    {
        SiteSetting::set('icon_size', '1.5rem');
        SiteSetting::set('table_density', 'compact');

        $admin = User::factory()->create(['is_admin' => true]);
        $html  = $this->actingAs($admin)->get('/zed-admin')->getContent();

        $this->assertStringContainsString('zedproxy-admin-theme-vars', $html);
        foreach ([
            '--zp-admin-icon-size', '--zp-admin-sidebar-icon-size', '--zp-admin-logo-size',
            '--zp-admin-font-scale', '--zp-admin-table-row-height', '--zp-admin-card-padding',
            '--zp-admin-sidebar-brand-size', '--zp-admin-sidebar-width', '--zp-admin-sidebar-font-size',
        ] as $name) {
            $this->assertStringContainsString($name, $html, "admin page missing {$name}");
        }
        // The resolved icon value for 1.5rem (20.4px) must appear in the page.
        $this->assertStringContainsString('--zp-admin-icon-size: 20.4px', $html);
        // data attributes for scoped selectors.
        $this->assertStringContainsString('data-zp-admin-density', $html);
    }

    /** Theme Studio renders the diagnostics panel and the visual sandbox. */
    public function test_theme_studio_has_diagnostics_and_sandbox(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $html  = $this->actingAs($admin)->get('/zed-admin/theme-studio')->getContent();

        $this->assertStringContainsString('عیب‌یابی تنظیمات ظاهر', $html);
        $this->assertStringContainsString('نمونهٔ زندهٔ پنل مدیریت', $html);
        $this->assertStringContainsString('بررسی اعمال تنظیمات', $html);
        // Admin sidebar control panel is present.
        $this->assertStringContainsString('سایدبار پنل مدیریت', $html);
        $this->assertStringContainsString('عرض سایدبار', $html);
    }
}
