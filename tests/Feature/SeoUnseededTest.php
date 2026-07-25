<?php

namespace Tests\Feature;

use App\Http\Controllers\FaqController;
use App\Models\SeoPage;
use App\Services\Seo\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression suite for the seed-state bug: noindex behavior (and therefore GA
 * suppression) must NOT depend on SeoPage database records. These tests
 * deliberately never run SeoPageSeeder — production servers installed without
 * seeding are exactly this state, and SeoTest::setUp() seeding is what hid the
 * original bug where a configured GA4 script loaded on /login and /register.
 */
class SeoUnseededTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deliberately NO SeoPageSeeder here.
        SeoSettings::flush();
    }

    public function test_seo_pages_table_is_actually_empty_in_this_suite(): void
    {
        $this->assertSame(0, SeoPage::count());
    }

    public function test_login_and_register_are_noindex_and_ga_free_on_an_unseeded_database(): void
    {
        SeoSettings::set('seo_google_analytics_id', 'G-AB12CD34EF');

        foreach (['/login', '/register'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            // Header behavior preserved…
            $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

            $html = $response->getContent();
            // …and the verdict now reaches the meta tag BEFORE render…
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, "{$path} must render meta noindex without SeoPage records");
            // …so Analytics never loads on an auth page.
            $this->assertStringNotContainsString('googletagmanager.com', $html, "{$path} must not load GA");
            $this->assertStringNotContainsString('gtag(', $html, "{$path} must not contain a gtag call");
        }
    }

    public function test_ordinary_public_page_still_renders_ga_when_unseeded(): void
    {
        SeoSettings::set('seo_google_analytics_id', 'G-AB12CD34EF');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('gtag/js?id=G-AB12CD34EF', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
    }

    public function test_every_route_behind_the_noindex_middleware_suppresses_ga(): void
    {
        // Not a login/register special case: ANY route using the `noindex`
        // middleware with the public SEO layout must get the same behavior.
        Route::middleware(['web', 'noindex'])->get('/__noindex-probe', [FaqController::class, 'index']);

        SeoSettings::set('seo_google_analytics_id', 'G-AB12CD34EF');

        $response = $this->get('/__noindex-probe');
        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $html = $response->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('gtag(', $html);
    }

    public function test_malformed_or_malicious_ga_id_still_renders_nothing_when_unseeded(): void
    {
        foreach (['not-a-ga-id', '"><script>alert(1)</script>'] as $bad) {
            SeoSettings::set('seo_google_analytics_id', $bad);

            $html = $this->get('/')->assertOk()->getContent();

            $this->assertStringNotContainsString('googletagmanager.com', $html);
            $this->assertStringNotContainsString('alert(1)', $html);
        }
    }
}
