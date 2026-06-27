<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\WalletTransaction;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon   = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup  = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel  = 'تراکنش‌های کیف پول';
    protected static ?string $modelLabel       = 'تراکنش کیف پول';
    protected static ?string $pluralModelLabel = 'تراکنش‌های کیف پول';
    protected static ?int    $navigationSort   = 5;

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
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => WalletTransaction::allTypes()[$state] ?? $state)
                    ->colors([
                        'success' => ['manual_credit', 'refund'],
                        'danger'  => ['manual_debit', 'order_payment'],
                        'warning' => ['adjustment'],
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
                        ($record->direction === 'credit' ? '+' : '-') . number_format($state)
                    ),

                Tables\Columns\TextColumn::make('balance_after_toman')
                    ->label('موجودی بعد (تومان)')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format($state)),

                Tables\Columns\TextColumn::make('description')
                    ->label('توضیحات')
                    ->limit(40)
                    ->default('—'),

                Tables\Columns\TextColumn::make('admin.username')
                    ->label('ادمین')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(WalletTransaction::allTypes()),

                Tables\Filters\SelectFilter::make('direction')
                    ->label('جهت')
                    ->options([
                        'credit' => 'واریز',
                        'debit'  => 'برداشت',
                    ]),
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
