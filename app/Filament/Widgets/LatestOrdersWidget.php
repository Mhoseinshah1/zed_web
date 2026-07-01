<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * «آخرین سفارش‌ها» — the latest orders as a compact table on the dashboard.
 * The user relation is eager-loaded and the set is capped at 8 rows, so there is
 * no N+1 and no full-table scan. Read-only; it links to the Orders resource.
 */
class LatestOrdersWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 4];

    protected static ?string $heading = 'آخرین سفارش‌ها';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()->with('user:id,username,account_id')->latest()->limit(8)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->description(fn (Order $r) => $r->user?->account_id)
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('plan_name')
                    ->label('پلن')
                    ->limit(24)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('final_price_toman')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . ' تومان'),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Order::allPaymentStatuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Order::PAYMENT_PAID     => 'success',
                        Order::PAYMENT_PENDING  => 'warning',
                        Order::PAYMENT_FAILED   => 'danger',
                        Order::PAYMENT_REFUNDED => 'info',
                        default                 => 'gray',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('مشاهده')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Order $r) => \App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $r]))
                    ->color('gray'),
            ]);
    }
}
