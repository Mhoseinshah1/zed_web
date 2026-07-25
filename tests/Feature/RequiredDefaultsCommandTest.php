<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\SeoPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * zedproxy:seed-required-defaults — the single entry point install.sh and the
 * atomic deployer use to guarantee the records the app REQUIRES: the
 * terms/privacy/about CMS pages (301 alias destinations) and the SEO page
 * registry (login/register noindex). It must be idempotent, complete partial
 * states, and never overwrite administrator edits.
 */
class RequiredDefaultsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_receives_active_default_pages_and_seo_records(): void
    {
        $this->assertSame(0, Page::count());
        $this->assertSame(0, SeoPage::count());

        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);

        foreach (['terms', 'privacy', 'about'] as $slug) {
            $page = Page::where('slug', $slug)->first();
            $this->assertNotNull($page, "page {$slug} must exist");
            $this->assertTrue((bool) $page->is_active, "page {$slug} must be active");
        }
        foreach (['login', 'register'] as $key) {
            $record = SeoPage::where('page_key', $key)->first();
            $this->assertNotNull($record, "seo record {$key} must exist");
            $this->assertFalse((bool) $record->robots_index, "seo record {$key} must be noindex");
        }
    }

    public function test_aliases_301_to_destinations_that_respond_200_after_seeding(): void
    {
        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);

        foreach (['terms', 'privacy', 'about'] as $slug) {
            $redirect = $this->get('/'.$slug);
            $redirect->assertStatus(301);
            $this->assertStringContainsString('/pages/'.$slug, $redirect->headers->get('Location'));
            // The exact production-bug scenario: the 301 destination must NOT 404.
            $this->get('/pages/'.$slug)->assertStatus(200);
        }
    }

    public function test_customized_records_are_never_overwritten(): void
    {
        Page::create([
            'slug' => 'terms', 'title' => 'قوانین سفارشی ادمین',
            'content' => '<p>متن ویرایش‌شده</p>', 'is_active' => true,
        ]);
        SeoPage::create([
            'page_key' => 'login', 'label' => 'ورود سفارشی',
            'robots_index' => false, 'robots_follow' => false,
            'meta_title' => 'عنوان سفارشی ادمین',
        ]);

        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);

        $this->assertSame('قوانین سفارشی ادمین', Page::where('slug', 'terms')->value('title'));
        $this->assertSame('عنوان سفارشی ادمین', SeoPage::where('page_key', 'login')->value('meta_title'));
        $this->assertSame(1, Page::where('slug', 'terms')->count());
        $this->assertSame(1, SeoPage::where('page_key', 'login')->count());
    }

    public function test_partially_missing_record_set_is_completed(): void
    {
        Page::create(['slug' => 'terms', 'title' => 'قوانین', 'content' => 'x', 'is_active' => true]);
        SeoPage::create(['page_key' => 'home', 'label' => 'خانه']);

        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);

        $this->assertNotNull(Page::where('slug', 'privacy')->first());
        $this->assertNotNull(Page::where('slug', 'about')->first());
        $this->assertNotNull(SeoPage::where('page_key', 'login')->first());
        $this->assertNotNull(SeoPage::where('page_key', 'register')->first());
    }

    public function test_repeated_execution_is_data_idempotent(): void
    {
        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);
        $pages = Page::orderBy('id')->get()->toArray();
        $seo = SeoPage::orderBy('id')->get()->toArray();

        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);

        $this->assertSame($pages, Page::orderBy('id')->get()->toArray());
        $this->assertSame($seo, SeoPage::orderBy('id')->get()->toArray());
    }

    public function test_command_seeds_nothing_beyond_the_two_required_seeders(): void
    {
        $this->artisan('zedproxy:seed-required-defaults')->assertExitCode(0);

        // No demo/business data may appear — the command must never run the
        // full DatabaseSeeder.
        $this->assertSame(0, Plan::count());
        $this->assertSame(0, PaymentMethod::count());
        $this->assertSame(0, User::count());
    }
}
