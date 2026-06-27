<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteText extends Model
{
    protected $fillable = [
        'key', 'group', 'label', 'value', 'description',
        'type', 'is_public', 'sort_order',
    ];

    protected $casts = [
        'is_public'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function get(string $key, string $default = ''): string
    {
        return Cache::remember("site_text:{$key}", 3600, function () use ($key, $default) {
            $record = static::where('key', $key)->first();
            return $record ? (string) $record->value : $default;
        });
    }

    protected static function booted(): void
    {
        static::saved(function (SiteText $text) {
            Cache::forget("site_text:{$text->key}");
        });

        static::deleted(function (SiteText $text) {
            Cache::forget("site_text:{$text->key}");
        });
    }
}
