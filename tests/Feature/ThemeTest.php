<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Theme\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertTrue(ThemeManager::isValidPreset('cyber-dark'));
        $this->assertFalse(ThemeManager::isValidPreset('does-not-exist'));
        $this->assertFalse(ThemeManager::isValidPreset(null));
    }

    public function test_default_theme_falls_back_to_cyber_dark(): void
    {
        $this->assertSame('cyber-dark', ThemeManager::defaultTheme(ThemeManager::SURFACE_USER));
        SiteSetting::set('default_theme_user', 'invalid-key');
        $this->assertSame('cyber-dark', ThemeManager::defaultTheme(ThemeManager::SURFACE_USER));
    }

    public function test_user_theme_preference_is_respected_when_enabled(): void
    {
        SiteSetting::set('enabled_themes', 'cyber-dark,emerald');
        $user = User::factory()->create(['theme_preference' => 'emerald']);
        $this->assertSame('emerald', ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user));
    }

    public function test_disabled_theme_preference_falls_back_to_default(): void
    {
        SiteSetting::set('enabled_themes', 'cyber-dark,ocean');
        SiteSetting::set('default_theme_user', 'ocean');
        $user = User::factory()->create(['theme_preference' => 'emerald']); // not enabled
        $this->assertSame('ocean', ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user));
    }

    public function test_force_global_theme_overrides_user(): void
    {
        SiteSetting::set('force_global_theme', 'true');
        SiteSetting::set('default_theme_user', 'royal');
        $user = User::factory()->create(['theme_preference' => 'emerald']);
        $this->assertSame('royal', ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user));
    }

    public function test_appearance_resolves_from_user(): void
    {
        $user = User::factory()->create(['appearance' => 'light']);
        $this->assertSame('light', ThemeManager::resolveAppearance($user));
    }

    // ── Switcher endpoint ────────────────────────────────────────────────────

    public function test_user_can_save_theme_and_appearance(): void
    {
        SiteSetting::set('enabled_themes', 'cyber-dark,emerald,ocean');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('theme.update'), ['theme' => 'emerald', 'appearance' => 'light'])
            ->assertStatus(302);

        $user->refresh();
        $this->assertSame('emerald', $user->theme_preference);
        $this->assertSame('light', $user->appearance);
    }

    public function test_user_cannot_save_disabled_theme(): void
    {
        SiteSetting::set('enabled_themes', 'cyber-dark,ocean');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('theme.update'), ['theme' => 'neon']);

        $user->refresh();
        $this->assertNull($user->theme_preference);
    }

    public function test_guest_theme_is_stored_in_cookie(): void
    {
        SiteSetting::set('enabled_themes', 'cyber-dark,sunset');
        $this->post(route('theme.update'), ['theme' => 'sunset'])
            ->assertCookie('zed_theme', 'sunset');
    }

    // ── Admin settings page ──────────────────────────────────────────────────

    public function test_admin_appearance_settings_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/settings/appearance')
            ->assertSuccessful()
            ->assertSee('تنظیمات ظاهر و تم');
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
            ->assertSee('ظاهر و تم');
    }
}
