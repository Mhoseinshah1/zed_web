<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $navigationIcon   = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup  = 'تنظیمات پرداخت';
    protected static ?string $navigationLabel  = 'روش‌های پرداخت';
    protected static ?string $modelLabel       = 'روش پرداخت';
    protected static ?string $pluralModelLabel = 'روش‌های پرداخت';
    protected static ?int    $navigationSort   = 1;

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
                        'info'    => ['manual_crypto'],
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
