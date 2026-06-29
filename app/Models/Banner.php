<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'description', 'image', 'background_image',
        'button_text', 'button_url', 'placement', 'theme_style',
        'starts_at', 'ends_at', 'is_active', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'metadata'   => 'array',
    ];

    /** Placement keys → Persian labels. */
    public static function placements(): array
    {
        return [
            'home_top'       => 'صفحه اصلی بالا',
            'home_middle'    => 'صفحه اصلی میانی',
            'homepage_top'   => 'صفحه اصلی بالا (جایگزین)',
            'homepage_middle' => 'صفحه اصلی میانی (جایگزین)',
            'shop_top'       => 'بالای فروشگاه',
            'plans_top'      => 'بالای پلن‌ها',
            'dashboard_top'  => 'بالای داشبورد',
            'wallet_top'     => 'بالای کیف پول',
        ];
    }

    /** Active now: respects is_active + starts_at/ends_at window. */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function scopePlacement(Builder $query, string $placement): Builder
    {
        return $query->where('placement', $placement);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('id');
    }

    /** Convenience: live banners for a placement, ordered. */
    public static function forPlacement(string $placement)
    {
        return static::query()->live()->placement($placement)->ordered()->get();
    }
}
