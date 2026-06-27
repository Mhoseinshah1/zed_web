<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteText extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    public static function get(string $key, string $default = ''): string
    {
        return Cache::remember("site_text:{$key}", 3600, function () use ($key, $default) {
            $record = static::where('key', $key)->first();
            return $record ? $record->value : $default;
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
