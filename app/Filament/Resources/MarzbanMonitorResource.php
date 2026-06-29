<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarzbanMonitorResource\Pages;
use App\Filament\Support\UserAccountColumn;
use App\Models\UserService;
use App\Services\Marzban\UserServiceSyncService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-mostly monitoring view of UserService ↔ Marzban sync state.
 *
 * The page never auto-syncs on load — it shows cached database values. Syncing
 * only happens when an admin clicks an explicit action.
 */
class MarzbanMonitorResource extends Resource
{
    protected static ?string $model = UserService::class;

    protected static ?string $navigationIcon   = 'heroicon-o-signal';
    protected static ?string $navigationGroup   = 'سرویس‌ها';
    protected static ?string $navigationLabel   = 'مانیتورینگ Marzban';
    protected static ?string $modelLabel        = 'سرویس Marzban';
    protected static ?string $pluralModelLabel  = 'مانیتورینگ Marzban';
    protected static ?int    $navigationSort    = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('شناسه سرویس')->sortable(),
                UserAccountColumn::make(),
                Tables\Columns\TextColumn::make('user.phone')->label('شماره موبایل')->placeholder('—'),
                Tables\Columns\TextColumn::make('remote_username')
                    ->label('نام کاربری Marzban')->fontFamily('mono')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('vpnPanel.name')->label('پنل')->placeholder('—'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت محلی')
                    ->formatStateUsing(fn ($state) => UserService::allStatuses()[$state] ?? $state),
                Tables\Columns\TextColumn::make('marzban_status')->label('وضعیت Marzban')->placeholder('—'),
                Tables\Columns\TextColumn::make('traffic_used_gb')->label('مصرف (GB)')->default(0),
                Tables\Columns\TextColumn::make('traffic_total_gb')->label('حجم کل (GB)')->default('نامحدود'),
                Tables\Columns\TextColumn::make('expires_at')->label('انقضا')->dateTime('Y/m/d')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('آخرین سینک')->dateTime('Y/m/d H:i')->placeholder('—')->sortable(),
                Tables\Columns\BadgeColumn::make('sync_status')
                    ->label('وضعیت سینک')
                    ->formatStateUsing(fn ($state) => $state ? (UserService::allSyncStatuses()[$state] ?? $state) : '—')
                    ->colors([
                        'success' => [UserService::SYNC_SYNCED],
                        'danger'  => [UserService::SYNC_FAILED, UserService::SYNC_NOT_FOUND],
                        'warning' => [UserService::SYNC_PENDING],
                        'gray'    => [UserService::SYNC_DISABLED],
                    ]),
                Tables\Columns\TextColumn::make('sync_error')->label('خطای سینک')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Filter::make('failed_sync')->label('خطای سینک')
                    ->query(fn ($q) => $q->where('sync_status', UserService::SYNC_FAILED)),
                Filter::make('pending_sync')->label('در انتظار سینک')
                    ->query(fn ($q) => $q->where('sync_status', UserService::SYNC_PENDING)),
                Filter::make('not_found')->label('پیدا نشده در Marzban')
                    ->query(fn ($q) => $q->where('sync_status', UserService::SYNC_NOT_FOUND)),
                Filter::make('expired')->label('منقضی‌شده')
                    ->query(fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<', now())),
                Filter::make('active')->label('فعال')
                    ->query(fn ($q) => $q->where('status', UserService::STATUS_ACTIVE)),
                Filter::make('near_expiry')->label('نزدیک انقضا')
                    ->query(fn ($q) => $q->where('status', UserService::STATUS_ACTIVE)
                        ->whereNotNull('expires_at')
                        ->whereBetween('expires_at', [now(), now()->addDays(3)])),
                SelectFilter::make('vpn_panel_id')->label('پنل')->relationship('vpnPanel', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('سینک این سرویس')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function (UserService $record) {
                        app(UserServiceSyncService::class)->syncService($record);
                        Notification::make()->title('سینک انجام شد.')->success()->send();
                    }),
                Tables\Actions\Action::make('open_service')
                    ->label('باز کردن سرویس')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (UserService $record) => UserServiceResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('open_user')
                    ->label('کاربر')
                    ->icon('heroicon-o-user')
                    ->visible(fn (UserService $record) => $record->user_id !== null)
                    ->url(fn (UserService $record) => UserResource::getUrl('edit', ['record' => $record->user_id]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sync_selected')
                    ->label('سینک موارد انتخاب‌شده')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($records) {
                        app(UserServiceSyncService::class)->syncBatch($records);
                        Notification::make()->title('سینک موارد انتخاب‌شده انجام شد.')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarzbanMonitor::route('/'),
        ];
    }
}
