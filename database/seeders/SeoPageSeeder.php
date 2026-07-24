<?php

namespace Database\Seeders;

use App\Models\SeoPage;
use Illuminate\Database\Seeder;

/**
 * Seeds the manageable SEO records for the public static pages. Idempotent:
 * uses firstOrCreate keyed on page_key, so re-running never overwrites
 * administrator-edited values. Login/register default to noindex and are
 * marked lock_noindex so the admin UI can warn before making them indexable.
 */
class SeoPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $page) {
            SeoPage::firstOrCreate(['page_key' => $page['page_key']], $page);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function pages(): array
    {
        $index = fn (array $extra = []) => array_merge([
            'robots_index' => true,
            'robots_follow' => true,
            'lock_noindex' => false,
            'og_type' => 'website',
            'twitter_card' => 'summary_large_image',
            'include_in_sitemap' => true,
            'sitemap_priority' => 0.6,
            'sitemap_change_frequency' => 'weekly',
            'is_active' => true,
        ], $extra);

        $noindex = fn (array $extra = []) => array_merge($index(), [
            'robots_index' => false,
            'robots_follow' => false,
            'lock_noindex' => true,
            'include_in_sitemap' => false,
            'is_active' => true,
        ], $extra);

        return [
            $index(['page_key' => 'home', 'label' => 'صفحه اصلی', 'route_name' => 'home', 'schema_type' => 'WebPage', 'sitemap_priority' => 1.0, 'sitemap_change_frequency' => 'daily']),
            $index(['page_key' => 'plans', 'label' => 'پلن‌ها / فروشگاه', 'route_name' => 'plans', 'schema_type' => 'CollectionPage', 'sitemap_priority' => 0.9, 'sitemap_change_frequency' => 'daily']),
            $index(['page_key' => 'faq', 'label' => 'سوالات متداول', 'route_name' => 'faq', 'schema_type' => 'FAQPage']),
            $index(['page_key' => 'tutorials', 'label' => 'آموزش‌ها', 'route_name' => 'tutorials', 'schema_type' => 'CollectionPage']),
            $index(['page_key' => 'status', 'label' => 'وضعیت سرویس‌ها', 'route_name' => 'status', 'schema_type' => 'WebPage', 'sitemap_change_frequency' => 'hourly']),
            $index(['page_key' => 'contact', 'label' => 'تماس با ما', 'route_name' => 'contact', 'schema_type' => 'ContactPage']),
            $index(['page_key' => 'about', 'label' => 'درباره ما', 'route_name' => null, 'schema_type' => 'AboutPage']),
            $index(['page_key' => 'terms', 'label' => 'قوانین و مقررات', 'route_name' => null, 'schema_type' => 'WebPage', 'sitemap_priority' => 0.3, 'sitemap_change_frequency' => 'yearly']),
            $index(['page_key' => 'privacy', 'label' => 'حریم خصوصی', 'route_name' => null, 'schema_type' => 'WebPage', 'sitemap_priority' => 0.3, 'sitemap_change_frequency' => 'yearly']),
            $noindex(['page_key' => 'login', 'label' => 'ورود', 'route_name' => 'login']),
            $noindex(['page_key' => 'register', 'label' => 'ثبت‌نام', 'route_name' => 'register']),
        ];
    }
}
