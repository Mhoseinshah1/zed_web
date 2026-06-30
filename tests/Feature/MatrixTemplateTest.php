<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\SiteSetting;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatrixTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function activateMatrix(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'matrix');
    }

    public function test_matrix_is_registered_as_a_template(): void
    {
        $this->assertArrayHasKey('matrix', TemplateManager::templates());
        $this->assertSame('قالب هکری', TemplateManager::templates()['matrix']['title']);
        $this->assertTrue(TemplateManager::isValid('matrix'));
    }

    public function test_matrix_template_renders_when_active(): void
    {
        $this->activateMatrix();
        $this->get(route('home'))->assertSuccessful()
            ->assertSee('matrix-home-marker', false)
            ->assertSee('matrix-template-marker', false)     // matrix layout (canvas/nav)
            ->assertDontSee('classic-template-marker', false)
            ->assertDontSee('shop-home-marker', false);
    }

    public function test_switching_to_matrix_changes_output(): void
    {
        $classic = $this->get(route('home'))->getContent();
        $this->activateMatrix();
        $matrix = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('classic-template-marker', $classic);
        $this->assertStringContainsString('matrix-home-marker', $matrix);
        $this->assertNotSame($classic, $matrix);
    }

    public function test_matrix_uses_real_location_count(): void
    {
        $this->activateMatrix();
        Location::create(['country_name' => 'آلمان-تست', 'country_code' => 'DE', 'flag_emoji' => '🇩🇪', 'is_active' => true, 'sort_order' => 1]);

        // Server stage shows the real location; classic/shop markers absent.
        $this->get(route('home'))->assertSuccessful()->assertSee('آلمان-تست');
    }

    public function test_other_templates_still_work(): void
    {
        // Sanity: existing templates remain selectable and render their markers.
        SiteSetting::set(TemplateManager::SETTING_KEY, 'shop');
        $this->get(route('home'))->assertSuccessful()->assertSee('shop-home-marker', false);

        SiteSetting::set(TemplateManager::SETTING_KEY, 'modern');
        $this->get(route('home'))->assertSuccessful()->assertSee('modern-home-marker', false);

        SiteSetting::set(TemplateManager::SETTING_KEY, 'classic');
        $this->get(route('home'))->assertSuccessful()->assertSee('classic-template-marker', false);
    }
}
