<?php

namespace Tests\Feature;

use App\Filament\Pages\AppearanceSettings;
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

    // ── Appearance settings (admin) ──────────────────────────────────────────

    public function test_appearance_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/appearance')
            ->assertSuccessful()
            ->assertSee('تنظیمات ظاهر')
            ->assertSee('رنگ‌بندی سایت');
    }

    public function test_old_theme_studio_redirects_to_appearance(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/theme-studio')
            ->assertRedirect('/zed-admin/appearance');
    }

    public function test_appearance_settings_persist(): void
    {
        Livewire::actingAs($this->admin())
            ->test(AppearanceSettings::class)
            ->set('data.appearance_mode', 'light')
            ->set('data.site_theme_preset', 'luxury_gold')
            ->set('data.primary_color', '#d4af37')
            ->set('data.admin_density', 'compact')
            ->set('data.admin_sidebar_size', 'small')
            ->set('data.admin_brand_text', 'Panel X')
            ->call('save');

        $this->assertSame('light', SiteSetting::get('appearance_mode'));
        $this->assertSame('light', SiteSetting::get('default_appearance')); // synced
        $this->assertSame('luxury_gold', SiteSetting::get('site_theme_preset'));
        $this->assertSame('compact', SiteSetting::get('admin_density'));
        $this->assertSame('small', SiteSetting::get('admin_sidebar_size'));
        $this->assertSame('Panel X', SiteSetting::get('admin_brand_text'));
    }

    public function test_preset_applies_to_user_surface(): void
    {
        SiteSetting::set('site_theme_preset', 'minimal_light');

        // The chosen preset's palette is injected on the user dashboard.
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard.index'))
            ->assertSuccessful()
            ->assertSee('--zp-primary:#2563eb', false);
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
