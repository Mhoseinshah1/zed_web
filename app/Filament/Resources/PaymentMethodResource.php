<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $navigationIcon   = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup  = 'مالی';
    protected static ?string $navigationLabel  = 'روش‌های پرداخت';
    protected static ?string $modelLabel       = 'روش پرداخت';
    protected static ?string $pluralModelLabel = 'روش‌های پرداخت';
    protected static ?int    $navigationSort   = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات اصلی')->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('slug')
                    ->label('شناسه یکتا (slug)')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100)
                    ->helperText('در صورت خالی بودن، از عنوان تولید می‌شود'),

                Forms\Components\Select::make('type')
                    ->label('نوع')
                    ->required()
                    ->options(PaymentMethod::allTypes()),

                Forms\Components\TextInput::make('description')
                    ->label('توضیح کوتاه')
                    ->maxLength(255),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),

                Forms\Components\TextInput::make('sort_order')
                    ->label('ترتیب نمایش')
                    ->numeric()
                    ->default(0),
            ])->columns(2),

            Forms\Components\Section::make('اطلاعات پرداخت')->schema([
                Forms\Components\Textarea::make('instructions')
                    ->label('راهنمای پرداخت')
                    ->rows(4)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('account_label')
                    ->label('برچسب حساب (مثلاً: آدرس کیف پول)')
                    ->maxLength(100),

                Forms\Components\TextInput::make('account_value')
                    ->label('مقدار حساب (آدرس/شماره)')
                    ->maxLength(500),

                Forms\Components\TextInput::make('network')
                    ->label('شبکه (مثلاً: TRC20)')
                    ->maxLength(50),
            ])->columns(2),

            Forms\Components\Section::make('محدودیت‌ها')->schema([
                Forms\Components\TextInput::make('min_amount_toman')
                    ->label('حداقل مبلغ (تومان)')
                    ->numeric()
                    ->minValue(0),

                Forms\Components\TextInput::make('max_amount_toman')
                    ->label('حداکثر مبلغ (تومان)')
                    ->numeric()
                    ->minValue(0),

                Forms\Components\TextInput::make('fee_percent')
                    ->label('کارمزد (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01),
            ])->columns(3)->collapsible()->collapsed(),

            // CentralPay rial gateway configuration — only visible when type=centralpay
            Forms\Components\Section::make('تنظیمات CentralPay')
                ->visible(fn (Get $get) => $get('type') === PaymentMethod::TYPE_CENTRALPAY)
                ->schema([
                    Forms\Components\TextInput::make('api_key')
                        ->label('کلید API CentralPay')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('کلید API از CentralPay دریافت می‌شود. برای تغییر ندادن کلید، این فیلد را خالی بگذارید.')
                        ->maxLength(500),

                    Forms\Components\TextInput::make('config.base_url')
                        ->label('آدرس پایه CentralPay')
                        ->default('https://centralapi.org/webservice/basic')
                        ->placeholder('https://centralapi.org/webservice/basic')
                        ->maxLength(500),

                    Forms\Components\Select::make('config.amount_unit')
                        ->label('واحد مبلغ')
                        ->options(['TOMAN' => 'تومان (TOMAN)', 'RIAL' => 'ریال (RIAL)'])
                        ->default('TOMAN')
                        ->helperText('مبلغ برای CentralPay به تومان ارسال می‌شود.'),

                    Forms\Components\TextInput::make('config.type')
                        ->label('نوع تراکنش')
                        ->default('deposit')
                        ->helperText('طبق مستندات CentralPay مقدار deposit استفاده می‌شود.')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('config.callback_path')
                        ->label('مسیر بازگشت پرداخت')
                        ->default('/payments/centralpay/callback')
                        ->helperText('orderId به صورت خودکار به این آدرس اضافه می‌شود.')
                        ->maxLength(500),

                    Forms\Components\Placeholder::make('callback_url_display')
                        ->label('آدرس بازگشت پرداخت (جهت ثبت در CentralPay)')
                        ->content(fn (Get $get) => url($get('config.callback_path') ?: '/payments/centralpay/callback'))
                        ->helperText('این آدرس را در تنظیمات CentralPay ثبت کنید.'),
                ])
                ->columns(2)
                ->collapsible(),

            // NOWPayments gateway configuration — only visible when type=nowpayments
            Forms\Components\Section::make('تنظیمات NOWPayments')
                ->visible(fn (Get $get) => $get('type') === PaymentMethod::TYPE_NOWPAYMENTS)
                ->schema([
                    Forms\Components\TextInput::make('api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable()
                        ->helperText('از داشبورد NOWPayments دریافت کنید. رمزگذاری شده ذخیره می‌شود.')
                        ->maxLength(500),

                    Forms\Components\TextInput::make('ipn_secret')
                        ->label('IPN Secret')
                        ->password()
                        ->revealable()
                        ->helperText('برای تایید امضای IPN webhook. رمزگذاری شده ذخیره می‌شود.')
                        ->maxLength(500),

                    Forms\Components\Select::make('config.nowpayments_mode')
                        ->label('حالت پرداخت NOWPayments')
                        ->options([
                            'invoice' => 'فاکتور میزبانی‌شده؛ انتخاب ارز توسط مشتری',
                            'direct'  => 'پرداخت مستقیم؛ ارز از قبل مشخص می‌شود',
                        ])
                        ->default('invoice')
                        ->helperText('در حالت فاکتور، مشتری ارز را داخل NOWPayments انتخاب می‌کند.')
                        ->live(),

                    Forms\Components\Toggle::make('config.sandbox')
                        ->label('حالت آزمایشی (Sandbox)')
                        ->helperText('برای تست از api-sandbox.nowpayments.io استفاده می‌شود')
                        ->default(false),

                    Forms\Components\TextInput::make('config.base_url')
                        ->label('Base URL (اختیاری)')
                        ->placeholder('https://api.nowpayments.io/v1')
                        ->helperText('فقط در صورت نیاز به override کردن آدرس API پر کنید')
                        ->url()
                        ->maxLength(500),

                    Forms\Components\TextInput::make('config.site_currency')
                        ->label('ارز سایت')
                        ->default('IRT')
                        ->helperText('IRT (تومان) یا IRR (ریال)')
                        ->placeholder('IRT'),

                    Forms\Components\TextInput::make('config.exchange_rate_usd')
                        ->label('نرخ تبدیل به دلار (تومان/دلار)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('تعداد تومان برابر با ۱ دلار آمریکا. مثال: 75000'),

                    Forms\Components\TextInput::make('config.price_currency')
                        ->label('ارز قیمت‌گذاری')
                        ->placeholder('usd')
                        ->default('usd')
                        ->helperText('ارزی که قیمت‌ها در آن محاسبه می‌شود (معمولاً usd)'),

                    Forms\Components\TextInput::make('config.ipn_callback_url')
                        ->label('IPN Callback URL (اختیاری)')
                        ->helperText('خالی = آدرس خودکار سایت (/webhooks/nowpayments)')
                        ->url()
                        ->maxLength(500),

                    Forms\Components\TextInput::make('config.success_url')
                        ->label('Success URL (اختیاری)')
                        ->helperText('خالی = صفحه سفارش کاربر')
                        ->url()
                        ->maxLength(500),

                    Forms\Components\TextInput::make('config.cancel_url')
                        ->label('Cancel URL (اختیاری)')
                        ->helperText('خالی = صفحه پرداخت سفارش کاربر')
                        ->url()
                        ->maxLength(500),

                    // Direct mode only fields
                    Forms\Components\TextInput::make('config.default_pay_currency')
                        ->label('ارز پیش‌فرض پرداخت (فقط حالت مستقیم)')
                        ->placeholder('usdttrc20')
                        ->helperText('در حالت فاکتور میزبانی‌شده، مشتری ارز پرداخت را داخل NOWPayments انتخاب می‌کند.')
                        ->visible(fn (Get $get) => ($get('config.nowpayments_mode') ?? 'invoice') === 'direct'),

                    Forms\Components\TextInput::make('config.allowed_pay_currencies')
                        ->label('ارزهای مجاز (فقط حالت مستقیم)')
                        ->placeholder('btc,eth,usdttrc20,ltc')
                        ->helperText('در حالت فاکتور میزبانی‌شده، مشتری ارز پرداخت را داخل NOWPayments انتخاب می‌کند.')
                        ->visible(fn (Get $get) => ($get('config.nowpayments_mode') ?? 'invoice') === 'direct'),
                ])
                ->columns(2)
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('slug')
                    ->fontFamily('mono'),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => PaymentMethod::allTypes()[$state] ?? $state)
                    ->colors([
                        'success' => ['wallet'],
                        'info'    => ['manual_crypto', 'nowpayments'],
                        'warning' => ['manual_stars', 'manual_rial'],
                    ]),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخرین ویرایش')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options(PaymentMethod::allTypes()),

                Tables\Filters\Filter::make('active')
                    ->label('فعال')
                    ->query(fn ($query) => $query->where('is_active', true)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit'   => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
