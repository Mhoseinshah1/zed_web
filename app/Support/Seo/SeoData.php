<?php

namespace App\Support\Seo;

/**
 * Immutable-ish value object holding the fully-resolved SEO values for one
 * request, ready to be rendered by <x-seo-head>. All fallbacks, canonical
 * cleanup and absolute-URL resolution have already been applied by SeoManager,
 * so the Blade component only prints what is present.
 */
class SeoData
{
    public function __construct(
        public string $title = '',
        public string $description = '',
        public string $keywords = '',
        public string $canonical = '',
        public bool $index = true,
        public bool $follow = true,
        public string $ogTitle = '',
        public string $ogDescription = '',
        public string $ogType = 'website',
        public string $ogUrl = '',
        public string $ogImage = '',
        public string $ogImageAlt = '',
        public string $siteName = '',
        public string $locale = 'fa-IR',
        public string $twitterCard = 'summary_large_image',
        public string $twitterTitle = '',
        public string $twitterDescription = '',
        public string $twitterImage = '',
        public string $twitterSite = '',
        /** @var array<int,array<string,mixed>> full JSON-LD graph nodes */
        public array $schemas = [],
        /** @var array<int,int|string> canonical rel prev/next etc. */
        public array $extraLinks = [],
    ) {}

    /** Robots meta content, e.g. "index, follow" / "noindex, nofollow". */
    public function robots(): string
    {
        return ($this->index ? 'index' : 'noindex') . ', ' . ($this->follow ? 'follow' : 'nofollow');
    }
}
