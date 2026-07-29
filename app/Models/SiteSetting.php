<?php

namespace App\Models;

use App\Services\Settings\SettingsRepository;
use App\Services\Theme\ThemeSettingsService;
use Illuminate\Database\Eloquent\Model;

/**
 * Compatibility facade over {@see SettingsRepository}.
 *
 * ~188 call sites read settings through this static API, so it stays. What
 * changed is where the state lives: reads are served by a container-`scoped`
 * repository that loads the table once per application lifecycle, instead of
 * one query per key.
 *
 * Every write path — including the query-builder ones, which fire no model
 * events — goes through a method here, so invalidation cannot be forgotten at
 * the call site. `upsertValue()` was introduced by the MailPipelineHealth
 * extraction precisely so invalidation could later be added in ONE place; this
 * is that change, extending it rather than replacing it.
 */
class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // Covers direct model saves and deletes anywhere in the codebase.
        static::saved(fn (self $setting) => static::repository()->remember($setting->key, $setting->value));
        static::deleted(fn (self $setting) => static::repository()->forget($setting));
    }

    /**
     * The scoped reader for the CURRENT lifecycle.
     *
     * Resolved from the container every time rather than held anywhere: that is
     * what lets `forgetScopedInstances()` — and the explicit queue hook — hand
     * the next job a genuinely fresh instance.
     */
    public static function repository(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    /** Drop the current lifecycle's loaded settings. */
    public static function flush(): void
    {
        app()->forgetInstance(SettingsRepository::class);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::repository()->get($key, $default);
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

        static::repository()->remember($key, $value);
    }

    /**
     * Insert rows that do not exist yet, never overwriting live values, then
     * invalidate. Race-safe under the unique `key` index.
     *
     * @param  array<int,array{key:string,value:string}>  $rows
     */
    public static function insertMissing(array $rows): void
    {
        static::query()->insertOrIgnore(array_map(
            fn (array $row) => $row + ['created_at' => now(), 'updated_at' => now()],
            $rows,
        ));

        // insertOrIgnore may or may not have written each row, so the value now
        // stored is either the pre-existing one or ours — guessing would invent
        // data. Re-read just the affected keys rather than dropping the whole
        // map: flushing everything would force a second FULL-table read on any
        // request that touches this path (registration does), undoing the
        // one-read-per-lifecycle property this class exists for.
        $keys = array_column($rows, 'key');

        foreach (static::query()->whereIn('key', $keys)->pluck('value', 'key') as $key => $value) {
            static::repository()->remember((string) $key, $value);
        }
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
