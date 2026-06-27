<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentTransactionResource\Pages;
use App\Models\PaymentTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static ?string $navigationIcon   = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup  = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel  = 'تراکنش‌ها';
    protected static ?string $modelLabel       = 'تراکنش';
    protected static ?string $pluralModelLabel = 'تراکنش‌ها';
    protected static ?int    $navigationSort   = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات تراکنش')->schema([
                Forms\Components\TextInput::make('order.order_number')
                    ->label('شماره سفارش')
                    ->disabled(),

                Forms\Components\TextInput::make('user.username')
                    ->label('کاربر')
                    ->disabled(),

                Forms\Components\TextInput::make('amount_toman')
                    ->label('مبلغ (تومان)')
                    ->disabled(),

                Forms\Components\TextInput::make('provider')
                    ->label('درگاه')
                    ->disabled(),

                Forms\Components\TextInput::make('method')
                    ->label('روش پرداخت')
                    ->disabled(),

                Forms\Components\Select::make('status')
                    ->label('وضعیت')
                    ->options([
                        'pending'   => 'در انتظار',
                        'paid'      => 'پرداخت شده',
                        'failed'    => 'ناموفق',
                        'refunded'  => 'برگشت داده شده',
                        'cancelled' => 'لغو شده',
                    ]),

                Forms\Components\TextInput::make('reference_id')
                    ->label('شناسه مرجع')
                    ->disabled(),

                Forms\Components\TextInput::make('external_id')
                    ->label('شناسه خارجی')
                    ->disabled(),

                Forms\Components\DateTimePicker::make('paid_at')
                    ->label('تاریخ پرداخت'),
            ])->columns(3),

            Forms\Components\Section::make('Payload')->schema([
                Forms\Components\KeyValue::make('payload')
                    ->label('داده‌های خام')
                    ->disabled(),
            ])->collapsible()->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.order_number')
                    ->label('سفارش')
                    ->searchable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable(),

                Tables\Columns\TextColumn::make('provider')
                    ->label('درگاه')
                    ->default('—'),

                Tables\Columns\TextColumn::make('method')
                    ->label('روش')
                    ->default('—'),

                Tables\Columns\TextColumn::make('amount_toman')
                    ->label('مبلغ (تومان)')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => match($state) {
                        'pending'   => 'در انتظار',
                        'paid'      => 'پرداخت شده',
                        'failed'    => 'ناموفق',
                        'refunded'  => 'برگشت داده شده',
                        'cancelled' => 'لغو شده',
                        default     => $state,
                    })
                    ->colors([
                        'warning' => ['pending'],
                        'success' => ['paid'],
                        'danger'  => ['failed', 'cancelled'],
                        'info'    => ['refunded'],
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'pending'   => 'در انتظار',
                        'paid'      => 'پرداخت شده',
                        'failed'    => 'ناموفق',
                        'refunded'  => 'برگشت داده شده',
                        'cancelled' => 'لغو شده',
                    ]),

                Tables\Filters\SelectFilter::make('provider')
                    ->label('درگاه'),

                Tables\Filters\SelectFilter::make('method')
                    ->label('روش پرداخت'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentTransactions::route('/'),
            'edit'  => Pages\EditPaymentTransaction::route('/{record}/edit'),
        ];
    }
}
