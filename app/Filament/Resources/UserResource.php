<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\SiteText;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel = 'کاربران';
    protected static ?string $modelLabel      = 'کاربر';
    protected static ?string $pluralModelLabel = 'کاربران';
    protected static ?int $navigationSort     = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('account_id')
                    ->label('شناسه اکانت')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('شناسه ۶ رقمی یکتا — غیرقابل تغییر'),

                Forms\Components\TextInput::make('username')
                    ->label('نام کاربری (برای ورود)')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(60)
                    ->regex('/^[a-zA-Z0-9_]+$/')
                    ->helperText('فقط حروف انگلیسی، اعداد و خط زیر مجاز است'),

                Forms\Components\TextInput::make('name')
                    ->label('نام نمایشی')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('ایمیل')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->label('شماره موبایل')
                    ->tel()
                    ->maxLength(32)
                    ->helperText('شماره نرمال‌شده به‌صورت خودکار ذخیره می‌شود.')
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('normalized_phone', \App\Support\PhoneNumber::normalize($state)))
                    ->live(onBlur: true),

                Forms\Components\TextInput::make('normalized_phone')
                    ->label('شماره نرمال‌شده')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\Toggle::make('is_admin')
                    ->label('دسترسی ادمین')
                    ->default(false),

                Forms\Components\DateTimePicker::make('email_verified_at')
                    ->label('تاریخ تایید ایمیل'),

                Forms\Components\DateTimePicker::make('phone_verified_at')
                    ->label('تاریخ تایید شماره موبایل')
                    ->helperText('برای لغو تایید، خالی کنید.'),

                Forms\Components\DateTimePicker::make('profile_completed_at')
                    ->label('تاریخ تکمیل پروفایل'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_id')
                    ->label('شناسه اکانت')
                    ->searchable(query: function ($query, string $search) {
                        return $query->where(function ($q) use ($search) {
                            $q->where('account_id', 'like', "%{$search}%")
                                ->orWhere('normalized_phone', 'like', "%{$search}%")
                                ->orWhere('id', $search);
                        });
                    })
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('ایمیل')->searchable()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('شماره موبایل')
                    ->searchable()
                    ->fontFamily('mono')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('phone_verified_at')
                    ->label('تایید شماره')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->phone_verified_at)),
                Tables\Columns\TextColumn::make('wallet_balance_toman')
                    ->label('موجودی کیف پول')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('services_count')
                    ->label('تعداد سرویس‌ها')
                    ->counts('services')
                    ->sortable(),
                Tables\Columns\TextColumn::make('orders_count')
                    ->label('تعداد سفارش‌ها')
                    ->counts('orders')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_admin')->label('ادمین')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت نام')->dateTime()->sortable(),
            ])
            ->searchable()
            ->filters([
                Tables\Filters\Filter::make('admins')
                    ->label('فقط ادمین‌ها')
                    ->query(fn ($query) => $query->where('is_admin', true)),
                Tables\Filters\Filter::make('no_phone')
                    ->label('کاربران بدون شماره موبایل')
                    ->query(fn ($query) => $query->whereNull('phone')),
                Tables\Filters\Filter::make('phone_verified')
                    ->label('شماره تایید شده')
                    ->query(fn ($query) => $query->whereNotNull('phone_verified_at')),
                Tables\Filters\Filter::make('phone_unverified')
                    ->label('شماره تایید نشده')
                    ->query(fn ($query) => $query->whereNull('phone_verified_at')),
                Tables\Filters\Filter::make('has_wallet_balance')
                    ->label('کاربران دارای موجودی کیف پول')
                    ->query(fn ($query) => $query->where('wallet_balance_toman', '>', 0)),
                Tables\Filters\Filter::make('has_active_service')
                    ->label('کاربران دارای سرویس فعال')
                    ->query(fn ($query) => $query->whereHas('services', fn ($q) => $q->where('status', \App\Models\UserService::STATUS_ACTIVE))),
                Tables\Filters\Filter::make('registered_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از تاریخ'),
                        Forms\Components\DatePicker::make('until')->label('تا تاریخ'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->label('تاریخ ثبت نام'),
            ])
            ->actions([
                Tables\Actions\Action::make('verify_phone')
                    ->label('تایید دستی شماره موبایل')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (User $record) => filled($record->phone) && is_null($record->phone_verified_at))
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['phone_verified_at' => now(), 'profile_completed_at' => $record->profile_completed_at ?? now()]);
                        Notification::make()->title('شماره موبایل تایید شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('unverify_phone')
                    ->label('لغو تایید شماره موبایل')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn (User $record) => ! is_null($record->phone_verified_at))
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['phone_verified_at' => null]);
                        Notification::make()->title('تایید شماره موبایل لغو شد.')->warning()->send();
                    }),

                Tables\Actions\Action::make('resend_otp')
                    ->label('ارسال مجدد کد تایید')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (User $record) => filled($record->phone) && is_null($record->phone_verified_at))
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $result = app(\App\Services\Phone\PhoneVerificationService::class)->requestCode($record);
                        if ($result['status'] === 'sent') {
                            $sent = ($result['sms_sent'] ?? false)
                                ? 'کد تایید برای کاربر ارسال شد.'
                                : 'کد تایید ساخته شد اما ارسال پیامک انجام نشد (سرویس پیامک تنظیم نشده است).';
                            Notification::make()->title($sent)->success()->send();
                        } else {
                            Notification::make()->title($result['message'])->warning()->send();
                        }
                    }),

                Tables\Actions\Action::make('credit_wallet')
                    ->label('شارژ کیف پول')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount_toman')
                            ->label('مبلغ (تومان)')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\Textarea::make('description')
                            ->label('دلیل / توضیح')
                            ->required(fn () => SiteText::getBool('wallet_admin_adjustment_requires_note', true))
                            ->rows(2),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(WalletService::class)->credit(
                                $record,
                                (int) $data['amount_toman'],
                                WalletTransaction::TYPE_MANUAL_CREDIT,
                                [
                                    'description' => $data['description'],
                                    'admin_id'    => auth()->id(),
                                ]
                            );
                            Notification::make()
                                ->title('کیف پول با موفقیت شارژ شد.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('خطا: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('شارژ کیف پول')
                    ->modalSubmitActionLabel('شارژ کن'),

                Tables\Actions\Action::make('debit_wallet')
                    ->label('برداشت از کیف پول')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount_toman')
                            ->label('مبلغ (تومان)')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\Textarea::make('description')
                            ->label('دلیل / توضیح')
                            ->required(fn () => SiteText::getBool('wallet_admin_adjustment_requires_note', true))
                            ->rows(2),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(WalletService::class)->debit(
                                $record,
                                (int) $data['amount_toman'],
                                WalletTransaction::TYPE_MANUAL_DEBIT,
                                [
                                    'description' => $data['description'],
                                    'admin_id'    => auth()->id(),
                                ]
                            );
                            Notification::make()
                                ->title('برداشت از کیف پول با موفقیت انجام شد.')
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('برداشت از کیف پول')
                    ->modalSubmitActionLabel('برداشت کن'),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
