<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentTransactionResource\Pages;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Http\Controllers\CentralPayController;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Payments\NowPayments\NowPaymentsClient;
use App\Services\PaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.order_number')
                    ->label('سفارش')
                    ->searchable()
                    ->fontFamily('mono')
                    ->default('—'),

                Tables\Columns\BadgeColumn::make('provider')
                    ->label('درگاه')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'nowpayments' => 'NOWPayments',
                        'centralpay'  => 'CentralPay',
                        'manual'      => 'دستی',
                        default       => $state ?? '—',
                    })
                    ->colors([
                        'warning' => ['nowpayments'],
                        'success' => ['centralpay'],
                        'gray'    => ['manual'],
                    ]),

                Tables\Columns\BadgeColumn::make('payment_purpose')
                    ->label('نوع پرداخت')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'order_payment' => 'خرید سرویس',
                        'wallet_topup'  => 'شارژ کیف پول',
                        default         => $state ?? '—',
                    })
                    ->colors([
                        'success' => ['order_payment'],
                        'info'    => ['wallet_topup'],
                    ]),

                Tables\Columns\TextColumn::make('amount_toman')
                    ->label('مبلغ (تومان)')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('currency')
                    ->label('ارز')
                    ->default('IRT')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => PaymentTransaction::allStatuses()[$state] ?? $state)
                    ->colors([
                        'warning' => ['pending', 'submitted', 'waiting', 'confirming'],
                        'success' => ['approved'],
                        'info'    => ['partially_paid'],
                        'danger'  => ['rejected', 'failed', 'cancelled', 'expired', 'refunded'],
                    ]),

                Tables\Columns\TextColumn::make('gateway_status')
                    ->label('وضعیت درگاه')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('transaction_reference')
                    ->label('شناسه پیگیری')
                    ->limit(20)
                    ->default('—')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('تاریخ پرداخت')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(PaymentTransaction::allStatuses()),

                SelectFilter::make('provider')
                    ->label('درگاه')
                    ->options([
                        'centralpay'  => 'CentralPay',
                        'nowpayments' => 'NOWPayments',
                        'manual'      => 'دستی',
                    ]),

                SelectFilter::make('payment_purpose')
                    ->label('نوع پرداخت')
                    ->options([
                        'order_payment' => 'خرید سرویس',
                        'wallet_topup'  => 'شارژ کیف پول',
                    ]),

                Filter::make('paid_only')
                    ->label('فقط پرداخت‌های موفق')
                    ->query(fn (Builder $q) => $q->where('status', PaymentTransaction::STATUS_APPROVED)),

                Filter::make('failed_only')
                    ->label('فقط پرداخت‌های ناموفق')
                    ->query(fn (Builder $q) => $q->whereIn('status', [
                        PaymentTransaction::STATUS_FAILED,
                        PaymentTransaction::STATUS_REJECTED,
                        PaymentTransaction::STATUS_EXPIRED,
                        PaymentTransaction::STATUS_CANCELLED,
                    ])),

                Filter::make('pending_only')
                    ->label('فقط در انتظار')
                    ->query(fn (Builder $q) => $q->whereIn('status', [
                        PaymentTransaction::STATUS_PENDING,
                        PaymentTransaction::STATUS_SUBMITTED,
                        PaymentTransaction::STATUS_WAITING,
                        PaymentTransaction::STATUS_CONFIRMING,
                    ])),

                Filter::make('today')
                    ->label('امروز')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', today())),

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

                SelectFilter::make('payment_method_id')
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
                        Forms\Components\Textarea::make('admin_note')->label('یادداشت ادمین (اختیاری)')->rows(3),
                    ])
                    ->action(function (PaymentTransaction $record, array $data) {
                        try {
                            app(PaymentService::class)->approveTransaction(
                                $record,
                                auth()->id(),
                                $data['admin_note'] ?? null
                            );
                            Notification::make()->title('تراکنش با موفقیت تایید شد.')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تایید پرداخت')
                    ->modalDescription('آیا از تایید این پرداخت مطمئن هستید؟ سفارش مربوطه پرداخت‌شده می‌شود.')
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
                        Forms\Components\Textarea::make('admin_note')->label('دلیل رد (اختیاری)')->rows(3),
                    ])
                    ->action(function (PaymentTransaction $record, array $data) {
                        try {
                            app(PaymentService::class)->rejectTransaction(
                                $record,
                                auth()->id(),
                                $data['admin_note'] ?? null
                            );
                            Notification::make()->title('تراکنش رد شد.')->warning()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('رد پرداخت')
                    ->modalDescription('آیا از رد این پرداخت مطمئن هستید؟')
                    ->modalSubmitActionLabel('بله، رد کن'),

                Tables\Actions\Action::make('nowpayments_check')
                    ->label('بررسی NOWPayments')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (PaymentTransaction $record) => $record->provider === 'nowpayments' && $record->provider_reference !== null)
                    ->action(function (PaymentTransaction $record) {
                        $method = PaymentMethod::where('type', PaymentMethod::TYPE_NOWPAYMENTS)->where('is_active', true)->first();
                        if (! $method) {
                            Notification::make()->title('روش پرداخت NOWPayments فعال یافت نشد.')->danger()->send();
                            return;
                        }
                        try {
                            $client        = new NowPaymentsClient($method);
                            $status        = $client->getPaymentStatus($record->provider_reference);
                            $gatewayStatus = strtolower($status['payment_status'] ?? '');
                            $record->update([
                                'gateway_status'   => $gatewayStatus,
                                'response_payload' => collect($status)->except(['api_key', 'ipn_secret'])->all(),
                            ]);
                            if ($gatewayStatus === 'finished') {
                                app(MarkOrderAsPaidService::class)->markPaid($record->order, $record);
                                Notification::make()->title('پرداخت تایید شد و سفارش پردازش شد.')->success()->send();
                            } else {
                                Notification::make()->title("وضعیت درگاه: {$gatewayStatus}")->info()->send();
                            }
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('خطا: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('view_gateway_response')
                    ->label('پاسخ درگاه')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (PaymentTransaction $record) => $record->provider === 'nowpayments' && $record->response_payload !== null)
                    ->modalContent(fn (PaymentTransaction $record) => view('filament.nowpayments-payload-modal', [
                        'payload'     => $record->response_payload,
                        'gateway_url' => $record->gateway_url,
                    ]))
                    ->modalHeading('پاسخ NOWPayments')
                    ->modalSubmitAction(false),

                Tables\Actions\Action::make('centralpay_check')
                    ->label('بررسی CentralPay')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (PaymentTransaction $record) => $record->provider === 'centralpay'
                        && ! in_array($record->gateway_status, ['verified', 'amount_mismatch', 'user_mismatch'])
                        && $record->order->payment_status !== \App\Models\Order::PAYMENT_PAID)
                    ->action(function (PaymentTransaction $record) {
                        try {
                            CentralPayController::adminVerify($record, app(MarkOrderAsPaidService::class));
                            Notification::make()->title('پرداخت CentralPay تایید شد و سفارش پردازش شد.')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('بررسی وضعیت پرداخت CentralPay')
                    ->modalDescription('آیا می‌خواهید وضعیت این پرداخت را از CentralPay بررسی کنید؟')
                    ->modalSubmitActionLabel('بله، بررسی کن'),

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
