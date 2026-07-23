<?php

namespace App\Services\Seo;

use App\Models\Page;
use App\Models\SeoPage;
use App\Models\Tutorial;
use App\Support\Seo\SeoData;
use Illuminate\Database\Eloquent\Model;

/**
 * The single source of truth for on-page SEO. Registered as a request-scoped
 * singleton: controllers enrich it (page key, model, overrides, breadcrumbs)
 * and the <x-seo-head> component renders the resolved SeoData exactly once.
 *
 * Resolution order for every field: explicit override → SeoPage record →
 * page model (Page/Tutorial) → global defaults. Canonical URLs are cleaned
 * (tracking params stripped, trailing slash normalised, absolute + prod-HTTPS),
 * private/auth paths are forced to noindex, and all image URLs are absolute.
 */
class SeoManager
{
    /** Path prefixes that must ALWAYS be noindex and can never be made indexable. */
    public const FORCED_NOINDEX_PREFIXES = [
        'dashboard', 'panel', 'zed-admin', 'payments', 'webhooks', 'telegram',
        'wallet', 'orders', 'profile', 'tickets', 'health', 'up', 'filament',
        'livewire', 'storage',
    ];

    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'msclkid', 'yclid', 'mc_cid', 'mc_eid', 'ref',
    ];

    private ?string $pageKey = null;

    private ?Model $model = null;

    /** @var array<string,mixed> */
    private array $overrides = [];

    /** @var array<int,array{name:string,url?:string}> */
    private array $breadcrumbs = [];

    /** @var array<int,string> query params kept in the canonical (e.g. pagination). */
    private array $allowedQueryParams = ['page'];

    private ?SeoData $resolved = null;

    // ── Fluent request-time configuration ────────────────────────────────────

    public function forKey(?string $key): self
    {
        $this->pageKey = $key;
        $this->resolved = null;

        return $this;
    }

    public function useModel(?Model $model): self
    {
        $this->model = $model;
        $this->resolved = null;

        return $this;
    }

    /** @param array<string,mixed> $overrides */
    public function set(array $overrides): self
    {
        $this->overrides = array_merge($this->overrides, $overrides);
        $this->resolved = null;

        return $this;
    }

    /** @param array<int,array{name:string,url?:string}> $items */
    public function breadcrumbs(array $items): self
    {
        $this->breadcrumbs = $items;
        $this->resolved = null;

        return $this;
    }

    /** @return array<int,array{name:string,url?:string}> */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /** @param array<int,string> $params */
    public function allowQueryParams(array $params): self
    {
        $this->allowedQueryParams = $params;
        $this->resolved = null;

        return $this;
    }

    public function pageKey(): ?string
    {
        return $this->pageKey;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    public function resolve(): SeoData
    {
        return $this->resolved ??= $this->build();
    }

    private function build(): SeoData
    {
        $record = $this->pageKey ? SeoPage::findByKey($this->pageKey) : null;
        $model = $this->model;

        $siteName = SeoSettings::siteName();

        $title = $this->firstFilled(
            $this->overrides['title'] ?? null,
            $record?->meta_title,
            $model instanceof Page ? ($model->meta_title ?: $model->title) : null,
            $model instanceof Tutorial ? ($model->meta_title ?: $model->title) : null,
            SeoSettings::defaultTitle(),
        );

        $description = $this->firstFilled(
            $this->overrides['description'] ?? null,
            $record?->meta_description,
            $model instanceof Page ? ($model->meta_description ?: $model->excerpt) : null,
            $model instanceof Tutorial ? ($model->meta_description ?: $model->short_description) : null,
            SeoSettings::defaultDescription(),
        );

        $keywords = $this->firstFilled(
            $this->overrides['keywords'] ?? null,
            $record?->meta_keywords,
            $model instanceof Page ? $model->meta_keywords : null,
        );

        // Robots — forced noindex wins over everything.
        [$index, $follow] = $this->resolveRobots($record, $model);

        // Canonical.
        $canonical = $this->buildCanonical($this->firstFilled(
            $this->overrides['canonical'] ?? null,
            $record?->canonical_url,
            $model?->canonical_url ?? null,
        ));

        // Open Graph.
        $ogTitle = $this->firstFilled(
            $this->overrides['og_title'] ?? null,
            $record?->og_title,
            $model instanceof Tutorial ? $model->og_title : null,
            $model instanceof Page ? $model->og_title : null,
            $title,
        );
        $ogDescription = $this->firstFilled(
            $this->overrides['og_description'] ?? null,
            $record?->og_description,
            $model instanceof Tutorial ? $model->og_description : null,
            $model instanceof Page ? $model->og_description : null,
            $description,
        );
        $ogType = $this->firstFilled(
            $this->overrides['og_type'] ?? null,
            $record?->og_type,
            $model instanceof Tutorial ? 'article' : null,
            'website',
        );
        $ogImage = $this->absoluteUrl($this->firstFilled(
            $this->overrides['og_image'] ?? null,
            $record?->og_image,
            $model?->og_image ?? null,
            SeoSettings::defaultOgImage(),
        ));

        // Twitter.
        $twitterCard = $this->firstFilled($record?->twitter_card, 'summary_large_image');
        $twitterTitle = $this->firstFilled(
            $record?->twitter_title,
            $model instanceof Tutorial ? $model->twitter_title : null,
            $model instanceof Page ? $model->twitter_title : null,
            $ogTitle,
        );
        $twitterDescription = $this->firstFilled(
            $record?->twitter_description,
            $model instanceof Tutorial ? $model->twitter_description : null,
            $model instanceof Page ? $model->twitter_description : null,
            $ogDescription,
        );
        $twitterImage = $this->absoluteUrl($this->firstFilled(
            $record?->twitter_image,
            $model instanceof Tutorial ? $model->twitter_image : null,
            $model instanceof Page ? $model->twitter_image : null,
        )) ?: $ogImage;

        $twitterSite = SeoSettings::twitterUsername();
        if ($twitterSite !== '' && ! str_starts_with($twitterSite, '@')) {
            $twitterSite = '@'.$twitterSite;
        }

        $data = new SeoData(
            title: $this->composeTitle($title, $siteName),
            description: $description,
            keywords: $keywords,
            canonical: $canonical,
            index: $index,
            follow: $follow,
            ogTitle: $ogTitle,
            ogDescription: $ogDescription,
            ogType: $ogType,
            ogUrl: $canonical,
            ogImage: $ogImage,
            ogImageAlt: $ogTitle,
            siteName: $siteName,
            locale: str_replace('-', '_', SeoSettings::locale()),
            twitterCard: $twitterCard,
            twitterTitle: $twitterTitle,
            twitterDescription: $twitterDescription,
            twitterImage: $twitterImage,
            twitterSite: $twitterSite,
            schemas: [],
        );

        // Publish the (schema-less) result BEFORE building structured data:
        // the schema builders call resolve() to read the canonical/title, and
        // without this the memo would still be null and re-enter build() —
        // an infinite recursion. Schemas are then attached to the same object.
        $this->resolved = $data;
        $data->schemas = $this->buildSchemas($record, $model);

        return $data;
    }

    // ── Robots ───────────────────────────────────────────────────────────────

    /** @return array{0:bool,1:bool} */
    private function resolveRobots(?SeoPage $record, ?Model $model): array
    {
        if (self::isForcedNoindexPath(request()?->path() ?? '')) {
            return [false, false];
        }
        if (isset($this->overrides['index']) || isset($this->overrides['follow'])) {
            return [
                (bool) ($this->overrides['index'] ?? true),
                (bool) ($this->overrides['follow'] ?? true),
            ];
        }
        if ($record) {
            return [(bool) $record->robots_index, (bool) $record->robots_follow];
        }
        if ($model && isset($model->robots_index)) {
            return [(bool) $model->robots_index, (bool) ($model->robots_follow ?? true)];
        }

        return [true, true];
    }

    /** True when the path must always be noindex (never indexable via admin). */
    public static function isForcedNoindexPath(string $path): bool
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return false;
        }
        $first = explode('/', $path)[0];

        return in_array($first, self::FORCED_NOINDEX_PREFIXES, true);
    }

    // ── Canonical + URL helpers ──────────────────────────────────────────────

    private function buildCanonical(?string $explicit): string
    {
        if (filled($explicit)) {
            return $this->normalizeUrl(self::stripTrackingParams($this->absoluteUrl($explicit)));
        }

        $path = request()?->path() ?? '/';
        $base = $this->baseUrl();
        $url = $path === '/' ? $base.'/' : $base.'/'.$path;

        // Keep only allowlisted query params (e.g. pagination) — this drops
        // category filters and tracking params so they never create duplicates.
        $kept = [];
        foreach ($this->allowedQueryParams as $param) {
            $val = request()?->query($param);
            if ($val !== null && $val !== '') {
                $kept[$param] = $val;
            }
        }
        if ($kept !== []) {
            $url .= '?'.http_build_query($kept);
        }

        return $this->normalizeUrl($url);
    }

    /** Absolute base "scheme://host" from the real app URL, HTTPS in production. */
    public function baseUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        if ($url === '') {
            $url = request()?->getSchemeAndHttpHost() ?? 'http://localhost';
        }
        // Reduce to scheme+host+port (drop any path in APP_URL).
        $parts = parse_url($url);
        if ($parts !== false && isset($parts['scheme'], $parts['host'])) {
            $url = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }
        if (app()->environment('production') && str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        return $url;
    }

    /** Resolve any path/URL to an absolute URL against the site base. */
    public function absoluteUrl(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return $this->baseUrl().$path;
        }
        // Stored public-disk path → URL, then make absolute.
        $resolved = cms_asset_url($path) ?? '';
        if ($resolved === '') {
            return '';
        }
        if (str_starts_with($resolved, 'http://') || str_starts_with($resolved, 'https://')) {
            return $resolved;
        }

        return $this->baseUrl().'/'.ltrim($resolved, '/');
    }

    /** Remove tracking parameters (utm_*, gclid, fbclid, …) from a URL's query. */
    public static function stripTrackingParams(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $params);
        foreach (array_keys($params) as $key) {
            if (in_array($key, self::TRACKING_PARAMS, true) || str_starts_with($key, 'utm_')) {
                unset($params[$key]);
            }
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = $params !== [] ? '?'.http_build_query($params) : '';

        return $scheme.$host.$port.$path.$query;
    }

    /** Normalize trailing slashes (strip, except the bare root). */
    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.$host.$port.$path.$query;
    }

    // ── Title ────────────────────────────────────────────────────────────────

    /** Append the site name once (never twice). */
    private function composeTitle(string $title, string $siteName): string
    {
        $title = trim($title);
        if ($title === '') {
            return SeoSettings::defaultTitle();
        }
        if ($siteName === '' || mb_stripos($title, $siteName) !== false) {
            return $title;
        }

        return $title.' '.SeoSettings::titleSeparator().' '.$siteName;
    }

    // ── Structured data ──────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function buildSchemas(?SeoPage $record, ?Model $model): array
    {
        $builder = new SchemaBuilder($this);
        $nodes = [];

        // Explicit valid JSON override replaces the auto page-node.
        $override = $this->validSchemaOverride($record?->schema_json_override);

        $key = $this->pageKey;

        // Site-wide nodes (homepage only, gated by toggles).
        if ($key === 'home') {
            if (SeoSettings::bool('seo_schema_organization_enabled', true) && $org = $builder->organization()) {
                $nodes[] = $org;
            }
            if (SeoSettings::bool('seo_schema_website_enabled', true) && $ws = $builder->website()) {
                $nodes[] = $ws;
            }
        }

        // Page-specific node.
        if ($override !== null) {
            $nodes[] = $override;
        } elseif ($model instanceof Tutorial) {
            $nodes[] = $builder->tutorialArticle($model);
        } elseif ($model instanceof Page) {
            $nodes[] = $builder->cmsPage($model);
        } elseif ($key === 'faq') {
            if ($faq = $builder->faqPage($this->overrides['faqs'] ?? null)) {
                $nodes[] = $faq;
            } else {
                $nodes[] = $builder->webPage();
            }
        } elseif ($key === 'plans') {
            if ($list = $builder->plansItemList($this->overrides['plans'] ?? null)) {
                $nodes[] = $list;
            }
            $nodes[] = $builder->webPage('CollectionPage');
        } elseif ($key === 'contact') {
            if ($cp = $builder->contactPage()) {
                $nodes[] = $cp;
            } else {
                $nodes[] = $builder->webPage('ContactPage');
            }
        } elseif ($key === 'home') {
            $nodes[] = $builder->webPage();
        } else {
            $type = $record?->schema_type ?: 'WebPage';
            $nodes[] = $builder->webPage($type);
        }

        // Breadcrumbs (must match the visible trail), gated by toggle.
        if ($this->breadcrumbs !== [] && SeoSettings::bool('seo_schema_breadcrumb_enabled', true)) {
            if ($bc = $builder->breadcrumbList($this->breadcrumbs)) {
                $nodes[] = $bc;
            }
        }

        // Attach @context to every node.
        return array_map(function (array $node): array {
            if (! isset($node['@context'])) {
                return array_merge(['@context' => 'https://schema.org'], $node);
            }

            return $node;
        }, $nodes);
    }

    /** Parse + validate a schema_json_override; null when empty/invalid. */
    public function validSchemaOverride(?string $json): ?array
    {
        $json = trim((string) $json);
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    // ── util ─────────────────────────────────────────────────────────────────

    private function firstFilled(...$values): string
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }

        return '';
    }
}
