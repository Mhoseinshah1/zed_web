<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommissionResource\Pages;
use App\Models\Commission;
use App\Models\Order;
use App\Services\Referrals\CommissionService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommissionResource extends Resource
{
    protected static ?string $model = Commission::class;

    protected static ?string $navigationIcon   = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup   = 'بازاریابی';
    protected static ?string $navigationLabel   = 'پورسانت‌ها';
    protected static ?string $modelLabel        = 'پورسانت';
    protected static ?string $pluralModelLabel  = 'پورسانت‌ها';
    protected static ?int    $navigationSort    = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('representative.account_id')
                    ->label('نماینده')
                    ->fontFamily('mono')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('representative', fn ($u) => $u
                        ->where('account_id', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('referredUser.account_id')
                    ->label('کاربر معرفی‌شده')
                    ->fontFamily('mono')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('referredUser', fn ($u) => $u
                        ->where('account_id', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('order.order_number')->label('سفارش')->fontFamily('mono'),
                Tables\Columns\BadgeColumn::make('order_type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => Order::allOrderTypes()[$state] ?? $state),
                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('مبلغ پورسانت')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . ' تومان')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => Commission::statuses()[$state] ?? $state)
                    ->colors([
                        'success' => [Commission::STATUS_CREDITED],
                        'warning' => [Commission::STATUS_PENDING],
                        'danger'  => [Commission::STATUS_CANCELLED, Commission::STATUS_REVERSED],
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')->dateTime('Y/m/d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options(Commission::statuses()),
                SelectFilter::make('order_type')->label('نوع سفارش')->options(Order::allOrderTypes()),
                SelectFilter::make('representative_user_id')->label('نماینده')->relationship('representative', 'username')->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('retry_credit')
                    ->label('تلاش مجدد واریز')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Commission $r) => $r->status === Commission::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (Commission $r) {
                        app(CommissionService::class)->credit($r);
                        Notification::make()->title('تلاش برای واریز انجام شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('لغو پورسانت')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Commission $r) => $r->status === Commission::STATUS_PENDING)
                    ->form([\Filament\Forms\Components\Textarea::make('admin_note')->label('یادداشت')->rows(2)])
                    ->requiresConfirmation()
                    ->action(function (Commission $r, array $data) {
                        app(CommissionService::class)->cancel($r, $data['admin_note'] ?? null);
                        Notification::make()->title('پورسانت لغو شد.')->warning()->send();
                    }),

                Tables\Actions\Action::make('open_order')
                    ->label('سفارش')->icon('heroicon-o-shopping-cart')
                    ->url(fn (Commission $r) => OrderResource::getUrl('edit', ['record' => $r->order_id]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('open_representative')
                    ->label('نماینده')->icon('heroicon-o-user')
                    ->url(fn (Commission $r) => UserResource::getUrl('edit', ['record' => $r->representative_user_id]))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
        ];
    }
}
