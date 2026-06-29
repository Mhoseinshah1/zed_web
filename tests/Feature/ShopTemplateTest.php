<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Theme\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function activateShop(): void
    {
        SiteSetting::set(TemplateManager::SETTING_KEY, 'shop');
    }

    // ── Registration in the existing selector ────────────────────────────────

    public function test_shop_is_registered_as_a_template(): void
    {
        $this->assertArrayHasKey('shop', TemplateManager::templates());
        $this->assertSame('قالب فروشگاهی', TemplateManager::templates()['shop']['title']);
        $this->assertTrue(TemplateManager::isValid('shop'));
        $this->assertCount(3, TemplateManager::templates());
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function test_shop_template_renders_when_active(): void
    {
        $this->activateShop();
        $this->get(route('home'))->assertSuccessful()
            ->assertSee('shop-home-marker', false)
            ->assertSee('shop-template-marker', false)      // shop layout (topbar/nav)
            ->assertDontSee('classic-template-marker', false)
            ->assertDontSee('modern-home-marker', false);
    }

    public function test_switching_to_shop_changes_output(): void
    {
        $classic = $this->get(route('home'))->getContent();
        $this->activateShop();
        $shop = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('classic-template-marker', $classic);
        $this->assertStringContainsString('shop-home-marker', $shop);
        $this->assertNotSame($classic, $shop);
    }

    public function test_shop_lists_real_locations(): void
    {
        $this->activateShop();
        Location::create(['country_name' => 'آلمان-تست', 'country_code' => 'DE', 'flag_emoji' => '🇩🇪', 'is_active' => true, 'sort_order' => 1]);
        Location::create(['country_name' => 'مخفی-تست', 'country_code' => 'XX', 'is_active' => false, 'sort_order' => 2]);

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('آلمان-تست')->assertDontSee('مخفی-تست');
    }

    // ── Testimonials gating ──────────────────────────────────────────────────

    public function test_testimonials_hidden_when_disabled(): void
    {
        $this->activateShop();
        SiteSetting::set('shop_testimonials_enabled', 'false');
        Testimonial::create(['name' => 'کاربر-آزمایشی', 'body' => 'متن نظر', 'rating' => 5, 'is_active' => true]);

        $this->get(route('home'))->assertSuccessful()->assertDontSee('کاربر-آزمایشی');
    }

    public function test_testimonials_hidden_when_enabled_but_empty(): void
    {
        $this->activateShop();
        SiteSetting::set('shop_testimonials_enabled', 'true');
        Testimonial::query()->delete();

        // The «رضایت کاربران» tag should not appear with zero testimonials.
        $this->get(route('home'))->assertSuccessful()->assertDontSee('رضایت کاربران');
    }

    public function test_testimonials_render_when_enabled_and_present(): void
    {
        $this->activateShop();
        SiteSetting::set('shop_testimonials_enabled', 'true');
        Testimonial::create(['name' => 'کاربر-راضی-۹۹', 'role' => 'کاربر پلن حرفه‌ای', 'body' => 'سرویس عالی بود', 'rating' => 5, 'is_active' => true]);

        $this->get(route('home'))->assertSuccessful()
            ->assertSee('کاربر-راضی-۹۹')->assertSee('سرویس عالی بود');
    }

    public function test_inactive_testimonial_not_shown(): void
    {
        $this->activateShop();
        SiteSetting::set('shop_testimonials_enabled', 'true');
        Testimonial::create(['name' => 'نظر-غیرفعال', 'body' => 'x', 'rating' => 4, 'is_active' => false]);

        $this->get(route('home'))->assertSuccessful()->assertDontSee('نظر-غیرفعال');
    }

    // ── Admin resource ───────────────────────────────────────────────────────

    public function test_testimonial_resource_loads(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/zed-admin/testimonials')->assertSuccessful();
    }
}
