<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Renewals\RenewalService;
use App\Services\ServiceProvisioner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon   = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup  = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel  = 'سفارش‌ها';
    protected static ?string $modelLabel       = 'سفارش';
    protected static ?string $pluralModelLabel = 'سفارش‌ها';
    protected static ?int    $navigationSort   = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات پلن (اسنپشات)')
                ->description('این اطلاعات در زمان ثبت سفارش ذخیره شده و نباید تغییر کنند.')
                ->schema([
                    Forms\Components\TextInput::make('order_number')
                        ->label('شماره سفارش')
                        ->disabled(),
                    Forms\Components\TextInput::make('plan_name')
                        ->label('نام پلن')
                        ->disabled(),
                    Forms\Components\TextInput::make('traffic_gb')
                        ->label('حجم (GB)')
                        ->disabled(),
                    Forms\Components\TextInput::make('duration_days')
                        ->label('مدت (روز)')
                        ->disabled(),
                    Forms\Components\TextInput::make('price_toman')
                        ->label('قیمت اصلی (تومان)')
                        ->disabled(),
                    Forms\Components\TextInput::make('final_price_toman')
                        ->label('قیمت نهایی (تومان)')
                        ->disabled(),
                ])->columns(3)->collapsible(),

            Forms\Components\Section::make('وضعیت سفارش')->schema([
                Forms\Components\Select::make('status')
                    ->label('وضعیت سفارش')
                    ->options(Order::allStatuses())
                    ->required(),

                Forms\Components\Select::make('payment_status')
                    ->label('وضعیت پرداخت')
                    ->options(Order::allPaymentStatuses())
                    ->required(),

                Forms\Components\DateTimePicker::make('paid_at')
                    ->label('تاریخ پرداخت'),

                Forms\Components\DateTimePicker::make('completed_at')
                    ->label('تاریخ تکمیل'),

                Forms\Components\DateTimePicker::make('cancelled_at')
                    ->label('تاریخ لغو'),
            ])->columns(3),

            Forms\Components\Section::make('یادداشت‌ها')->schema([
                Forms\Components\Textarea::make('notes')
                    ->label('یادداشت کاربر')
                    ->disabled()
                    ->rows(3),
                Forms\Components\Textarea::make('admin_notes')
                    ->label('یادداشت ادمین')
                    ->rows(3),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('order_number')
                    ->label('شناسه سفارش')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\BadgeColumn::make('order_type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => Order::allOrderTypes()[$state] ?? $state)
                    ->colors([
                        'info'    => Order::TYPE_NEW_SERVICE,
                        'success' => Order::TYPE_RENEWAL,
                        'warning' => Order::TYPE_EXTRA_TRAFFIC,
                        'primary' => Order::TYPE_EXTRA_TIME,
                    ]),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('final_price_toman')
                    ->label('مبلغ (تومان)')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_provider')
                    ->label('روش پرداخت')
                    ->getStateUsing(function (Order $record): string {
                        $tx = $record->paymentTransactions()
                            ->whereNotIn('status', ['failed', 'cancelled', 'rejected', 'expired'])
                            ->orderByDesc('id')
                            ->first();
                        if (! $tx) {
                            return '—';
                        }
                        return match ($tx->provider) {
                            'nowpayments' => 'NOWPayments',
                            'centralpay'  => 'CentralPay',
                            'manual'      => 'دستی',
                            default       => 'کیف پول',
                        };
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'NOWPayments' => 'warning',
                        'CentralPay'  => 'success',
                        'دستی'        => 'gray',
                        'کیف پول'     => 'info',
                        default       => 'gray',
                    }),

                Tables\Columns\BadgeColumn::make('payment_status')
                    ->label('پرداخت')
                    ->formatStateUsing(fn ($state) => Order::allPaymentStatuses()[$state] ?? $state)
                    ->colors([
                        'gray'    => ['unpaid'],
                        'warning' => ['pending'],
                        'success' => ['paid'],
                        'danger'  => ['failed'],
                        'info'    => ['refunded'],
                    ]),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت سفارش')
                    ->formatStateUsing(fn ($state) => Order::allStatuses()[$state] ?? $state)
                    ->colors([
                        'gray'    => ['pending', 'awaiting_payment'],
                        'warning' => ['paid', 'processing', 'provisioning'],
                        'success' => ['completed'],
                        'danger'  => ['provisioning_failed', 'cancelled', 'failed'],
                    ]),

                Tables\Columns\IconColumn::make('service_exists')
                    ->label('سرویس')
                    ->getStateUsing(fn (Order $record): bool => $record->service !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('تاریخ پرداخت')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('وضعیت سفارش')
                    ->options(Order::allStatuses()),

                SelectFilter::make('payment_status')
                    ->label('وضعیت پرداخت')
                    ->options(Order::allPaymentStatuses()),

                Filter::make('paid_today')
                    ->label('پرداخت‌شده امروز')
                    ->query(fn (Builder $query) => $query
                        ->where('payment_status', Order::PAYMENT_PAID)
                        ->whereDate('paid_at', today())),

                Filter::make('paid_this_month')
                    ->label('پرداخت‌شده این ماه')
                    ->query(fn (Builder $query) => $query
                        ->where('payment_status', Order::PAYMENT_PAID)
                        ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])),

                Filter::make('provisioning_failed')
                    ->label('خطا در ساخت سرویس')
                    ->query(fn (Builder $query) => $query->where('status', Order::STATUS_PROVISIONING_FAILED)),

                Filter::make('renewal_orders')
                    ->label('سفارش‌های تمدید')
                    ->query(fn (Builder $query) => $query->where('order_type', Order::TYPE_RENEWAL)),

                Filter::make('renewal_failed')
                    ->label('خطا در تمدید')
                    ->query(fn (Builder $query) => $query->where('status', Order::STATUS_RENEWAL_FAILED)),

                Filter::make('extra_traffic_orders')
                    ->label('خرید حجم اضافه')
                    ->query(fn (Builder $query) => $query->where('order_type', Order::TYPE_EXTRA_TRAFFIC)),

                Filter::make('extra_time_orders')
                    ->label('خرید زمان اضافه')
                    ->query(fn (Builder $query) => $query->where('order_type', Order::TYPE_EXTRA_TIME)),

                Filter::make('addon_failed')
                    ->label('خطا در اعمال حجم/زمان اضافه')
                    ->query(fn (Builder $query) => $query->where('status', Order::STATUS_ADDON_FAILED)),

                Filter::make('paid_without_service')
                    ->label('پرداخت‌شده بدون سرویس')
                    ->query(fn (Builder $query) => $query
                        ->where('payment_status', Order::PAYMENT_PAID)
                        ->whereDoesntHave('service')
                        ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED])),

                SelectFilter::make('provider')
                    ->label('درگاه پرداخت')
                    ->options([
                        'nowpayments' => 'NOWPayments',
                        'centralpay'  => 'CentralPay',
                        'manual'      => 'دستی',
                    ])
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereHas('paymentTransactions', fn ($q) => $q->where('provider', $data['value']))
                        : $query),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('از تاریخ'),
                        Forms\Components\DatePicker::make('created_until')->label('تا تاریخ'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['created_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['created_until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    })
                    ->label('بازه تاریخ'),
            ])
            ->actions([
                Tables\Actions\Action::make('retry_provisioning')
                    ->label('تلاش مجدد ساخت سرویس')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(function (Order $record) {
                        $isRetryable = in_array($record->status, [
                            Order::STATUS_PAID,
                            Order::STATUS_PROVISIONING,
                            Order::STATUS_PROVISIONING_FAILED,
                        ]) && $record->payment_status === Order::PAYMENT_PAID;

                        $hasActiveService = $record->service
                            && $record->service->status === \App\Models\UserService::STATUS_ACTIVE;

                        return $isRetryable && ! $hasActiveService;
                    })
                    ->action(function (Order $record) {
                        try {
                            app(ProvisioningService::class)->provisionOrder($record, true);
                            Notification::make()->title('سرویس با موفقیت ساخته شد.')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('ساخت سرویس دوباره با خطا مواجه شد.')->body($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تلاش مجدد برای ساخت سرویس')
                    ->modalDescription('آیا می‌خواهید دوباره تلاش کنید؟')
                    ->modalSubmitActionLabel('بله، دوباره تلاش کن'),

                Tables\Actions\Action::make('view_provisioning_logs')
                    ->label('لاگ ساخت سرویس')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->modalContent(function (Order $record) {
                        $attempts = ProvisioningAttempt::where('order_id', $record->id)
                            ->orderByDesc('attempt_number')
                            ->get();
                        return view('filament.modals.provisioning-attempts', compact('attempts'));
                    })
                    ->modalHeading('لاگ‌های ساخت سرویس')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن'),

                Tables\Actions\Action::make('mark_processing')
                    ->label('در حال پردازش')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('info')
                    ->visible(fn (Order $record) => $record->status === Order::STATUS_PAID)
                    ->action(function (Order $record) {
                        $record->update(['status' => Order::STATUS_PROCESSING]);
                        Notification::make()->title('سفارش به حالت در حال پردازش تغییر یافت.')->info()->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تغییر وضعیت به در حال پردازش')
                    ->modalSubmitActionLabel('تایید'),

                Tables\Actions\Action::make('mark_completed')
                    ->label('تکمیل شده')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Order $record) => $record->status === Order::STATUS_PROCESSING)
                    ->action(function (Order $record) {
                        $record->update(['status' => Order::STATUS_COMPLETED, 'completed_at' => now()]);
                        Notification::make()->title('سفارش تکمیل شد.')->success()->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تکمیل سفارش')
                    ->modalSubmitActionLabel('تایید'),

                Tables\Actions\Action::make('cancel_order')
                    ->label('لغو')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record) => ! in_array($record->status, [
                        Order::STATUS_COMPLETED,
                        Order::STATUS_CANCELLED,
                        Order::STATUS_FAILED,
                    ]))
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')->label('دلیل لغو (اختیاری)')->rows(3),
                    ])
                    ->action(function (Order $record, array $data) {
                        $record->update([
                            'status'       => Order::STATUS_CANCELLED,
                            'cancelled_at' => now(),
                            'admin_notes'  => $data['admin_notes'] ?? $record->admin_notes,
                        ]);
                        Notification::make()->title('سفارش لغو شد.')->warning()->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('لغو سفارش')
                    ->modalSubmitActionLabel('بله، لغو کن'),

                Tables\Actions\Action::make('create_service')
                    ->label('ایجاد سرویس')
                    ->icon('heroicon-o-plus-circle')
                    ->color('info')
                    ->visible(fn (Order $record) => in_array($record->status, [
                        Order::STATUS_PAID,
                        Order::STATUS_PROCESSING,
                        Order::STATUS_COMPLETED,
                    ]) && $record->service === null)
                    ->action(function (Order $record) {
                        try {
                            app(ServiceProvisioner::class)->createFromOrder($record);
                            Notification::make()->title('سرویس با موفقیت ایجاد شد.')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('ایجاد سرویس برای این سفارش')
                    ->modalDescription('آیا می‌خواهید یک سرویس جدید برای این سفارش ایجاد کنید؟')
                    ->modalSubmitActionLabel('بله، ایجاد کن'),

                Tables\Actions\Action::make('retry_renewal')
                    ->label('تلاش مجدد تمدید')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Order $record) => $record->order_type === Order::TYPE_RENEWAL
                        && $record->status === Order::STATUS_RENEWAL_FAILED
                        && $record->payment_status === Order::PAYMENT_PAID)
                    ->action(function (Order $record) {
                        try {
                            app(RenewalService::class)->applyRenewal($record);
                            Notification::make()->title('تمدید با موفقیت اعمال شد.')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('خطا در تلاش مجدد تمدید')->body($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تلاش مجدد برای اعمال تمدید')
                    ->modalDescription('آیا می‌خواهید دوباره تلاش کنید؟ پرداخت قبلاً انجام شده است.')
                    ->modalSubmitActionLabel('بله، دوباره تلاش کن'),

                Tables\Actions\Action::make('retry_addon')
                    ->label('تلاش مجدد برای اعمال تغییرات سرویس')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Order $record) => $record->isAddon()
                        && $record->status === Order::STATUS_ADDON_FAILED
                        && $record->payment_status === Order::PAYMENT_PAID)
                    ->action(function (Order $record) {
                        try {
                            $addon = app(\App\Services\Addons\ServiceAddonService::class);
                            if ($record->order_type === Order::TYPE_EXTRA_TRAFFIC) {
                                $addon->applyExtraTraffic($record);
                            } else {
                                $addon->applyExtraTime($record);
                            }
                            Notification::make()->title('تغییرات سرویس با موفقیت اعمال شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در اعمال تغییرات سرویس')->body($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تلاش مجدد برای اعمال تغییرات سرویس')
                    ->modalDescription('آیا می‌خواهید دوباره تلاش کنید؟ پرداخت قبلاً انجام شده است.')
                    ->modalSubmitActionLabel('بله، دوباره تلاش کن'),

                Tables\Actions\EditAction::make()->label('ویرایش'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit'  => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
