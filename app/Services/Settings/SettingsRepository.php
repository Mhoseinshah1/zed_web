<?php

namespace App\Services\Settings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads `site_settings` at most once per application lifecycle.
 *
 * ── Why this is a scoped service and not a static memo ────────────────────
 *
 * `SiteSetting::get()` used to issue its own `where key = ? limit 1` on every
 * call, and it is called ~188 times across the application. Measured on a
 * dashboard page: 17 of 21 queries were single-key settings lookups, 15 of
 * which returned nothing. Loading the table once is the fix.
 *
 * The obvious implementation — a `private static ?array $memo` on the model —
 * is WRONG, and the first version of this change shipped it. A PHP static is
 * PROCESS-scoped. A queue worker is one process handling jobs for hours, so:
 *
 *   1. Job A reads a setting; the worker memoises the whole table.
 *   2. An administrator changes it in the web process.
 *   3. The web process clears only its own memory.
 *   4. Job B, same worker, still reads the old value — until a restart.
 *
 * These settings gate email-verification enforcement, SMTP runtime
 * configuration, payments, backups and feature switches, so the failure mode is
 * a worker running for days on a stale security toggle.
 *
 * This class is therefore registered with the container's `scoped` lifetime and
 * is additionally forgotten on every queue-job boundary. Both matter:
 * `Illuminate\Queue\Worker` only calls `forgetScopedInstances()` from its
 * `daemon()` loop (verified in Laravel 12.62.0), so `queue:work --once`, the
 * `sync` connection, and any custom runner would otherwise keep the instance
 * alive across jobs.
 *
 * A TTL cache was rejected deliberately: a stale read of a security toggle from
 * a shared cache is worse than a repeated query, and correct invalidation would
 * need a versioning scheme this does not warrant.
 */
class SettingsRepository
{
    /** @var array<string,string|null>|null */
    private ?array $values = null;

    /** Load the whole table once for this lifecycle. */
    private function values(): array
    {
        return $this->values ??= DB::table('site_settings')
            ->pluck('value', 'key')
            ->all();
    }

    /** True when the key exists as a row (a NULL value still counts). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values());
    }

    /** Raw stored value, or null when the row is absent. */
    public function raw(string $key): ?string
    {
        $values = $this->values();

        return array_key_exists($key, $values) ? $values[$key] : null;
    }

    /**
     * Coerced value, preserving the historical behaviour exactly:
     * `'true'`/`'false'` become booleans, an integral numeric string becomes an
     * int, everything else stays a string, and a MISSING row yields $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->raw($key);

        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value) && ! str_contains((string) $value, '.')) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Every raw stored value for this lifecycle.
     *
     * Exposed so other readers (ThemeSettingsService) can share the ONE
     * whole-table read instead of issuing their own `pluck()`.
     *
     * @return array<string,string|null>
     */
    public function all(): array
    {
        return $this->values();
    }

    /** Drop the loaded map; the next read re-queries. */
    public function flush(): void
    {
        $this->values = null;
    }

    /**
     * Keep the map correct after a write we performed ourselves, without
     * paying for a re-read. Called by the model's write paths.
     */
    public function remember(string $key, ?string $value): void
    {
        if ($this->values !== null) {
            $this->values[$key] = $value;
        }
    }

    /** Model-event hook: any Eloquent write or delete invalidates. */
    public function forget(Model|string $key): void
    {
        if ($this->values === null) {
            return;
        }

        $name = $key instanceof Model ? (string) $key->getAttribute('key') : $key;
        unset($this->values[$name]);
    }
}
