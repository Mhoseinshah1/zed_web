<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Plan;
use App\Models\PlanCategory;
use App\Models\SiteText;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Homepage / dynamic content ───────────────────────────────────────────

    public function test_homepage_loads_with_database_content(): void
    {
        SiteText::set('hero_title', 'سرتیتر آزمایشی هیرو');
        SiteText::set('hero_is_active', '1');
        $this->get(route('home'))->assertSuccessful()->assertSee('سرتیتر آزمایشی هیرو');
    }

    public function test_homepage_does_not_crash_with_no_content(): void
    {
        Faq::query()->delete();
        Banner::query()->delete();
        $this->get(route('home'))->assertSuccessful();
    }

    public function test_inactive_hero_is_hidden(): void
    {
        SiteText::set('hero_title', 'هیرو-مخفی-۱۲۳');
        SiteText::set('hero_is_active', '0');
        $this->get(route('home'))->assertSuccessful()->assertDontSee('هیرو-مخفی-۱۲۳');
    }

    public function test_active_faq_renders_and_inactive_hidden(): void
    {
        Faq::query()->delete();
        Faq::create(['question' => 'سوال-فعال-۱', 'answer' => 'پاسخ', 'is_active' => true, 'sort_order' => 1]);
        Faq::create(['question' => 'سوال-مخفی-۲', 'answer' => 'پاسخ', 'is_active' => false, 'sort_order' => 2]);

        $this->get(route('faq'))->assertSuccessful()
            ->assertSee('سوال-فعال-۱')->assertDontSee('سوال-مخفی-۲');
    }

    // ── Banners ──────────────────────────────────────────────────────────────

    public function test_banner_respects_placement_and_active(): void
    {
        Banner::create(['title' => 'بنر-فعال-فروشگاه', 'placement' => 'shop_top', 'is_active' => true]);
        Banner::create(['title' => 'بنر-غیرفعال', 'placement' => 'shop_top', 'is_active' => false]);

        $this->get(route('plans'))->assertSuccessful()
            ->assertSee('بنر-فعال-فروشگاه')->assertDontSee('بنر-غیرفعال');
    }

    public function test_banner_schedule_window_is_respected(): void
    {
        Banner::create(['title' => 'بنر-آینده', 'placement' => 'home_top', 'is_active' => true, 'starts_at' => now()->addDay()]);
        Banner::create(['title' => 'بنر-منقضی', 'placement' => 'home_top', 'is_active' => true, 'ends_at' => now()->subDay()]);
        Banner::create(['title' => 'بنر-جاری', 'placement' => 'home_top', 'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $res = $this->get(route('home'))->assertSuccessful();
        $res->assertSee('بنر-جاری')->assertDontSee('بنر-آینده')->assertDontSee('بنر-منقضی');
    }

    // ── Static pages ─────────────────────────────────────────────────────────

    public function test_static_page_loads_by_slug(): void
    {
        Page::create(['slug' => 'test-page', 'title' => 'صفحه آزمایشی', 'content' => '<p>محتوا</p>', 'is_active' => true]);
        $this->get('/pages/test-page')->assertSuccessful()->assertSee('صفحه آزمایشی');
    }

    public function test_inactive_page_returns_404(): void
    {
        Page::create(['slug' => 'hidden-page', 'title' => 'مخفی', 'content' => 'x', 'is_active' => false]);
        $this->get('/pages/hidden-page')->assertNotFound();
    }

    public function test_pretty_alias_redirects_to_page(): void
    {
        $this->get('/terms')->assertRedirect(route('pages.show', 'terms'));
    }

    public function test_footer_shows_footer_pages(): void
    {
        Page::create(['slug' => 'footer-page', 'title' => 'صفحه-فوتر-۹۹', 'content' => 'x', 'is_active' => true, 'show_in_footer' => true]);
        $this->get(route('home'))->assertSuccessful()->assertSee('صفحه-فوتر-۹۹');
    }

    // ── Tutorials ────────────────────────────────────────────────────────────

    public function test_tutorials_list_and_detail_load(): void
    {
        $t = Tutorial::create(['title' => 'آموزش-آزمایشی', 'slug' => 'test-tut', 'platform' => 'android', 'content' => '<p>x</p>', 'is_active' => true]);
        $this->get(route('tutorials'))->assertSuccessful()->assertSee('آموزش-آزمایشی');
        $this->get(route('tutorials.show', $t->slug))->assertSuccessful()->assertSee('آموزش-آزمایشی');
    }

    public function test_inactive_tutorial_detail_404(): void
    {
        $t = Tutorial::create(['title' => 'مخفی', 'slug' => 'hidden-tut', 'platform' => 'ios', 'content' => 'x', 'is_active' => false]);
        $this->get(route('tutorials.show', $t->slug))->assertNotFound();
    }

    // ── Shop / categories ────────────────────────────────────────────────────

    public function test_shop_settings_render_without_breaking_plans(): void
    {
        SiteText::set('shop_page_title', 'عنوان-فروشگاه-سفارشی');
        SiteText::set('payment_help_text', 'راهنمای-پرداخت-سفارشی');
        $cat = PlanCategory::create(['title' => 'دسته-تست', 'slug' => 'cat-test', 'is_active' => true]);
        Plan::create([
            'name' => 'پلن تست', 'slug' => 'plan-test', 'category_id' => $cat->id,
            'traffic_gb' => 50, 'duration_days' => 30, 'price_toman' => 100000, 'is_active' => true,
        ]);

        $this->get(route('plans'))->assertSuccessful()
            ->assertSee('عنوان-فروشگاه-سفارشی')
            ->assertSee('دسته-تست')
            ->assertSee('راهنمای-پرداخت-سفارشی');
    }

    public function test_plan_category_relationship(): void
    {
        $cat = PlanCategory::create(['title' => 'گیمینگ', 'slug' => 'gaming', 'is_active' => true]);
        $plan = Plan::create([
            'name' => 'پلن گیم', 'slug' => 'game-plan', 'category_id' => $cat->id,
            'traffic_gb' => 100, 'duration_days' => 30, 'price_toman' => 200000, 'is_active' => true,
        ]);
        $this->assertSame($cat->id, $plan->category->id);
        $this->assertTrue($cat->plans->contains($plan));
    }

    // ── Snippets / settings ──────────────────────────────────────────────────

    public function test_snippet_fallback_when_missing(): void
    {
        $this->assertSame('پیش‌فرض', site_setting('a_missing_snippet_key', 'پیش‌فرض'));
        SiteText::set('a_missing_snippet_key', 'مقدار ذخیره‌شده');
        $this->assertSame('مقدار ذخیره‌شده', site_setting('a_missing_snippet_key', 'پیش‌فرض'));
    }

    public function test_seed_default_does_not_overwrite_admin_edits(): void
    {
        SiteText::set('hero_title', 'ویرایش-مدیر');
        SiteText::seedDefault('hero_title', 'مقدار-پیش‌فرض');
        $this->assertSame('ویرایش-مدیر', SiteText::get('hero_title'));
    }

    // ── Admin content resources / pages load ─────────────────────────────────

    public function test_admin_content_resources_load(): void
    {
        $admin = $this->admin();
        foreach ([
            '/zed-admin/banners', '/zed-admin/faqs', '/zed-admin/pages',
            '/zed-admin/tutorials', '/zed-admin/landing-sections', '/zed-admin/plan-categories',
            '/zed-admin/content/site-settings', '/zed-admin/content/home',
            '/zed-admin/content/social-links', '/zed-admin/content/shop-settings',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertSuccessful();
        }
    }

    public function test_seo_meta_renders_on_page(): void
    {
        Page::create([
            'slug' => 'seo-page', 'title' => 'صفحه سئو', 'content' => 'x', 'is_active' => true,
            'meta_description' => 'توضیحات-سئو-آزمایشی', 'meta_keywords' => 'کلمه-کلیدی-تست',
        ]);
        $this->get('/pages/seo-page')->assertSuccessful()
            ->assertSee('توضیحات-سئو-آزمایشی', false)
            ->assertSee('کلمه-کلیدی-تست', false);
    }
}
