<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LandingSection extends Model
{
    protected $fillable = [
        'key', 'title', 'subtitle', 'content', 'type',
        'image', 'background_image', 'icon',
        'button_text', 'button_url', 'secondary_button_text', 'secondary_button_url',
        'items', 'settings', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'items'      => 'array',
        'settings'   => 'array',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Section type keys → Persian labels. */
    public static function types(): array
    {
        return [
            'hero'          => 'هیرو',
            'features'      => 'ویژگی‌ها',
            'stats'         => 'آمار',
            'locations'     => 'لوکیشن‌ها',
            'plans_preview' => 'پیش‌نمایش پلن‌ها',
            'banners'       => 'بنرها',
            'faq'           => 'سوالات متداول',
            'testimonials'  => 'نظرات کاربران',
            'trust'         => 'اعتمادسازی',
            'steps'         => 'مراحل',
            'call_to_action' => 'دعوت به اقدام',
            'custom'        => 'سفارشی',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
