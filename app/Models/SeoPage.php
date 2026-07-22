<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

// Note: SeoPage records are looked up once per request and memoized by
// SeoManager, so we deliberately do NOT cache the Eloquent model object itself
// (serialising models across processes is fragile). Only sitemap output and the
// global settings blob are cross-request cached.

/**
 * Per-page manageable SEO record for a public static page. The `page_key` is the
 * stable identifier the SeoManager resolves against; `lock_noindex` marks pages
 * (login/register) whose noindex state must not be silently flipped.
 */
class SeoPage extends Model
{
    protected $fillable = [
        'page_key', 'label', 'route_name',
        'meta_title', 'meta_description', 'meta_keywords', 'canonical_url',
        'robots_index', 'robots_follow', 'lock_noindex',
        'og_title', 'og_description', 'og_image', 'og_type',
        'twitter_card', 'twitter_title', 'twitter_description', 'twitter_image',
        'schema_type', 'schema_json_override',
        'include_in_sitemap', 'sitemap_priority', 'sitemap_change_frequency',
        'is_active',
    ];

    protected $casts = [
        'robots_index'        => 'boolean',
        'robots_follow'       => 'boolean',
        'lock_noindex'        => 'boolean',
        'include_in_sitemap'  => 'boolean',
        'is_active'           => 'boolean',
        'sitemap_priority'    => 'float',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Lookup by page_key. Not object-cached — see the class note above. */
    public static function findByKey(string $key): ?self
    {
        return static::where('page_key', $key)->first();
    }

    protected static function booted(): void
    {
        $bust = function () {
            Cache::forget('seo_sitemap:pages');
            Cache::forget('seo_sitemap:index');
        };
        static::saved($bust);
        static::deleted($bust);
    }
}
