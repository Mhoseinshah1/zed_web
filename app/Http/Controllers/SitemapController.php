<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\SeoPage;
use App\Models\Tutorial;
use App\Services\Seo\SeoManager;
use App\Services\Seo\SeoSettings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;

/**
 * XML sitemaps. Only public, active, indexable URLs are included; noindex and
 * redirect-alias pages are excluded, URLs are absolute (HTTPS in production),
 * lastmod values are real, and output is cached and capped at the 50k limit.
 *
 *   /sitemap.xml            → sitemap index
 *   /sitemap-pages.xml      → static public pages + active CMS pages
 *   /sitemap-tutorials.xml  → active tutorials
 */
class SitemapController extends Controller
{
    private const MAX_URLS = 50000;

    private const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>';

    public function __construct(private readonly SeoManager $seo) {}

    /** Sitemap index referencing the per-type sitemaps. */
    public function index(): Response
    {
        $this->ensureEnabled();

        $xml = Cache::remember('seo_sitemap:index', 3600, function (): string {
            $base = $this->seo->baseUrl();
            $now = now()->toAtomString();
            $items = '';
            foreach (['sitemap-pages.xml', 'sitemap-tutorials.xml'] as $child) {
                $items .= '<sitemap>'
                    .'<loc>'.e("{$base}/{$child}").'</loc>'
                    ."<lastmod>{$now}</lastmod>"
                    .'</sitemap>';
            }

            return self::XML_HEADER
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .$items
                .'</sitemapindex>';
        });

        return $this->xml($xml);
    }

    /** Static public pages + active, indexable CMS pages. */
    public function pages(): Response
    {
        $this->ensureEnabled();

        $xml = Cache::remember('seo_sitemap:pages', 3600, function (): string {
            $urls = [];

            // Static public pages managed via SeoPage. Only real routes (never
            // redirect aliases like /terms, whose route_name is null).
            foreach (SeoPage::active()->where('include_in_sitemap', true)->where('robots_index', true)->get() as $sp) {
                if (! $sp->route_name || ! RouteFacade::has($sp->route_name)) {
                    continue;
                }
                if (SeoManager::isForcedNoindexPath(parse_url(route($sp->route_name, [], false), PHP_URL_PATH) ?? '')) {
                    continue;
                }
                $urls[] = [
                    'loc' => $this->seo->absoluteUrl(route($sp->route_name, [], false)),
                    'changefreq' => $sp->sitemap_change_frequency,
                    'priority' => number_format((float) $sp->sitemap_priority, 1),
                    'lastmod' => optional($sp->updated_at)->toAtomString(),
                ];
            }

            // Active CMS pages.
            $query = Page::query()->where('is_active', true);
            if (Schema::hasColumn('pages', 'include_in_sitemap')) {
                $query->where('include_in_sitemap', true)->where('robots_index', true);
            }
            foreach ($query->get() as $page) {
                $urls[] = [
                    'loc' => $this->seo->absoluteUrl(route('pages.show', $page->slug, false)),
                    'changefreq' => $page->sitemap_change_frequency ?? 'monthly',
                    'priority' => number_format((float) ($page->sitemap_priority ?? 0.6), 1),
                    'lastmod' => optional($page->updated_at)->toAtomString(),
                ];
            }

            return $this->urlset($urls);
        });

        return $this->xml($xml);
    }

    /** Active, indexable tutorials. */
    public function tutorials(): Response
    {
        $this->ensureEnabled();

        $xml = Cache::remember('seo_sitemap:tutorials', 3600, function (): string {
            $urls = [];
            $query = Tutorial::query()->where('is_active', true);
            if (Schema::hasColumn('tutorials', 'include_in_sitemap')) {
                $query->where('include_in_sitemap', true)->where('robots_index', true);
            }
            foreach ($query->get() as $t) {
                $urls[] = [
                    'loc' => $this->seo->absoluteUrl(route('tutorials.show', $t->slug, false)),
                    'changefreq' => $t->sitemap_change_frequency ?? 'monthly',
                    'priority' => number_format((float) ($t->sitemap_priority ?? 0.6), 1),
                    'lastmod' => optional($t->published_at ?? $t->updated_at)->toAtomString(),
                ];
            }

            return $this->urlset($urls);
        });

        return $this->xml($xml);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<int,array<string,?string>> $urls */
    private function urlset(array $urls): string
    {
        $urls = array_slice($urls, 0, self::MAX_URLS);
        $body = '';
        foreach ($urls as $u) {
            if (empty($u['loc'])) {
                continue;
            }
            $body .= '<url><loc>'.e($u['loc']).'</loc>';
            if (! empty($u['lastmod'])) {
                $body .= '<lastmod>'.e($u['lastmod']).'</lastmod>';
            }
            if (! empty($u['changefreq'])) {
                $body .= '<changefreq>'.e($u['changefreq']).'</changefreq>';
            }
            if (isset($u['priority'])) {
                $body .= '<priority>'.e($u['priority']).'</priority>';
            }
            $body .= '</url>';
        }

        return self::XML_HEADER
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body
            .'</urlset>';
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function ensureEnabled(): void
    {
        abort_unless(SeoSettings::sitemapEnabled(), 404);
    }
}
