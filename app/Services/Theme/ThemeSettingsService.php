<?php

namespace App\Services\Theme;

use App\Models\SiteSetting;

/**
 * Central read/write access to all theme/appearance settings.
 *
 * Wraps the SiteSetting key/value store and adds a per-request memo so a single
 * page render does not hit the database once per key. Writes update the memo
 * immediately and `flush()` clears it — settings are therefore always read
 * "live" within a request and never require a manual `php artisan cache:clear`
 * for a visual change to appear.
 *
 * This is the single place admin appearance settings are persisted, so cache
 * invalidation is guaranteed to happen alongside every write.
 *
 * @phpstan-type SettingValue string|int|bool|null
 */
class ThemeSettingsService
{
    /** @var array<string,mixed>|null per-request memo (null = not primed) */
    protected static ?array $memo = null;

    /** Read a single setting, falling back to $default when missing. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $memo = self::memo();
        return array_key_exists($key, $memo) ? $memo[$key] : $default;
    }

    /**
     * Read the first present value among $keys (used for admin_* → legacy
     * fallback), or $default if none are set.
     *
     * @param  array<int,string>  $keys
     */
    public static function firstOf(array $keys, mixed $default = null): mixed
    {
        $memo = self::memo();
        foreach ($keys as $key) {
            if (array_key_exists($key, $memo) && $memo[$key] !== null && $memo[$key] !== '') {
                return $memo[$key];
            }
        }
        return $default;
    }

    /** Persist a single setting and refresh the memo (cache invalidation). */
    public static function set(string $key, mixed $value): void
    {
        SiteSetting::set($key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        if (self::$memo !== null) {
            self::$memo[$key] = SiteSetting::get($key);
        }
    }

    /**
     * Persist many settings at once.
     *
     * @param  array<string,mixed>  $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    /** Drop the per-request memo so the next read hits the database afresh. */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /** @return array<string,mixed> all settings, primed once per request */
    protected static function memo(): array
    {
        if (self::$memo === null) {
            self::$memo = SiteSetting::query()->pluck('value', 'key')->all();
            // Cast like SiteSetting::get so callers see consistent types.
            foreach (self::$memo as $k => $v) {
                self::$memo[$k] = self::cast($v);
            }
        }
        return self::$memo;
    }

    /** Mirror SiteSetting's value casting. */
    protected static function cast(mixed $value): mixed
    {
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_string($value) && is_numeric($value) && ! str_contains($value, '.')) {
            return (int) $value;
        }
        return $value;
    }
}
