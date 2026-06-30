<?php

namespace App\Filament\Pages;

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

    public function mount(): void
    {
        $this->checks = $this->runChecks();
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
