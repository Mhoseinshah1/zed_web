<?php

namespace App\Services\Seo;

use App\Models\SiteText;
use Illuminate\Support\Facades\Cache;

/**
 * Global SEO configuration — the single source of truth for site-wide defaults
 * (site name, default title/description/OG image, organization data, schema
 * toggles, verification tags). Stored as `seo_*` SiteText key/value pairs and
 * cached as one blob so a page render never issues N queries for these.
 *
 * No secrets are stored here (analytics IDs and verification tokens are public
 * by design; API keys/secrets live in their own encrypted settings).
 */
class SeoSettings
{
    private const CACHE_KEY = 'seo_settings:all';

    /** All manageable keys with their defaults. */
    public const DEFAULTS = [
        'seo_site_name' => 'ZedProxy',
        'seo_default_title' => 'ZedProxy | خرید VPN و پروکسی پرسرعت',
        'seo_default_description' => 'خرید VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و قیمت مناسب',
        'seo_default_og_image' => '',
        'seo_twitter_username' => '',
        'seo_facebook_url' => '',
        'seo_telegram_url' => '',
        'seo_schema_logo' => '',
        'seo_org_name' => 'ZedProxy',
        'seo_org_alternate_name' => '',
        'seo_telephone' => '',
        'seo_email' => '',
        'seo_address' => '',
        'seo_country' => 'IR',
        'seo_default_locale' => 'fa-IR',
        'seo_sitemap_enabled' => '1',
        'seo_custom_robots' => '',
        'seo_google_verification' => '',
        'seo_bing_verification' => '',
        'seo_google_analytics_id' => '',
        'seo_schema_breadcrumb_enabled' => '1',
        'seo_schema_organization_enabled' => '1',
        'seo_schema_website_enabled' => '1',
        'seo_title_separator' => '|',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = Cache::remember(self::CACHE_KEY, 3600, function (): array {
            // Single query for every seo_* key (not one per key), then fall back
            // to the shipped defaults for any missing/empty value.
            $stored = SiteText::whereIn('key', array_keys(self::DEFAULTS))
                ->pluck('value', 'key')
                ->all();

            $values = [];
            foreach (self::DEFAULTS as $key => $default) {
                $v = $stored[$key] ?? null;
                $values[$key] = ($v === null || $v === '') ? $default : $v;
            }

            return $values;
        });

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        $val = $all[$key] ?? $default;

        return $val !== '' ? $val : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::all()[$key] ?? ($default ? '1' : '0');

        return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Persist a value + bust caches. */
    public static function set(string $key, ?string $value): void
    {
        SiteText::set($key, (string) $value, ['group' => 'seo']);
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
        Cache::forget(self::CACHE_KEY);
    }

    // ── Convenience accessors used across the SEO system ─────────────────────

    public static function siteName(): string
    {
        return self::get('seo_site_name', 'ZedProxy');
    }

    public static function defaultTitle(): string
    {
        return self::get('seo_default_title', self::siteName());
    }

    public static function defaultDescription(): string
    {
        return self::get('seo_default_description');
    }

    public static function defaultOgImage(): string
    {
        return self::get('seo_default_og_image');
    }

    public static function locale(): string
    {
        return self::get('seo_default_locale', 'fa-IR');
    }

    public static function titleSeparator(): string
    {
        return self::get('seo_title_separator', '|');
    }

    public static function sitemapEnabled(): bool
    {
        return self::bool('seo_sitemap_enabled', true);
    }

    public static function twitterUsername(): string
    {
        return self::get('seo_twitter_username');
    }
}
