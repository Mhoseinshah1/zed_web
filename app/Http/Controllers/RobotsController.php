<?php

namespace App\Http\Controllers;

use App\Services\Seo\SeoManager;
use App\Services\Seo\SeoSettings;
use Illuminate\Http\Response;

/**
 * Dynamic /robots.txt.
 *
 * Production: allow crawling but always disallow the sensitive areas, then
 * append admin-defined custom rules — which can NEVER remove the required
 * security disallows (any admin "Allow:" targeting a protected prefix is
 * dropped). Non-production environments disallow everything.
 */
class RobotsController extends Controller
{
    /** Prefixes that must always be disallowed and can't be re-allowed. */
    private const FORCED_DISALLOW = [
        '/dashboard/', '/panel/', '/zed-admin/', '/payments/',
        '/webhooks/', '/telegram/', '/wallet/', '/filament/', '/livewire/',
    ];

    public function __invoke(SeoManager $seo): Response
    {
        $lines = [];

        if (! app()->environment('production')) {
            // Never let staging/local get indexed.
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'User-agent: *';
            $lines[] = 'Allow: /';
            foreach (self::FORCED_DISALLOW as $path) {
                $lines[] = 'Disallow: '.$path;
            }

            // Admin-defined extra rules, filtered so they cannot re-open a
            // protected area.
            foreach ($this->customLines() as $line) {
                if ($this->reopensProtected($line)) {
                    continue;
                }
                $lines[] = $line;
            }

            if (SeoSettings::sitemapEnabled()) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.$seo->baseUrl().'/sitemap.xml';
            }
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /** @return array<int,string> */
    private function customLines(): array
    {
        $raw = SeoSettings::get('seo_custom_robots');
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** True when a custom line would Allow a protected prefix. */
    private function reopensProtected(string $line): bool
    {
        if (! preg_match('/^allow:\s*(\S+)/i', $line, $m)) {
            return false;
        }
        $target = $m[1];
        foreach (self::FORCED_DISALLOW as $path) {
            if (str_starts_with($target, $path) || $target === rtrim($path, '/')) {
                return true;
            }
        }

        return false;
    }
}
