<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if ($setting === null) {
            return $default;
        }

        $value = $setting->value;
        if ($value === 'true')  return true;
        if ($value === 'false') return false;
        if (is_numeric($value) && strpos($value, '.') === false) return (int) $value;

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);

        // Invalidate the theme settings memo so any visual change is picked up
        // on the very next read — no manual cache:clear required.
        if (class_exists(\App\Services\Theme\ThemeSettingsService::class)) {
            \App\Services\Theme\ThemeSettingsService::flush();
        }
    }
}
