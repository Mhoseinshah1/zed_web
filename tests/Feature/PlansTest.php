<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Location;
use App\Models\Plan;
use App\Models\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlansTest extends TestCase
{
    use RefreshDatabase;

    // ── Homepage ─────────────────────────────────────────────────────────────

    public function test_homepage_loads_successfully(): void
    {
        $response = $this->get('/');
        $response->assertOk();
    }

    public function test_homepage_shows_active_plans(): void
    {
        Plan::factory()->create(['name' => 'استارتر', 'is_active' => true,  'sort_order' => 1]);
        Plan::factory()->create(['name' => 'آرشیو',   'is_active' => false, 'sort_order' => 2]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('استارتر');
        $response->assertDontSee('آرشیو');
    }

    public function test_homepage_shows_active_features(): void
    {
        Feature::factory()->create(['title' => 'اتصال پایدار', 'is_active' => true]);
        Feature::factory()->create(['title' => 'ویژگی غیرفعال', 'is_active' => false]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('اتصال پایدار');
        $response->assertDontSee('ویژگی غیرفعال');
    }

    public function test_homepage_shows_active_locations(): void
    {
        Location::factory()->create(['country_name' => 'آلمان', 'is_active' => true]);
        Location::factory()->create(['country_name' => 'مریخ',  'is_active' => false]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('آلمان');
        $response->assertDontSee('مریخ');
    }

    // ── Plans page ───────────────────────────────────────────────────────────

    public function test_plans_page_loads_successfully(): void
    {
        $response = $this->get('/plans');
        $response->assertOk();
    }

    public function test_plans_page_shows_active_plans_only(): void
    {
        Plan::factory()->create(['name' => 'پلن فعال',    'is_active' => true,  'sort_order' => 1]);
        Plan::factory()->create(['name' => 'پلن غیرفعال', 'is_active' => false, 'sort_order' => 2]);

        $response = $this->get('/plans');

        $response->assertOk();
        $response->assertSee('پلن فعال');
        $response->assertDontSee('پلن غیرفعال');
    }

    public function test_plans_page_shows_empty_message_when_no_plans(): void
    {
        $response = $this->get('/plans');
        $response->assertOk();
        $response->assertSee('پلنی برای نمایش وجود ندارد');
    }

    // ── Models ───────────────────────────────────────────────────────────────

    public function test_active_plans_scope_filters_correctly(): void
    {
        Plan::factory()->create(['is_active' => true]);
        Plan::factory()->create(['is_active' => true]);
        Plan::factory()->create(['is_active' => false]);

        $this->assertCount(2, Plan::active()->get());
    }

    public function test_plan_traffic_label_returns_unlimited_when_null(): void
    {
        $plan = Plan::factory()->make(['traffic_gb' => null]);
        $this->assertEquals('نامحدود', $plan->trafficLabel());
    }

    public function test_plan_traffic_label_returns_gb_amount(): void
    {
        $plan = Plan::factory()->make(['traffic_gb' => 50]);
        $this->assertEquals('50 گیگابایت', $plan->trafficLabel());
    }

    public function test_plan_has_features_relation(): void
    {
        $plan    = Plan::factory()->create();
        $feature = Feature::factory()->create();
        $plan->features()->attach($feature->id);

        $this->assertCount(1, $plan->fresh()->features);
    }

    // ── SiteText helper ──────────────────────────────────────────────────────

    public function test_site_setting_returns_db_value(): void
    {
        SiteText::create([
            'key'   => 'test.greeting',
            'value' => 'سلام از دیتابیس',
        ]);

        $this->assertEquals('سلام از دیتابیس', site_setting('test.greeting', 'پیش‌فرض'));
    }

    public function test_site_setting_returns_default_when_key_missing(): void
    {
        $this->assertEquals('مقدار پیش‌فرض', site_setting('does.not.exist', 'مقدار پیش‌فرض'));
    }

    public function test_site_text_seeder_does_not_overwrite_admin_edited_value(): void
    {
        SiteText::create([
            'key'   => 'homepage.hero.title',
            'value' => 'عنوان ویرایش‌شده توسط ادمین',
        ]);

        // Run seeder again — should not overwrite
        (new \Database\Seeders\SiteTextSeeder())->run();

        $this->assertEquals(
            'عنوان ویرایش‌شده توسط ادمین',
            SiteText::where('key', 'homepage.hero.title')->value('value')
        );
    }

    public function test_feature_seeder_does_not_overwrite_admin_edited_title(): void
    {
        Feature::create([
            'title'      => 'ویژگی ویرایش‌شده',
            'slug'       => 'feature-1',
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        (new \Database\Seeders\FeatureSeeder())->run();

        $this->assertEquals(
            'ویژگی ویرایش‌شده',
            Feature::where('slug', 'feature-1')->value('title')
        );
    }

    public function test_location_seeder_does_not_overwrite_admin_edited_name(): void
    {
        Location::create([
            'country_name' => 'آلمان ویرایش‌شده',
            'country_code' => 'DE',
            'is_active'    => true,
        ]);

        (new \Database\Seeders\LocationSeeder())->run();

        $this->assertEquals(
            'آلمان ویرایش‌شده',
            Location::where('country_code', 'DE')->value('country_name')
        );
    }
}
