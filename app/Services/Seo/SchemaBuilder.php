<?php

namespace App\Services\Seo;

use App\Models\Faq;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Tutorial;
use Illuminate\Support\Str;

/**
 * Builds JSON-LD structured-data nodes from REAL database content only. Never
 * fabricates steps, ratings, reviews or authors. Each public builder returns a
 * plain array (a schema.org node) or null when the required real data is
 * absent, so callers can simply filter out the nulls.
 */
class SchemaBuilder
{
    public function __construct(private readonly SeoManager $seo) {}

    /** Organization node (used on the homepage + as publisher). */
    public function organization(): ?array
    {
        $name = SeoSettings::get('seo_org_name', SeoSettings::siteName());
        if ($name === '') {
            return null;
        }

        $node = [
            '@type' => 'Organization',
            '@id'   => $this->seo->baseUrl() . '/#organization',
            'name'  => $name,
            'url'   => $this->seo->baseUrl() . '/',
        ];

        if ($alt = SeoSettings::get('seo_org_alternate_name')) {
            $node['alternateName'] = $alt;
        }
        if ($logo = $this->seo->absoluteUrl(SeoSettings::get('seo_schema_logo') ?: SeoSettings::defaultOgImage())) {
            $node['logo'] = $logo;
        }

        $sameAs = array_values(array_filter([
            SeoSettings::get('seo_facebook_url'),
            SeoSettings::get('seo_telegram_url'),
            $this->twitterUrl(),
        ]));
        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        if ($cp = $this->contactPoint()) {
            $node['contactPoint'] = [$cp];
        }

        return $node;
    }

    /** WebSite node. SearchAction is intentionally omitted — no real search. */
    public function website(): ?array
    {
        return [
            '@type'    => 'WebSite',
            '@id'      => $this->seo->baseUrl() . '/#website',
            'url'      => $this->seo->baseUrl() . '/',
            'name'     => SeoSettings::siteName(),
            'inLanguage' => SeoSettings::locale(),
            'publisher' => ['@id' => $this->seo->baseUrl() . '/#organization'],
        ];
    }

    /** Generic WebPage node for the current URL. */
    public function webPage(string $type = 'WebPage'): array
    {
        $d = $this->seo->resolve();
        $node = [
            '@type'      => $type,
            'url'        => $d->canonical ?: ($this->seo->baseUrl() . '/'),
            'name'       => $d->title,
            'inLanguage' => SeoSettings::locale(),
            'isPartOf'   => ['@id' => $this->seo->baseUrl() . '/#website'],
        ];
        if ($d->description !== '') {
            $node['description'] = $d->description;
        }
        return $node;
    }

    /** ContactPage + embedded ContactPoint, only when real contact data exists. */
    public function contactPage(): ?array
    {
        $cp = $this->contactPoint();
        if ($cp === null) {
            return null;
        }
        return [
            '@type' => 'ContactPage',
            'url'   => $this->seo->resolve()->canonical,
            'name'  => $this->seo->resolve()->title,
            'mainEntity' => array_merge(['@id' => $this->seo->baseUrl() . '/#organization'], ['contactPoint' => $cp]),
        ];
    }

    /** ContactPoint from real telephone/email — null when neither is set. */
    public function contactPoint(): ?array
    {
        $phone = SeoSettings::get('seo_telephone');
        $email = SeoSettings::get('seo_email');
        if ($phone === '' && $email === '') {
            return null;
        }
        $node = ['@type' => 'ContactPoint', 'contactType' => 'customer support'];
        if ($phone !== '') {
            $node['telephone'] = $phone;
        }
        if ($email !== '') {
            $node['email'] = $email;
        }
        return $node;
    }

