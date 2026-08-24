<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\SiteSetting;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function activatePremium(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'premium');
    }

    public function test_premium_is_registered_as_a_template(): void
    {
        $this->assertArrayHasKey('premium', TemplateManager::templates());
        $this->assertSame('قالب ZED Premium', TemplateManager::templates()['premium']['title']);
        $this->assertTrue(TemplateManager::isValid('premium'));
    }

    public function test_premium_template_renders_when_active(): void
    {
        $this->activatePremium();

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('premium-home-template-marker', false)
            ->assertSee('premium-template-marker', false)
            ->assertSee('ZED Network Console')
            ->assertDontSee('classic-template-marker', false);
    }

    public function test_premium_home_uses_real_active_locations(): void
    {
        $this->activatePremium();

        Location::create([
            'country_name' => 'لوکیشن-پریمیوم-تست',
            'country_code' => 'DE',
            'flag_emoji' => '🇩🇪',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        Location::create([
            'country_name' => 'لوکیشن-مخفی-پریمیوم',
            'country_code' => 'XX',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('لوکیشن-پریمیوم-تست')
            ->assertDontSee('لوکیشن-مخفی-پریمیوم');
    }

    public function test_premium_styles_are_scoped_to_premium_template(): void
    {
        $this->activatePremium();

        $html = $this->get(route('home'))->assertSuccessful()->getContent();

        $this->assertStringContainsString('body[data-template="premium"]', $html);
        $this->assertStringContainsString('--zp-tpl-accent: #3f7cff', $html);
    }
}
