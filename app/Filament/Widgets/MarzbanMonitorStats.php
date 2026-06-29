<?php

namespace App\Filament\Widgets;

use App\Models\UserService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Monitoring KPIs — computed from cached database values only (no API calls).
 */
class MarzbanMonitorStats extends BaseWidget
{
    protected function getStats(): array
    {
        $active   = UserService::where('status', UserService::STATUS_ACTIVE)->count();
        $expired  = UserService::where(fn ($q) => $q->where('status', UserService::STATUS_EXPIRED)
            ->orWhere(fn ($q2) => $q2->whereNotNull('expires_at')->where('expires_at', '<', now())))->count();
        $synced   = UserService::where('sync_status', UserService::SYNC_SYNCED)->count();
        $failed   = UserService::where('sync_status', UserService::SYNC_FAILED)->count();
        $notFound = UserService::where('sync_status', UserService::SYNC_NOT_FOUND)->count();
        $lastSync = UserService::max('last_synced_at');

        return [
            Stat::make('سرویس‌های فعال', number_format($active)),
            Stat::make('سرویس‌های منقضی‌شده', number_format($expired)),
            Stat::make('سرویس‌های سینک‌شده', number_format($synced)),
            Stat::make('خطاهای سینک', number_format($failed))->color($failed > 0 ? 'danger' : 'gray'),
            Stat::make('پیدا نشده در Marzban', number_format($notFound))->color($notFound > 0 ? 'warning' : 'gray'),
            Stat::make('آخرین زمان سینک', $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('Y/m/d H:i') : '—'),
        ];
    }
}