    /**
     * FAQPage from the ACTIVE FAQs actually rendered on the page. HTML answers
     * are converted to safe plain text. Returns null when there are no FAQs.
     *
     * @param  \Illuminate\Support\Collection<int,Faq>|null  $faqs
     */
    public function faqPage($faqs = null): ?array
    {
        $faqs = $faqs ?? Faq::active()->ordered()->get();
        if ($faqs->isEmpty()) {
            return null;
        }

        $items = [];
        foreach ($faqs as $faq) {
            $q = trim((string) $faq->question);
            $a = $this->htmlToText((string) $faq->answer);
            if ($q === '' || $a === '') {
                continue;
            }
            $items[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        if ($items === []) {
            return null;
        }

        return ['@type' => 'FAQPage', 'mainEntity' => $items];
    }

    /**
     * ItemList of ACTIVE plans. Each item is a Product with an Offer ONLY when a
     * real price exists. No ratings/reviews are ever generated.
     *
     * @param  \Illuminate\Support\Collection<int,Plan>|null  $plans
     */
    public function plansItemList($plans = null): ?array
    {
        $plans = $plans ?? Plan::active()->ordered()->get();
        if ($plans->isEmpty()) {
            return null;
        }

        $elements = [];
        $pos = 1;
        foreach ($plans as $plan) {
            $product = [
                '@type' => 'Product',
                'name'  => (string) $plan->name,
            ];
            if (filled($plan->description ?? null)) {
                $product['description'] = $this->htmlToText((string) $plan->description);
            }
            // Offer only with real price data (Toman → IRR currency).
            if (($plan->price_toman ?? 0) > 0) {
                $product['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => (string) ((int) $plan->price_toman * 10), // Toman → Rial
                    'priceCurrency' => 'IRR',
                    'availability'  => 'https://schema.org/InStock',
                ];
            }
            $elements[] = ['@type' => 'ListItem', 'position' => $pos++, 'item' => $product];
        }

        return [
            '@type'           => 'ItemList',
            'itemListElement' => $elements,
            'numberOfItems'   => count($elements),
        ];
    }

    /**
     * Article / TechArticle / HowTo for a tutorial. HowTo is only used when the
     * model declares it AND real steps exist; otherwise the requested article
     * type (default Article). Never fabricates author/steps/ratings.
     */
    public function tutorialArticle(Tutorial $t): array
    {
        $type = in_array($t->schema_type ?? '', ['Article', 'TechArticle', 'HowTo'], true)
            ? $t->schema_type : 'Article';

        // HowTo requires real structured steps; we do not fabricate them, so a
        // HowTo request with no steps degrades to TechArticle.
        if ($type === 'HowTo') {
            $type = 'TechArticle';
        }

        $d = $this->seo->resolve();
        $node = [
            '@type'      => $type,
            'headline'   => $d->title,
            'url'        => $d->canonical,
            'inLanguage' => SeoSettings::locale(),
        ];
        if ($d->description !== '') {
            $node['description'] = $d->description;
        }
        if ($img = $d->ogImage) {
            $node['image'] = $img;
        }

        $published = $t->published_at ?? $t->created_at;
        if ($published) {
            $node['datePublished'] = $published->toIso8601String();
        }
        if ($t->updated_at) {
            $node['dateModified'] = $t->updated_at->toIso8601String();
        }
        // Author only when a real name is stored — never invented.
        if (filled($t->author_name)) {
            $node['author'] = ['@type' => 'Person', 'name' => $t->author_name];
        }
        $node['publisher'] = ['@id' => $this->seo->baseUrl() . '/#organization'];

        return $node;
    }

    /** CMS page WebPage/Article node (schema_type override respected). */
    public function cmsPage(Page $page): array
    {
        $type = filled($page->schema_type) ? $page->schema_type : 'WebPage';
        return $this->webPage($type);
    }

    /** BreadcrumbList matching the visible breadcrumb trail. */
    public function breadcrumbList(array $items): ?array
    {
        if ($items === []) {
            return null;
        }
        $elements = [];
        $pos = 1;
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $el = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $name];
            if (filled($item['url'] ?? null)) {
                $el['item'] = $this->seo->absoluteUrl($item['url']);
            }
            $elements[] = $el;
        }
        if ($elements === []) {
            return null;
        }
        return ['@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function twitterUrl(): string
    {
        $u = ltrim(SeoSettings::twitterUsername(), '@');
        return $u !== '' ? "https://twitter.com/{$u}" : '';
    }

    /** Convert stored HTML (FAQ/plan descriptions) to safe single-line text. */
    private function htmlToText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim(Str::limit($text, 1000, ''));
    }
}
