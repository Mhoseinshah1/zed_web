<?php

namespace App\Console\Commands;

use App\Services\Health\HealthCheckService;
use Illuminate\Console\Command;

/**
 * Detailed, operator-only health diagnostics. Unlike the public /health
 * endpoint, this may show a per-component status and a MASKED error reason
 * (secrets, hostnames, endpoints redacted by SecretMasker). Intended for the
 * server console / installer / updater — never exposed over HTTP.
 *
 * Exit code 0 when everything is healthy, 1 otherwise.
 */
class HealthCommand extends Command
{
    protected $signature = 'zedproxy:health {--json : Output machine-readable JSON}';

    protected $description = 'Detailed system health diagnostics (database, migrations, Redis, storage).';

    public function handle(HealthCheckService $health): int
    {
        $results = $health->collect();
        $allOk   = collect($results)->every(fn (array $r) => $r['ok'] === true);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status'     => $allOk ? 'ok' : 'error',
                'components' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $allOk ? self::SUCCESS : self::FAILURE;
        }

        $labels = [
            'app'        => 'برنامه',
            'database'   => 'اتصال دیتابیس برقرار نیست.',
            'redis'      => 'اتصال Redis برقرار نیست.',
            'migrations' => 'جدول مهاجرت‌ها',
            'storage'    => 'دسترسی نوشتن در فضای ذخیره‌سازی وجود ندارد.',
        ];

        $this->info('وضعیت کلی سیستم: ' . ($allOk ? 'سالم ✅' : 'ناسالم ❌'));
        $this->newLine();

        $rows = [];
        foreach ($results as $name => $result) {
            $rows[] = [
                $name,
                $result['ok'] ? '✅' : '❌',
                $result['ok'] ? '—' : ($this->reasonFor($name, $labels) . ($result['error'] ? "  ({$result['error']})" : '')),
            ];
        }

        $this->table(['Component', 'OK', 'Detail'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function reasonFor(string $name, array $labels): string
    {
        return $labels[$name] ?? $name;
    }
}
