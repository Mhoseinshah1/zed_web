<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tutorial extends Model
{
    protected $fillable = [
        'title', 'slug', 'platform', 'short_description', 'content',
        'video_url', 'image', 'meta_title', 'meta_description', 'og_image',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Platform keys → Persian labels. */
    public static function platforms(): array
    {
        return [
            'android' => 'اندروید',
            'ios'     => 'آیفون',
            'windows' => 'ویندوز',
            'mac'     => 'مک',
            'linux'   => 'لینوکس',
            'router'  => 'مودم/روتر',
            'general' => 'عمومی',
        ];
    }

    public function platformLabel(): string
    {
        return static::platforms()[$this->platform] ?? $this->platform;
    }

    protected static function booted(): void
    {
        static::saving(function (Tutorial $tutorial) {
            if (empty($tutorial->slug)) {
                $tutorial->slug = Str::slug($tutorial->title) ?: 'tutorial-' . uniqid();
            }
        });
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
