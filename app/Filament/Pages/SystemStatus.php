<?php

namespace App\Filament\Pages;

use App\Support\SchedulerHeartbeat;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SystemStatus extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-server';
    protected static ?string $navigationGroup = 'سیستم';
    protected static ?string $navigationLabel = 'وضعیت سیستم';
    protected static ?string $title           = 'وضعیت سیستم';
    protected static ?int $navigationSort     = 10;

    protected static string $view = 'filament.pages.system-status';

    public array $checks = [];

    public array $scheduler = [];

    public function mount(): void
    {
        $this->checks    = $this->runChecks();
        $this->scheduler = $this->schedulerStatus();
    }

    /**
     * Scheduler heartbeat status for the "وضعیت زمان‌بندی وظایف" panel.
     */
    private function schedulerStatus(): array
    {
        $last     = SchedulerHeartbeat::lastRunAt();
        $healthy  = SchedulerHeartbeat::isHealthy();
        $timezone = (string) config('app.timezone', 'UTC');

        return [
            'ok'          => $healthy,
            'last_run'    => $last?->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'age_seconds' => SchedulerHeartbeat::ageSeconds(),
            'timezone'    => $timezone,
            'message'     => $healthy ? null : 'زمان‌بندی وظایف به‌درستی اجرا نمی‌شود.',
        ];
    }

    private function runChecks(): array
    {
        $checks = [];

        // Database
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['label' => 'PostgreSQL', 'ok' => true, 'detail' => 'متصل'];
        } catch (\Throwable $e) {
            $checks['database'] = ['label' => 'PostgreSQL', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Redis
        try {
            Cache::store('redis')->put('admin_ping', 'ok', 5);
            $checks['redis'] = ['label' => 'Redis', 'ok' => Cache::store('redis')->get('admin_ping') === 'ok', 'detail' => 'متصل'];
        } catch (\Throwable $e) {
            $checks['redis'] = ['label' => 'Redis', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Storage
        try {
            Storage::disk('local')->put('.admin_check', 'ok');
            Storage::disk('local')->delete('.admin_check');
            $checks['storage'] = ['label' => 'استوریج', 'ok' => true, 'detail' => 'قابل نوشتن'];
        } catch (\Throwable $e) {
            $checks['storage'] = ['label' => 'استوریج', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Queue
        $checks['queue'] = [
            'label'  => 'Queue',
            'ok'     => config('queue.default') === 'redis',
            'detail' => 'درایور: ' . config('queue.default'),
        ];

        // Cache
        $checks['cache'] = [
            'label'  => 'Cache',
            'ok'     => config('cache.default') === 'redis',
            'detail' => 'درایور: ' . config('cache.default'),
        ];

        return $checks;
    }
}
