<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\Seo\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan Product schema on /plans: stable fragment URLs (/plans#plan-{slug}),
 * honest fields only (no fabricated images or dates), exact Toman→Rial
 * conversion, and injection-safe JSON-LD.
 */
class PlanSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'name' => 'پلن طلایی',
            'slug' => 'gold-plan',
            'price_toman' => 150000,
            'is_active' => true,
        ], $attrs));
    }

    /** All JSON-LD nodes from an HTML string, decoded. */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($j) => json_decode($j, true), $m[1]);
    }

    private function itemList(string $html): ?array
    {
        foreach ($this->jsonLd($html) as $node) {
            if (($node['@type'] ?? '') === 'ItemList') {
                return $node;
            }
        }

        return null;
    }

    public function test_each_plan_card_has_a_unique_stable_id_on_plans_only(): void
    {
        $this->makePlan();
        $this->makePlan(['name' => 'پلن نقره‌ای', 'slug' => 'silver-plan', 'is_featured' => false]);

        $plans = $this->get('/plans')->assertOk()->getContent();
        $this->assertSame(1, substr_count($plans, 'id="plan-gold-plan"'));
        $this->assertSame(1, substr_count($plans, 'id="plan-silver-plan"'));

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="plan-', $home, 'homepage must not duplicate the fragment anchors');
    }

    public function test_product_and_offer_urls_point_at_the_absolute_card_fragment(): void
    {
        $this->makePlan();

        $list = $this->itemList($this->get('/plans')->getContent());
        $this->assertNotNull($list);
        $product = $list['itemListElement'][0]['item'];

        $this->assertSame('Product', $product['@type']);
        $this->assertStringEndsWith('/plans#plan-gold-plan', $product['url']);
        $this->assertStringStartsWith('http', $product['url']);
        $this->assertSame($product['url'], $product['offers']['url']);
        $this->assertSame('gold-plan', $product['sku']);
        $this->assertSame(1, $list['itemListElement'][0]['position']);
    }

    public function test_toman_to_rial_conversion_is_exactly_ten_x(): void
    {
        $this->makePlan(['price_toman' => 123457]);

        $product = $this->itemList($this->get('/plans')->getContent())['itemListElement'][0]['item'];

        $this->assertSame('1234570', $product['offers']['price']);
        $this->assertSame('IRR', $product['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/NewCondition', $product['offers']['itemCondition']);
    }

    public function test_product_image_uses_the_configured_default_og_image_and_is_omitted_when_empty(): void
    {
        $this->makePlan();

        SeoSettings::set('seo_default_og_image', 'https://cdn.example.com/og.png');
        $product = $this->itemList($this->get('/plans')->getContent())['itemListElement'][0]['item'];
        $this->assertSame('https://cdn.example.com/og.png', $product['image']);

        SeoSettings::set('seo_default_og_image', '');
        $product = $this->itemList($this->get('/plans')->getContent())['itemListElement'][0]['item'];
        $this->assertArrayNotHasKey('image', $product, 'image must be OMITTED, never an empty string');
    }

    public function test_price_valid_until_renders_only_when_configured_and_valid(): void
    {
        $this->makePlan();

        // Absent by default.
        $product = $this->itemList($this->get('/plans')->getContent())['itemListElement'][0]['item'];
        $this->assertArrayNotHasKey('priceValidUntil', $product['offers']);

        // Valid future date renders.
        $future = now()->addMonths(3)->format('Y-m-d');
        SeoSettings::set('seo_offer_price_valid_until', $future);
        $product = $this->itemList($this->get('/plans')->getContent())['itemListElement'][0]['item'];
        $this->assertSame($future, $product['offers']['priceValidUntil']);

        // Expired, malformed, and impossible dates are all omitted.
        foreach (['2020-01-01', 'next year', '2026-13-45', '31-12-2026'] as $bad) {
            SeoSettings::set('seo_offer_price_valid_until', $bad);
            $product = $this->itemList($this->get('/plans')->getContent())['itemListElement'][0]['item'];
            $this->assertArrayNotHasKey('priceValidUntil', $product['offers'], "'{$bad}' must be omitted");
        }
    }

    public function test_json_ld_stays_valid_when_plan_text_contains_injection_payloads(): void
    {
        $this->makePlan([
            'name' => '</script><script>alert(1)</script>',
            'slug' => 'evil-plan',
            'description' => '"</script>&{}',
        ]);

        $html = $this->get('/plans')->assertOk()->getContent();
        $nodes = $this->jsonLd($html);
        $this->assertNotEmpty($nodes);
        foreach ($nodes as $node) {
            $this->assertIsArray($node, 'every JSON-LD block must stay parseable');
        }
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_no_plan_detail_route_is_introduced(): void
    {
        $this->assertFalse(Route::has('plans.show'));
        $this->makePlan(['slug' => 'some-plan']);
        $this->get('/plans/some-plan')->assertNotFound();
    }
}
