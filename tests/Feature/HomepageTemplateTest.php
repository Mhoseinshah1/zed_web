<?php

namespace Tests\Feature;

use App\Filament\Pages\TemplateStudio;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HomepageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── TemplateManager ──────────────────────────────────────────────────────

    public function test_default_template_is_classic(): void
    {
        $this->assertSame('classic', TemplateManager::activeTemplate());
        $this->assertCount(2, TemplateManager::templates());
    }

    public function test_validation_and_normalisation(): void
    {
        $this->assertTrue(TemplateManager::isValid('modern'));
        $this->assertFalse(TemplateManager::isValid('nope'));
        $this->assertSame('modern', TemplateManager::normalize(' modern '));
        $this->assertNull(TemplateManager::normalize('bogus'));
    }

    public function test_invalid_value_falls_back_to_default(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'garbage');
        $this->assertSame('classic', TemplateManager::activeTemplate());
    }

    // ── Homepage rendering switches with the setting ─────────────────────────

    public function test_classic_template_renders_by_default(): void
    {
        $this->get(route('home'))->assertSuccessful()
            ->assertSee('classic-template-marker', false)
            ->assertDontSee('modern-home-marker', false);
    }

    public function test_modern_template_renders_when_active(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'modern');

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('modern-home-marker', false)
            ->assertSee('modern-template-marker', false)   // modern layout (topbar/nav)
            ->assertDontSee('classic-template-marker', false);
    }

    public function test_switching_template_changes_output(): void
    {
        $classic = $this->get(route('home'))->getContent();
        SiteSetting::set(TemplateManager::SETTING_KEY, 'modern');
        $modern = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('classic-template-marker', $classic);
        $this->assertStringContainsString('modern-home-marker', $modern);
        $this->assertNotSame($classic, $modern);
    }

    public function test_modern_template_uses_theme_tokens_not_hardcoded_colors(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'modern');
        $html = $this->get(route('home'))->getContent();
        // Modern body relies on theme-bound utility/component classes.
        $this->assertStringContainsString('zed-gradient', $html);
    }

    // ── Admin Template Studio ────────────────────────────────────────────────

    public function test_template_studio_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get('/zed-admin/templates')
            ->assertSuccessful()
            ->assertSee('قالب‌های صفحه اصلی');
    }

    public function test_template_studio_persists_selection(): void
    {
        Livewire::actingAs($this->admin())
            ->test(TemplateStudio::class)
            ->call('persist', 'modern');

        $this->assertSame('modern', SiteSetting::get(TemplateManager::SETTING_KEY));
        $this->assertSame('modern', TemplateManager::activeTemplate());
    }

    public function test_template_studio_ignores_invalid_template(): void
    {
        Livewire::actingAs($this->admin())
            ->test(TemplateStudio::class)
            ->call('persist', 'hacker');

        $this->assertSame('classic', TemplateManager::activeTemplate());
    }
}
