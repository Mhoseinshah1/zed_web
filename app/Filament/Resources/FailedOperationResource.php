<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailedOperationResource\Pages;
use App\Filament\Support\UserAccountColumn;
use App\Models\Order;
use App\Services\Orders\OrderApplyRetryService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paid orders whose Marzban operation failed (provisioning / renewal / add-ons).
 * Payment stays paid; admins retry the application from here.
 */
class FailedOperationResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon   = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup   = 'سرویس‌ها و پنل‌های VPN';
    protected static ?string $navigationLabel   = 'عملیات‌های ناموفق';
    protected static ?string $modelLabel        = 'عملیات ناموفق';
    protected static ?string $pluralModelLabel  = 'عملیات‌های ناموفق';
    protected static ?int    $navigationSort    = 50;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('status', Order::failedOperationStatuses());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereNull('failure_reviewed_at')->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('شماره سفارش')->searchable()->fontFamily('mono'),
                Tables\Columns\BadgeColumn::make('order_type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => Order::allOrderTypes()[$state] ?? $state),
                UserAccountColumn::make(),
                Tables\Columns\TextColumn::make('user.phone')->label('شماره موبایل')->placeholder('—'),
                Tables\Columns\TextColumn::make('user_service_id')->label('شناسه سرویس')->placeholder('—'),
                Tables\Columns\TextColumn::make('final_price_toman')
                    ->label('مبلغ')->formatStateUsing(fn ($state) => number_format((int) $state) . ' تومان'),
                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => Order::allStatuses()[$state] ?? $state)
                    ->badge()->color('danger'),
                Tables\Columns\TextColumn::make('failure_reason')
                    ->label('دلیل خطا')
                    ->getStateUsing(fn (Order $record) => $record->failureReason())
                    ->limit(50)->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت')->dateTime('Y/m/d H:i')->sortable(),
                Tables\Columns\TextColumn::make('last_retry_at')->label('آخرین تلاش')->dateTime('Y/m/d H:i')->placeholder('—'),
                Tables\Columns\IconColumn::make('failure_reviewed_at')
                    ->label('بررسی‌شده')->boolean()
                    ->getStateUsing(fn (Order $record) => $record->failure_reviewed_at !== null),
            ])
            ->filters([
                SelectFilter::make('order_type')->label('نوع عملیات')->options(Order::allOrderTypes()),
                SelectFilter::make('status')->label('وضعیت')->options(collect(Order::failedOperationStatuses())
                    ->mapWithKeys(fn ($s) => [$s => Order::allStatuses()[$s] ?? $s])->all()),
                Tables\Filters\Filter::make('unreviewed')->label('بررسی‌نشده')
                    ->query(fn (Builder $q) => $q->whereNull('failure_reviewed_at')),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('تلاش مجدد')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('پرداخت قبلاً انجام شده است؛ این عملیات فقط اعمال سرویس را دوباره تلاش می‌کند.')
                    ->action(function (Order $record) {
                        try {
                            $applied = app(OrderApplyRetryService::class)->retry($record);
                            if ($applied) {
                                Notification::make()->title('عملیات با موفقیت اعمال شد.')->success()->send();
                            } else {
                                Notification::make()->title('عملیات هنوز اعمال نشد. دوباره بررسی کنید.')->warning()->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در تلاش مجدد')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('mark_reviewed')
                    ->label('علامت‌گذاری بررسی‌شده')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (Order $record) => $record->failure_reviewed_at === null)
                    ->action(function (Order $record) {
                        $record->update(['failure_reviewed_at' => now()]);
                        Notification::make()->title('بررسی‌شده شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('open_order')
                    ->label('سفارش')
                    ->icon('heroicon-o-shopping-cart')
                    ->url(fn (Order $record) => OrderResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('open_user')
                    ->label('کاربر')
                    ->icon('heroicon-o-user')
                    ->visible(fn (Order $record) => $record->user_id !== null)
                    ->url(fn (Order $record) => UserResource::getUrl('edit', ['record' => $record->user_id]))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailedOperations::route('/'),
        ];
    }
}
