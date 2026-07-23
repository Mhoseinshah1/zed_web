<?php

namespace App\Services\Health;

use App\Support\SecretMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs the component health checks (database, migrations, Redis, storage) and
 * returns structured results.
 *
 * Two consumption paths:
 *  - The PUBLIC /health endpoint reads only the boolean `ok` of each component.
 *  - The ADMIN diagnostics command reads the masked error text.
 *
 * Exception messages are NEVER returned raw: they are masked via SecretMasker
 * and logged securely. Each check uses unique temporary keys/files and cleans
 * them up, so concurrent health requests never collide. External checks are
 * bounded by a short timeout.
 */
class HealthCheckService
{
    /** Seconds before an external check (DB/Redis) is considered failed. */
    public const TIMEOUT = 2;

    public function __construct()
    {
        // Bound the Redis client so a dead endpoint can't hang the request.
        config([
            'database.redis.default.timeout'            => self::TIMEOUT,
            'database.redis.default.read_write_timeout' => self::TIMEOUT,
        ]);
    }

    /**
     * Run every component check.
     *
     * @return array<string, array{ok: bool, error: ?string}>
     */
    public function collect(): array
    {
        return [
            'app'        => $this->componentApp(),
            'database'   => $this->componentDatabase(),
            'redis'      => $this->componentRedis(),
            'migrations' => $this->componentMigrations(),
            'storage'    => $this->componentStorage(),
        ];
    }

    /**
     * Boolean-only view of the checks — safe for public consumption.
     *
     * @return array<string, bool>
     */
    public function booleans(): array
    {
        return array_map(fn (array $r) => (bool) $r['ok'], $this->collect());
    }

    public function componentApp(): array
    {
        // Liveness: the framework booted and can serve a request.
        return $this->safe('app', fn () => true);
    }

    public function componentDatabase(): array
    {
        return $this->safe('database', function () {
            $pdo = DB::connection()->getPdo();
            try {
                $pdo->setAttribute(\PDO::ATTR_TIMEOUT, self::TIMEOUT);
            } catch (\Throwable) {
                // Not all drivers support ATTR_TIMEOUT; the connect itself proves reachability.
            }
            DB::connection()->select('select 1');
            return true;
        });
    }

    public function componentMigrations(): array
    {
        return $this->safe('migrations', function () {
            if (! Schema::hasTable('migrations')) {
                throw new \RuntimeException('migrations table missing');
            }
            DB::table('migrations')->count();
            return true;
        });
    }

    public function componentRedis(): array
    {
        return $this->safe('redis', function () {
            $key = 'health:ping:' . Str::random(16);
            $connection = Redis::connection();
            try {
                $connection->setex($key, self::TIMEOUT, '1');
                if ((string) $connection->get($key) !== '1') {
                    throw new \RuntimeException('redis readback mismatch');
                }
                return true;
            } finally {
                try {
                    $connection->del($key);
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }
        });
    }

    public function componentStorage(): array
    {
        return $this->safe('storage', function () {
            $disk = Storage::disk('local');
            $file = 'health/.write-test-' . Str::uuid()->toString();
            try {
                $disk->put($file, 'ok');
                if ($disk->get($file) !== 'ok') {
                    throw new \RuntimeException('storage readback mismatch');
                }
                return true;
            } finally {
                try {
                    $disk->delete($file);
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }
        });
    }

    /**
     * Execute a check, capturing any failure as a masked, logged error.
     *
     * @return array{ok: bool, error: ?string}
     */
    private function safe(string $component, callable $check): array
    {
        try {
            return ['ok' => (bool) $check(), 'error' => null];
        } catch (\Throwable $e) {
            $masked = SecretMasker::mask($e->getMessage());

            // Log securely for operators — masked message only, no stack trace,
            // no credentials, no endpoints.
            Log::warning('health.check_failed', [
                'component' => $component,
                'error'     => $masked,
            ]);

            return ['ok' => false, 'error' => $masked];
        }
    }
}
