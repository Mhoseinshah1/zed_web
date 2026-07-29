<?php

namespace App\Models;

use App\Services\Theme\ThemeSettingsService;
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
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value) && strpos($value, '.') === false) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Atomic single-key upsert.
     *
     * `set()` reads then inserts, so two callers racing to create the SAME
     * missing key let the loser die on the unique `key` index — which turns a
     * fail-open health probe into a 500 on a dashboard request or a
     * registration. An upsert makes the loser update instead; both racers
     * persist the same value.
     *
     * Deliberately the ONLY sanctioned way to write a single key from a
     * query-builder context. Keeping every such write behind one method is what
     * lets a later change add cache or reader invalidation here once, rather
     * than hunting call sites — see the stall-marker write in
     * MailPipelineHealth, which used a raw builder upsert before this existed.
     */
    public static function upsertValue(string $key, string $value): void
    {
        static::query()->upsert(
            [['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);

        // Invalidate the theme settings memo so any visual change is picked up
        // on the very next read — no manual cache:clear required.
        if (class_exists(ThemeSettingsService::class)) {
            ThemeSettingsService::flush();
        }
    }
}
