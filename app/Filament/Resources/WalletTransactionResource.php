<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon   = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup  = 'مالی';
    protected static ?string $navigationLabel  = 'تراکنش‌های کیف پول';
    protected static ?string $modelLabel       = 'تراکنش کیف پول';
    protected static ?string $pluralModelLabel = 'تراکنش‌های کیف پول';
    protected static ?int    $navigationSort   = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع تراکنش')
                    ->formatStateUsing(fn ($state) => WalletTransaction::allTypes()[$state] ?? $state)
                    ->colors([
                        'success' => [WalletTransaction::TYPE_MANUAL_CREDIT, WalletTransaction::TYPE_REFUND, WalletTransaction::TYPE_TOPUP],
                        'danger'  => [WalletTransaction::TYPE_MANUAL_DEBIT, WalletTransaction::TYPE_ORDER_PAYMENT],
                        'warning' => [WalletTransaction::TYPE_ADJUSTMENT],
                    ]),

                Tables\Columns\BadgeColumn::make('direction')
                    ->label('جهت')
                    ->formatStateUsing(fn ($state) => $state === 'credit' ? 'واریز' : 'برداشت')
                    ->colors([
                        'success' => ['credit'],
                        'danger'  => ['debit'],
                    ]),

                Tables\Columns\TextColumn::make('amount_toman')
                    ->label('مبلغ (تومان)')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) =>
                        ($record->direction === 'credit' ? '+' : '-') . number_format($state)),

                Tables\Columns\TextColumn::make('balance_before_toman')
                    ->label('موجودی قبل')
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('balance_after_toman')
                    ->label('موجودی بعد')
                    ->formatStateUsing(fn ($state) => number_format($state)),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'completed' => 'موفق',
                        'pending'   => 'در انتظار',
                        'failed'    => 'ناموفق',
                        default     => $state,
                    })
                    ->colors([
                        'success' => ['completed'],
                        'warning' => ['pending'],
                        'danger'  => ['failed'],
                    ]),

                Tables\Columns\TextColumn::make('description')
                    ->label('توضیحات')
                    ->limit(45)
                    ->default('—'),

                Tables\Columns\TextColumn::make('admin.username')
                    ->label('ادمین')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('نوع تراکنش')
                    ->options(WalletTransaction::allTypes()),

                SelectFilter::make('direction')
                    ->label('جهت')
                    ->options([
                        'credit' => 'واریز (افزایش موجودی)',
                        'debit'  => 'برداشت',
                    ]),

                SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'completed' => 'موفق',
                        'pending'   => 'در انتظار',
                        'failed'    => 'ناموفق',
                    ]),

                Filter::make('topup_only')
                    ->label('فقط شارژ کیف پول')
                    ->query(fn (Builder $q) => $q->where('type', WalletTransaction::TYPE_TOPUP)),

                Filter::make('order_payment_only')
                    ->label('فقط پرداخت سفارش')
                    ->query(fn (Builder $q) => $q->where('type', WalletTransaction::TYPE_ORDER_PAYMENT)),

                Filter::make('admin_only')
                    ->label('فقط تغییرات ادمین')
                    ->query(fn (Builder $q) => $q->whereIn('type', [
                        WalletTransaction::TYPE_MANUAL_CREDIT,
                        WalletTransaction::TYPE_MANUAL_DEBIT,
                        WalletTransaction::TYPE_ADJUSTMENT,
                    ])),

                Filter::make('today')
                    ->label('امروز')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', today())),

                Filter::make('this_month')
                    ->label('این ماه')
                    ->query(fn (Builder $q) => $q->whereBetween('created_at', [
                        now()->startOfMonth(),
                        now()->endOfMonth(),
                    ])),

                Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ'),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    })
                    ->label('بازه تاریخ'),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
        ];
    }
}
