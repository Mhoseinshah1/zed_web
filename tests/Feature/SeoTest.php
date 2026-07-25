<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Page;
use App\Models\Plan;
use App\Models\SeoPage;
use App\Models\Tutorial;
use App\Models\User;
use App\Services\Seo\SeoManager;
use App\Services\Seo\SeoSettings;
use Database\Seeders\SeoPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new SeoPageSeeder)->run();
        SeoSettings::flush();
    }

    /** Extract every JSON-LD block from an HTML string, decoded. */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($j) => json_decode($j, true), $m[1]);
    }

    private function schemaTypes(string $html): array
    {
        return array_values(array_filter(array_map(fn ($s) => $s['@type'] ?? null, $this->jsonLd($html))));
    }

    // 1. Home title, description, canonical ───────────────────────────────────
    public function test_home_has_title_description_and_canonical(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]+/">#', $html);
    }

    // 2 + 3. Open Graph + Twitter tags ────────────────────────────────────────
    public function test_home_has_open_graph_and_twitter_tags(): void
    {
        $html = $this->get('/')->getContent();
        foreach (['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'og:locale'] as $t) {
            $this->assertStringContainsString('property="'.$t.'"', $html, "missing {$t}");
        }
        $this->assertStringContainsString('name="twitter:card"', $html);
    }

    // 4. No duplicate meta tags ───────────────────────────────────────────────
    public function test_no_duplicate_core_meta_tags(): void
    {
        $html = $this->get('/')->getContent();
        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertSame(1, substr_count($html, 'name="description"'));
        $this->assertSame(1, substr_count($html, 'property="og:title"'));
        $this->assertSame(1, substr_count($html, 'name="twitter:card"'));
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
    }

    // 5. CMS page fallbacks ───────────────────────────────────────────────────
    public function test_cms_page_falls_back_to_title_and_excerpt(): void
    {
        $page = Page::create([
            'slug' => 'about', 'title' => 'درباره ما زدپروکسی', 'excerpt' => 'ما یک سرویس هستیم',
            'content' => '<p>x</p>', 'is_active' => true,
        ]);
        $html = $this->get('/pages/about')->assertOk()->getContent();
        $this->assertStringContainsString('درباره ما زدپروکسی', $html);
        $this->assertStringContainsString('ما یک سرویس هستیم', $html);
    }

    // 6 + 7. Tutorial SEO + Article/TechArticle schema ────────────────────────
    public function test_tutorial_seo_and_article_schema(): void
    {
        $t = Tutorial::create([
            'title' => 'آموزش اتصال', 'slug' => 'connect', 'platform' => 'android',
            'short_description' => 'راهنمای اتصال', 'content' => '<p>steps</p>',
            'schema_type' => 'TechArticle', 'is_active' => true, 'published_at' => now(),
        ]);
        $html = $this->get('/tutorials/connect')->assertOk()->getContent();
        $this->assertStringContainsString('آموزش اتصال', $html);
        $this->assertContains('TechArticle', $this->schemaTypes($html));
        // A HowTo with no real steps must NOT be emitted as HowTo.
        $t->update(['schema_type' => 'HowTo']);
        $html = $this->get('/tutorials/connect')->getContent();
        $this->assertNotContains('HowTo', $this->schemaTypes($html));
    }

    // 8. FAQPage schema from active FAQs ──────────────────────────────────────
    public function test_faq_page_schema_from_active_faqs(): void
    {
        Faq::create(['question' => 'سوال یک؟', 'answer' => '<b>پاسخ</b> یک', 'is_active' => true, 'sort_order' => 1]);
        Faq::create(['question' => 'سوال مخفی؟', 'answer' => 'مخفی', 'is_active' => false, 'sort_order' => 2]);

        $html = $this->get('/faq')->assertOk()->getContent();
        $ld = collect($this->jsonLd($html))->firstWhere('@type', 'FAQPage');
        $this->assertNotNull($ld);
        $questions = collect($ld['mainEntity'])->pluck('name');
        $this->assertContains('سوال یک؟', $questions);
        $this->assertNotContains('سوال مخفی؟', $questions); // inactive excluded
        // HTML answer converted to plain text (no tags).
        $this->assertStringNotContainsString('<b>', json_encode($ld));
    }

    // 9. Plans ItemList schema ────────────────────────────────────────────────
    public function test_plans_item_list_schema_with_real_offers(): void
    {
        Plan::factory()->create(['name' => 'پلن طلایی', 'price_toman' => 50000, 'is_active' => true]);
        $html = $this->get('/plans')->assertOk()->getContent();
        $ld = collect($this->jsonLd($html))->firstWhere('@type', 'ItemList');
        $this->assertNotNull($ld);
        $first = $ld['itemListElement'][0]['item'];
        $this->assertSame('Product', $first['@type']);
        // Product URL: absolute /plans URL with the plan anchor.
        $this->assertStringStartsWith('http', $first['url']);
        $this->assertStringContainsString('/plans#plan-', $first['url']);
        // No default OG image configured in this fixture → image key omitted.
        $this->assertArrayNotHasKey('image', $first);
        $this->assertArrayHasKey('offers', $first);
        $this->assertSame('IRR', $first['offers']['priceCurrency']);
        $this->assertSame('500000', $first['offers']['price']); // 50000 Toman → Rial
        // Offer completeness: url mirrors the Product; priceValidUntil is a
        // real ISO-8601 date ~30 days out. Ratings/reviews stay absent.
        $this->assertSame($first['url'], $first['offers']['url']);
        $this->assertSame(now()->addDays(30)->toDateString(), $first['offers']['priceValidUntil']);
        $this->assertArrayNotHasKey('aggregateRating', $first);
        $this->assertArrayNotHasKey('review', $first);
    }

    public function test_plans_item_list_uses_default_og_image_when_configured(): void
    {
        SeoSettings::set('seo_default_og_image', 'https://cdn.example.com/og.png');
        Plan::factory()->create(['name' => 'پلن تصویر', 'price_toman' => 10000, 'is_active' => true]);
        $html = $this->get('/plans')->assertOk()->getContent();
        $ld = collect($this->jsonLd($html))->firstWhere('@type', 'ItemList');
        $this->assertSame('https://cdn.example.com/og.png', $ld['itemListElement'][0]['item']['image']);
    }

    // 10 + 11. Organization + WebSite schema on home ──────────────────────────
    public function test_home_has_organization_and_website_schema(): void
    {
        $types = $this->schemaTypes($this->get('/')->getContent());
        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertContains('WebPage', $types);
    }

    // 12. Valid JSON-LD everywhere ────────────────────────────────────────────
    public function test_all_json_ld_is_valid_json(): void
    {
        foreach (['/', '/faq', '/plans'] as $url) {
            $blocks = $this->jsonLd($this->get($url)->getContent());
            $this->assertNotEmpty($blocks, "no JSON-LD on {$url}");
            foreach ($blocks as $b) {
                $this->assertIsArray($b, "invalid JSON-LD on {$url}");
                $this->assertSame('https://schema.org', $b['@context']);
            }
        }
    }

    // 13. Valid sitemap XML ───────────────────────────────────────────────────
    public function test_sitemaps_are_valid_xml(): void
    {
        foreach (['/sitemap.xml', '/sitemap-pages.xml', '/sitemap-tutorials.xml'] as $url) {
            $res = $this->get($url)->assertOk();
            $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
            $xml = simplexml_load_string($res->getContent());
            $this->assertNotFalse($xml, "invalid XML at {$url}");
        }
    }

    // 14 + 27. noindex + disabled pages excluded from sitemap ──────────────────
    public function test_sitemap_excludes_noindex_and_disabled_pages(): void
    {
        Page::create(['slug' => 'secret', 'title' => 'Secret', 'content' => 'x', 'is_active' => true, 'robots_index' => false, 'include_in_sitemap' => true]);
        Page::create(['slug' => 'draft', 'title' => 'Draft', 'content' => 'x', 'is_active' => false]);
        Page::create(['slug' => 'visible-page', 'title' => 'Visible', 'content' => 'x', 'is_active' => true]);
        Cache::forget('seo_sitemap:pages');

        $xml = $this->get('/sitemap-pages.xml')->getContent();
        $this->assertStringContainsString('/pages/visible-page', $xml);
        $this->assertStringNotContainsString('/pages/secret', $xml);   // noindex
        $this->assertStringNotContainsString('/pages/draft', $xml);    // inactive
        $this->assertStringNotContainsString('/login', $xml);          // auth page
    }

    // 15. Production robots.txt ────────────────────────────────────────────────
    public function test_production_robots_txt(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $body = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Disallow: /dashboard/', $body);
        $this->assertStringContainsString('Disallow: /zed-admin/', $body);
        $this->assertStringContainsString('Disallow: /payments/', $body);
        $this->assertStringContainsString('Sitemap: ', $body);
        $this->assertStringNotContainsString("Disallow: /\n", $body); // not a blanket block
    }

    // 16. Local/testing robots.txt blocks everything ──────────────────────────
    public function test_non_production_robots_txt_blocks_all(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /', $body);
        $this->assertStringNotContainsString('Sitemap:', $body);
    }

    public function test_admin_custom_robots_cannot_reopen_protected(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        SeoSettings::set('seo_custom_robots', "Allow: /zed-admin/\nDisallow: /private/");
        $body = $this->get('/robots.txt')->getContent();
        $this->assertStringNotContainsString('Allow: /zed-admin/', $body); // stripped
        $this->assertStringContainsString('Disallow: /private/', $body);   // kept
        $this->assertStringContainsString('Disallow: /zed-admin/', $body); // forced remains
    }

    // 17-21. X-Robots-Tag noindex on private/sensitive routes ─────────────────
    public function test_private_routes_send_noindex_header(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->get('/dashboard')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $this->get('/health')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/zed-admin/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_payment_callback_sends_noindex_header(): void
    {
        // The route carries the noindex middleware regardless of the outcome.
        $res = $this->get('/payments/centralpay/callback');
        $this->assertSame('noindex, nofollow, noarchive', $res->headers->get('X-Robots-Tag'));
    }

    // 22. Canonical strips UTM ─────────────────────────────────────────────────
    public function test_canonical_has_no_utm_parameters(): void
    {
        $html = $this->get('/plans?utm_source=google&utm_medium=cpc&gclid=abc')->getContent();
        preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $m);
        $this->assertStringNotContainsString('utm_', $m[1]);
        $this->assertStringNotContainsString('gclid', $m[1]);
    }

    // 23. Plans category filter → single canonical (no duplicates) ────────────
    public function test_plans_filter_canonical_ignores_category(): void
    {
        $html = $this->get('/plans?category=vpn')->getContent();
        preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $m);
        $this->assertStringEndsWith('/plans', $m[1]);
        $this->assertStringNotContainsString('category', $m[1]);
    }

    // 24. Absolute OG image URLs ──────────────────────────────────────────────
    public function test_og_image_is_absolute(): void
    {
        SeoSettings::set('seo_default_og_image', 'seo/default.png');
        $html = $this->get('/')->getContent();
        if (preg_match('#<meta property="og:image" content="([^"]+)">#', $html, $m)) {
            $this->assertMatchesRegularExpression('#^https?://#', $m[1]);
        } else {
            $this->fail('og:image not rendered');
        }
    }

    // 25. Meta values are escaped ─────────────────────────────────────────────
    public function test_meta_values_are_escaped(): void
    {
        SeoPage::where('page_key', 'home')->update([
            'meta_description' => 'خطر "<script>alert(1)</script>" & test',
        ]);
        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // 26. Invalid schema_json_override is rejected ────────────────────────────
    public function test_invalid_schema_override_is_rejected(): void
    {
        $m = app(SeoManager::class);
        $this->assertNull($m->validSchemaOverride('{not valid json'));
        $this->assertNull($m->validSchemaOverride(''));
        $this->assertIsArray($m->validSchemaOverride('{"@type":"WebPage"}'));

        // A page with an invalid override still renders valid JSON-LD.
        SeoPage::where('page_key', 'home')->update(['schema_json_override' => '<<<bad']);
        foreach ($this->jsonLd($this->get('/')->getContent()) as $b) {
            $this->assertIsArray($b);
        }
    }

    // 28. SEO sitemap cache invalidation ──────────────────────────────────────
    public function test_sitemap_cache_invalidates_on_page_change(): void
    {
        $this->get('/sitemap-pages.xml'); // warm cache
        $this->assertNotNull(Cache::get('seo_sitemap:pages'));

        Page::create(['slug' => 'fresh-page', 'title' => 'Fresh', 'content' => 'x', 'is_active' => true]);
        $this->assertNull(Cache::get('seo_sitemap:pages')); // busted by model event

        $xml = $this->get('/sitemap-pages.xml')->getContent();
        $this->assertStringContainsString('/pages/fresh-page', $xml);
    }

    // 29 + 30. Templates render + RTL preserved ───────────────────────────────
    public function test_public_pages_render_and_keep_rtl(): void
    {
        foreach (['/', '/plans', '/faq', '/tutorials', '/contact', '/status'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('dir="rtl"', $html, "RTL lost on {$url}");
            $this->assertStringContainsString('lang="fa"', $html);
        }
    }

    // 31. Acceptable query count on the homepage ──────────────────────────────
    public function test_home_query_count_is_reasonable(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        // The content-rich homepage (plans, features, locations, FAQs, sections,
        // banners, theming) dominates; the SEO layer adds only ~2 queries
        // (cached settings blob + one SeoPage lookup). This guards against N+1
        // regressions in the SEO/structured-data path.
        $this->assertLessThan(75, $count, "home issued {$count} queries");
    }

    // Login/register default noindex in the rendered head ─────────────────────
    public function test_auth_pages_default_noindex_in_head(): void
    {
        $html = $this->get('/login')->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    }

    // Forced-noindex path helper ──────────────────────────────────────────────
    public function test_forced_noindex_path_detection(): void
    {
        $this->assertTrue(SeoManager::isForcedNoindexPath('dashboard/orders'));
        $this->assertTrue(SeoManager::isForcedNoindexPath('zed-admin'));
        $this->assertTrue(SeoManager::isForcedNoindexPath('payments/centralpay/callback'));
        $this->assertFalse(SeoManager::isForcedNoindexPath('plans'));
        $this->assertFalse(SeoManager::isForcedNoindexPath(''));
    }

    // ── Delivery-layer fixes ──────────────────────────────────────────────────

    public function test_no_static_robots_txt_file_exists(): void
    {
        // A static file would shadow the dynamic RobotsController via nginx
        // try_files — it must never exist in the repo/public dir again.
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }

    public function test_pretty_page_aliases_redirect_permanently(): void
    {
        foreach (['/terms', '/privacy', '/about'] as $alias) {
            $response = $this->get($alias);
            $response->assertStatus(301);
            $this->assertStringContainsString('/pages/', $response->headers->get('Location'));
        }
    }

    public function test_ga_snippet_absent_when_id_empty(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $html);
    }

    public function test_ga_snippet_rendered_once_for_valid_id_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        SeoSettings::set('seo_google_analytics_id', 'G-AB12CD34EF');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'googletagmanager.com/gtag/js?id=G-AB12CD34EF'));
        $this->assertSame(1, substr_count($html, "gtag('config', 'G-AB12CD34EF')"));
    }

    public function test_ga_snippet_absent_for_invalid_id(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        SeoSettings::set('seo_google_analytics_id', 'UA-1234-5"><script>alert(1)</script>');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_ga_snippet_absent_outside_production(): void
    {
        SeoSettings::set('seo_google_analytics_id', 'G-AB12CD34EF');
        $html = $this->get('/')->assertOk()->getContent();   // testing env
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $html);
    }

    public function test_ga_snippet_absent_on_noindex_routes(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        SeoSettings::set('seo_google_analytics_id', 'G-AB12CD34EF');
        $html = $this->get('/login')->assertOk()->getContent();   // noindex middleware
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $html);
    }

    public function test_layout_uses_self_hosted_fonts(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        $this->assertStringNotContainsString("body { font-family: 'Vazirmatn'", $html);
    }
}
