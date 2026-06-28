<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\ServiceProvisioner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
            // Snapshot (read-only in edit)
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

            // Editable fields
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
                Tables\Columns\TextColumn::make('order_number')
                    ->label('شماره سفارش')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('plan_name')
                    ->label('پلن')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('final_price_toman')
                    ->label('مبلغ (تومان)')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('paymentTransactions.paymentMethod.title')
                    ->label('روش پرداخت')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => Order::allStatuses()[$state] ?? $state)
                    ->colors([
                        'gray'    => ['pending'],
                        'warning' => ['awaiting_payment'],
                        'info'    => ['paid', 'processing'],
                        'success' => ['completed'],
                        'danger'  => ['cancelled', 'failed'],
                    ]),

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

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت سفارش')
                    ->options(Order::allStatuses()),

                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('وضعیت پرداخت')
                    ->options(Order::allPaymentStatuses()),
            ])
            ->actions([
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
                        $record->update([
                            'status'       => Order::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
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
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('دلیل لغو (اختیاری)')
                            ->rows(3),
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
