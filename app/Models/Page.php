<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Page extends Model
{
    protected $fillable = [
        'slug', 'title', 'content', 'excerpt',
        'meta_title', 'meta_description', 'meta_keywords',
        'og_title', 'og_description', 'og_image',
        'canonical_url', 'robots_index', 'robots_follow',
        'twitter_title', 'twitter_description', 'twitter_image',
        'schema_type', 'include_in_sitemap', 'sitemap_priority', 'sitemap_change_frequency',
        'is_active', 'show_in_footer', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_footer' => 'boolean',
        'sort_order' => 'integer',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'include_in_sitemap' => 'boolean',
        'sitemap_priority' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (Page $page) {
            if (empty($page->slug)) {
                $page->slug = Str::slug($page->title) ?: 'page-'.uniqid();
            }
        });

        // Invalidate the pages sitemap cache when CMS content changes.
        $bust = fn () => Cache::forget('seo_sitemap:pages');
        static::saved($bust);
        static::deleted($bust);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
