<?php

namespace Tests\Feature;

use App\Filament\Pages\ThemeStudio;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Theme\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Presets / resolution ─────────────────────────────────────────────────

    public function test_presets_catalog_is_complete_and_valid(): void
    {
        $presets = ThemeManager::presets();
        $this->assertCount(15, $presets);
        $this->assertTrue(ThemeManager::isValidPreset('zed-ocean'));
        $this->assertFalse(ThemeManager::isValidPreset('does-not-exist'));
        $this->assertFalse(ThemeManager::isValidPreset(null));

        // Every preset carries the rich metadata the Studio depends on.
        foreach ($presets as $key => $p) {
            $this->assertArrayHasKey('title', $p);
            $this->assertArrayHasKey('group', $p);
            $this->assertContains($p['group'], ['dark', 'light', 'special']);
            $this->assertArrayHasKey('colors', $p);
            $this->assertArrayHasKey('gradient', $p['colors']);
        }
    }

    public function test_groups_partition_all_presets(): void
    {
        $groups = ThemeManager::groups();
        $total = count($groups['dark']) + count($groups['light']) + count($groups['special']);
        $this->assertSame(15, $total);
    }

    public function test_legacy_slug_is_normalised(): void
    {
        $this->assertSame('zed-cyber-dark', ThemeManager::normalize('cyber-dark'));
        $this->assertSame('zed-ocean', ThemeManager::normalize('zed-ocean'));
        $this->assertNull(ThemeManager::normalize('nope'));
    }

    public function test_default_theme_falls_back_to_default(): void
    {
        $this->assertSame(ThemeManager::DEFAULT_THEME, ThemeManager::defaultTheme(ThemeManager::SURFACE_USER));
        SiteSetting::set('default_theme_user', 'invalid-key');
        $this->assertSame(ThemeManager::DEFAULT_THEME, ThemeManager::defaultTheme(ThemeManager::SURFACE_USER));
        // Legacy stored value still resolves.
        SiteSetting::set('default_theme_user', 'emerald');
        $this->assertSame('zed-emerald', ThemeManager::defaultTheme(ThemeManager::SURFACE_USER));
    }

    public function test_user_theme_preference_is_respected_when_enabled(): void
    {
        SiteSetting::set('enabled_themes', 'zed-ocean,zed-emerald');
        $user = User::factory()->create(['theme_preference' => 'zed-emerald']);
        $this->assertSame('zed-emerald', ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user));
    }

    public function test_disabled_theme_preference_falls_back_to_default(): void
    {
        SiteSetting::set('enabled_themes', 'zed-ocean,zed-cyber-dark');
        SiteSetting::set('default_theme_user', 'zed-cyber-dark');
        $user = User::factory()->create(['theme_preference' => 'zed-emerald']); // not enabled
        $this->assertSame('zed-cyber-dark', ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user));
    }

    public function test_force_global_theme_overrides_user(): void
    {
        SiteSetting::set('force_global_theme', 'true');
        SiteSetting::set('default_theme_user', 'zed-sunset');
        $user = User::factory()->create(['theme_preference' => 'zed-emerald']);
        $this->assertSame('zed-sunset', ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user));
    }

    public function test_appearance_resolves_from_user(): void
    {
        $user = User::factory()->create(['appearance' => 'light']);
        $this->assertSame('light', ThemeManager::resolveAppearance($user));
    }

    public function test_animation_intensity_back_compat(): void
    {
        SiteSetting::set('animation_intensity', 'subtle');
        $this->assertSame('low', ThemeManager::animationIntensity());
        SiteSetting::set('animation_intensity', 'high');
        $this->assertSame('high', ThemeManager::animationIntensity());
    }

    // ── Switcher endpoint ────────────────────────────────────────────────────

    public function test_user_can_save_theme_and_appearance(): void
    {
        SiteSetting::set('enabled_themes', 'zed-ocean,zed-emerald,zed-cyber-dark');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('theme.update'), ['theme' => 'zed-emerald', 'appearance' => 'light'])
            ->assertStatus(302);

        $user->refresh();
        $this->assertSame('zed-emerald', $user->theme_preference);
        $this->assertSame('light', $user->appearance);
    }

    public function test_user_cannot_save_disabled_theme(): void
    {
        SiteSetting::set('enabled_themes', 'zed-ocean,zed-cyber-dark');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('theme.update'), ['theme' => 'zed-neon']);

        $user->refresh();
        $this->assertNull($user->theme_preference);
    }

    public function test_guest_theme_is_stored_in_cookie(): void
    {
        SiteSetting::set('enabled_themes', 'zed-ocean,zed-sunset');
        $this->post(route('theme.update'), ['theme' => 'zed-sunset'])
            ->assertCookie('zed_theme', 'zed-sunset');
    }

    // ── Theme panel (پنل تم) ─────────────────────────────────────────────────

    public function test_theme_panel_loads(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/theme-studio')
            ->assertSuccessful()
            ->assertSee('پنل تم')
            ->assertSee('پیش‌نمایش زنده');
    }

    public function test_old_appearance_url_redirects_to_theme_panel(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/appearance')
            ->assertRedirect('/zed-admin/theme-studio');
    }

    /** Persisting writes the right keys and never wipes Advanced/other keys. */
    public function test_theme_panel_persists_and_preserves_keys(): void
    {
        // A key NOT exposed by this panel must survive a save untouched.
        SiteSetting::set('admin_density', 'compact');

        Livewire::actingAs($this->admin())
            ->test(ThemeStudio::class)
            ->call('persist', [
                'scope'                 => 'public',
                'default_theme_public'  => 'zed-aurora',
                'default_theme_user'    => 'zed-emerald',
                'default_theme_admin'   => 'zed-graphite',
                'enabled_themes'        => ['zed-aurora'],
                'accent'                => '#ff0000',
                'accent2'               => '#00ff00',
                'appearance'            => 'light',
                'radius'                => 16,
                'font_scale'            => 110,
                'allow_user_theme_switch'      => true,
                'allow_user_appearance_switch' => false,
                'force_global_theme'    => true,
                'animation_intensity'   => 'high',
                'icon_size'             => '1.5rem',
                'table_density'         => 'compact',
                // logo_size/sidebar_icon_size/image_size/card_density omitted on purpose
            ]);

        $this->assertSame('zed-aurora', SiteSetting::get('default_theme_public'));
        $this->assertSame('zed-emerald', SiteSetting::get('default_theme_user'));
        $this->assertSame('zed-graphite', SiteSetting::get('default_theme_admin'));
        $this->assertSame('light', SiteSetting::get('default_appearance'));
        $this->assertSame('#ff0000', SiteSetting::get('primary_color'));
        $this->assertSame('#00ff00', SiteSetting::get('accent_color'));
        $this->assertSame('16px', SiteSetting::get('card_radius'));
        $this->assertSame('12px', SiteSetting::get('button_radius'));
        $this->assertSame(110, SiteSetting::get('font_scale'));
        $this->assertSame('high', SiteSetting::get('animation_intensity'));
        $this->assertSame('1.5rem', SiteSetting::get('icon_size'));

        // Omitted Advanced keys keep their previous/default value (not wiped).
        $this->assertSame('1.15rem', SiteSetting::get('logo_size'));
        $this->assertSame('2.5rem', SiteSetting::get('image_size'));

        // And the unrelated key this panel does not manage is untouched.
        $this->assertSame('compact', SiteSetting::get('admin_density'));

        // Required default themes stay enabled (guard).
        $enabled = explode(',', (string) SiteSetting::get('enabled_themes'));
        $this->assertContains('zed-emerald', $enabled);
        $this->assertContains('zed-graphite', $enabled);
    }

    public function test_preset_applies_to_user_surface(): void
    {
        SiteSetting::set('site_theme_preset', 'minimal_light');

        // The chosen preset's ACCENT is injected on the user dashboard.
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard.index'))
            ->assertSuccessful()
            ->assertSee('--zp-primary:#2563eb', false);
    }

    /**
     * Root-cause regression: light mode must flip the chrome. The html carries
     * the zed-light class AND the neutral chrome ramp is NOT inlined on <html>
     * (otherwise inline style would override html.zed-light and defeat it).
     */
    public function test_light_mode_flips_public_chrome(): void
    {
        SiteSetting::set('default_appearance', 'light');
        SiteSetting::set('appearance_mode', 'light');

        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('zed-light', $html);
        // Neutral ramp must come from html.zed-light, never from inline style.
        $this->assertStringNotContainsString('--zp-bg:', $html);
        $this->assertStringNotContainsString('--zp-surface:', $html);
        $this->assertStringNotContainsString('--zp-text:', $html);
        // Accent is still injected (appearance-independent).
        $this->assertStringContainsString('--zp-primary:', $html);
    }

    public function test_dark_mode_default_has_no_light_class(): void
    {
        SiteSetting::set('default_appearance', 'dark');
        $html = $this->get(route('home'))->getContent();
        $this->assertStringNotContainsString('zed-light', $html);
    }

    // ── Key pages still render with theme wiring ─────────────────────────────

    public function test_public_home_renders_with_theme_attributes(): void
    {
        $this->get(route('home'))
            ->assertSuccessful()
            ->assertSee('data-theme', false);
    }

    public function test_user_dashboard_renders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard.index'))->assertSuccessful();
    }

    public function test_profile_page_shows_theme_switcher(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard.profile'))
            ->assertSuccessful()
            ->assertSee('تنظیمات ظاهر');
    }
}
