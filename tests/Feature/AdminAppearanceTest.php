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

    /** admin_density maps onto the real density variables. */
    public function test_density_changes_resolved_vars(): void
    {
        SiteSetting::set('admin_density', 'compact');
        $vars = AdminAppearanceResolver::resolve()['vars'];
        $this->assertSame('40px', $vars['--zp-admin-table-row-height']);
        $this->assertSame('36px', $vars['--zp-admin-sidebar-item-height']);
        $this->assertSame('12px', $vars['--zp-admin-card-padding']);
        $this->assertSame('38px', $vars['--zp-admin-form-control-height']);

        SiteSetting::set('admin_density', 'comfortable');
        $vars = AdminAppearanceResolver::resolve()['vars'];
        $this->assertSame('56px', $vars['--zp-admin-table-row-height']);
        $this->assertSame('46px', $vars['--zp-admin-sidebar-item-height']);
        $this->assertSame('20px', $vars['--zp-admin-card-padding']);
    }

    /** admin_sidebar_size maps onto the real sidebar variables. */
    public function test_sidebar_size_changes_resolved_vars(): void
    {
        SiteSetting::set('admin_sidebar_size', 'small');
        $vars = AdminAppearanceResolver::resolve()['vars'];
        $this->assertSame('250px', $vars['--zp-admin-sidebar-width']);
        $this->assertSame('250px', $vars['--sidebar-width']);
        $this->assertSame('20px', $vars['--zp-admin-sidebar-brand-size']);
        $this->assertSame('13px', $vars['--zp-admin-sidebar-font-size']);
        $this->assertSame('15px', $vars['--zp-admin-sidebar-icon-size']);

        SiteSetting::set('admin_sidebar_size', 'large');
        $vars = AdminAppearanceResolver::resolve()['vars'];
        $this->assertSame('320px', $vars['--zp-admin-sidebar-width']);
        $this->assertSame('28px', $vars['--zp-admin-sidebar-brand-size']);
        $this->assertSame('18px', $vars['--zp-admin-sidebar-icon-size']);
    }

    /** Defaults are normal/normal and the select caret is fixed + small. */
    public function test_defaults_are_sensible(): void
    {
        $r = AdminAppearanceResolver::resolve();
        $this->assertSame('normal', $r['density']);
        $this->assertSame('normal', $r['sidebar_size']);
        $this->assertSame('280px', $r['vars']['--zp-admin-sidebar-width']);
        $this->assertSame('14px', $r['vars']['--zp-admin-select-caret-size']);
        $this->assertSame('16px', $r['vars']['--zp-admin-icon-size']);
    }

    /** Old fine-grained settings migrate onto the two practical presets. */
    public function test_legacy_settings_migrate(): void
    {
        SiteSetting::set('card_density', 'compact');         // legacy density
        SiteSetting::set('admin_sidebar_width', '320px');    // legacy explicit width
        $r = AdminAppearanceResolver::resolve();
        $this->assertSame('compact', $r['density']);
        $this->assertSame('large', $r['sidebar_size']);
    }

    /** Brand text + display resolve. */
    public function test_brand_options(): void
    {
        SiteSetting::set('admin_brand_text', 'My Panel');
        SiteSetting::set('admin_brand_display', 'logo_text');
        $r = AdminAppearanceResolver::resolve();
        $this->assertSame('My Panel', $r['brand_text']);
        $this->assertSame('logo_text', $r['brand_display']);
    }

    /** /zed-admin injects the appearance style tag with colour + admin vars. */
    public function test_admin_page_injects_appearance_vars(): void
    {
        SiteSetting::set('admin_density', 'compact');
        SiteSetting::set('admin_sidebar_size', 'small');

        $admin = User::factory()->create(['is_admin' => true]);
        $html  = $this->actingAs($admin)->get('/zed-admin')->getContent();

        $this->assertStringContainsString('zedproxy-appearance-vars', $html);
        foreach ([
            '--zp-primary', '--zp-bg', '--zp-surface',
            '--zp-admin-sidebar-width', '--zp-admin-sidebar-brand-size',
            '--zp-admin-table-row-height', '--zp-admin-card-padding',
        ] as $name) {
            $this->assertStringContainsString($name, $html, "admin page missing {$name}");
        }
        $this->assertStringContainsString('--zp-admin-sidebar-width: 250px', $html);
        $this->assertStringContainsString('data-zp-admin-density', $html);
    }

    /** The admin shell sheet is injected and the panel's radius flows into it. */
    public function test_admin_shell_css_injected_and_radius_wired(): void
    {
        SiteSetting::set('card_radius', '16px');
        SiteSetting::set('button_radius', '12px');

        $admin = User::factory()->create(['is_admin' => true]);
        $html  = $this->actingAs($admin)->get('/zed-admin')->getContent();

        // Shell sheet present and scoped to .fi-body.
        $this->assertStringContainsString('id="zp-admin-shell"', $html);
        $this->assertStringContainsString('.fi-body .fi-badge', $html);
        $this->assertStringContainsString('.fi-body .fi-modal-window', $html);
        // The corner-radius slider value is wired into the admin variables.
        $this->assertStringContainsString('--zp-admin-card-radius: 16px', $html);
        $this->assertStringContainsString('--zp-admin-button-radius: 12px', $html);
        $this->assertStringContainsString('--zp-card-radius: 16px', $html);
    }

    /** Resolver exposes the new shell polish variables. */
    public function test_resolver_exposes_shell_vars(): void
    {
        $vars = AdminAppearanceResolver::resolve()['vars'];
        $this->assertArrayHasKey('--zp-admin-card-shadow', $vars);
        $this->assertArrayHasKey('--zp-admin-modal-radius', $vars);
        $this->assertArrayHasKey('--zp-admin-ring', $vars);
    }

    /** The theme panel renders, including the sandboxed live preview. */
    public function test_theme_panel_renders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $html  = $this->actingAs($admin)->get('/zed-admin/theme-studio')->getContent();

        $this->assertStringContainsString('پنل تم', $html);
        $this->assertStringContainsString('پیش‌نمایش زنده', $html);
        $this->assertStringContainsString('تم آماده', $html);
    }

    /** The old standalone appearance URL redirects to the theme panel. */
    public function test_old_appearance_url_redirects(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/zed-admin/appearance')
            ->assertRedirect('/zed-admin/theme-studio');
    }
}
