<?php

namespace App\Filament\Resources\MarzbanMonitorResource\Pages;

use App\Filament\Resources\MarzbanMonitorResource;
use App\Filament\Widgets\MarzbanMonitorStats;
use App\Models\SiteSetting;
use App\Services\Marzban\UserServiceSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMarzbanMonitor extends ListRecords
{
    protected static string $resource = MarzbanMonitorResource::class;

    protected function getHeaderWidgets(): array
    {
        return [MarzbanMonitorStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_failed')
                ->label('سینک سرویس‌های ناموفق')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function () {
                    $limit = (int) SiteSetting::get('marzban_background_sync_batch_size', 50);
                    $count = app(UserServiceSyncService::class)->syncFailedServices($limit);
                    Notification::make()->title("{$count} سرویس سینک شد.")->success()->send();
                }),

            Actions\Action::make('sync_pending')
                ->label('سینک سرویس‌های در انتظار')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->action(function () {
                    $limit = (int) SiteSetting::get('marzban_background_sync_batch_size', 50);
                    $count = app(UserServiceSyncService::class)->syncPendingServices($limit);
                    Notification::make()->title("{$count} سرویس سینک شد.")->success()->send();
                }),

            Actions\Action::make('sync_batch')
                ->label('سینک دسته‌ای محدود')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\TextInput::make('limit')
                        ->label('حداکثر تعداد')->numeric()->default(50)->minValue(1),
                ])
                ->action(function (array $data) {
                    $limit = max(1, (int) ($data['limit'] ?? 50));
                    $svc   = app(UserServiceSyncService::class);
                    $count = $svc->syncFailedServices($limit) + $svc->syncPendingServices($limit);
                    Notification::make()->title("{$count} سرویس سینک شد.")->success()->send();
                }),
        ];
    }
}
