<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentTransactionResource\Pages;
use App\Models\PaymentTransaction;
use App\Services\PaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

                Forms\Components\TextInput::make('paymentMethod.title')
                    ->label('روش پرداخت')
                    ->disabled(),

                Forms\Components\TextInput::make('amount_toman')
                    ->label('مبلغ (تومان)')
                    ->disabled(),

                Forms\Components\TextInput::make('transaction_reference')
                    ->label('کد تراکنش / TXID')
                    ->disabled(),

                Forms\Components\Select::make('status')
                    ->label('وضعیت')
                    ->options(PaymentTransaction::allStatuses())
                    ->disabled(),
            ])->columns(3),

            Forms\Components\Section::make('توضیحات')->schema([
                Forms\Components\Textarea::make('user_note')
                    ->label('توضیح کاربر')
                    ->disabled()
                    ->rows(2),

                Forms\Components\Textarea::make('admin_note')
                    ->label('یادداشت ادمین')
                    ->rows(2),
            ])->columns(2),

            Forms\Components\Section::make('بررسی')->schema([
                Forms\Components\TextInput::make('reviewer.username')
                    ->label('بررسی‌کننده')
                    ->disabled(),

                Forms\Components\DateTimePicker::make('reviewed_at')
                    ->label('تاریخ بررسی')
                    ->disabled(),

                Forms\Components\DateTimePicker::make('rejected_at')
                    ->label('تاریخ رد')
                    ->disabled(),
            ])->columns(3)->collapsible(),
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

                Tables\Columns\TextColumn::make('paymentMethod.title')
                    ->label('روش پرداخت')
                    ->default('—'),

                Tables\Columns\TextColumn::make('amount_toman')
                    ->label('مبلغ (تومان)')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('transaction_reference')
                    ->label('TXID')
                    ->limit(20)
                    ->default('—')
                    ->fontFamily('mono'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => PaymentTransaction::allStatuses()[$state] ?? $state)
                    ->colors([
                        'warning' => ['pending', 'submitted'],
                        'success' => ['approved'],
                        'danger'  => ['rejected', 'failed', 'cancelled'],
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(PaymentTransaction::allStatuses()),

                Tables\Filters\SelectFilter::make('payment_method_id')
                    ->label('روش پرداخت')
                    ->relationship('paymentMethod', 'title'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('تایید')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PaymentTransaction $record) => in_array($record->status, [
                        PaymentTransaction::STATUS_PENDING,
                        PaymentTransaction::STATUS_SUBMITTED,
                    ]))
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('یادداشت ادمین (اختیاری)')
                            ->rows(3),
                    ])
                    ->action(function (PaymentTransaction $record, array $data) {
                        try {
                            app(PaymentService::class)->approveTransaction(
                                $record,
                                auth()->id(),
                                $data['admin_note'] ?? null
                            );
                            Notification::make()
                                ->title('تراکنش با موفقیت تایید شد.')
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تایید پرداخت')
                    ->modalDescription('آیا از تایید این پرداخت مطمئن هستید؟ سفارش مربوطه به حالت پرداخت شده تغییر می‌کند.')
                    ->modalSubmitActionLabel('بله، تایید کن'),

                Tables\Actions\Action::make('reject')
                    ->label('رد')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PaymentTransaction $record) => in_array($record->status, [
                        PaymentTransaction::STATUS_PENDING,
                        PaymentTransaction::STATUS_SUBMITTED,
                    ]))
                    ->form([
                        Forms\Components\Textarea::make('admin_note')
                            ->label('دلیل رد (اختیاری)')
                            ->rows(3),
                    ])
                    ->action(function (PaymentTransaction $record, array $data) {
                        try {
                            app(PaymentService::class)->rejectTransaction(
                                $record,
                                auth()->id(),
                                $data['admin_note'] ?? null
                            );
                            Notification::make()
                                ->title('تراکنش رد شد.')
                                ->warning()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('رد پرداخت')
                    ->modalDescription('آیا از رد این پرداخت مطمئن هستید؟')
                    ->modalSubmitActionLabel('بله، رد کن'),

                Tables\Actions\EditAction::make()->label('جزئیات'),
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
