<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Feature;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon  = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup = 'فروشگاه و پلن‌ها';
    protected static ?string $navigationLabel = 'پلن‌ها';
    protected static ?string $modelLabel      = 'پلن';
    protected static ?string $pluralModelLabel = 'پلن‌ها';
    protected static ?int $navigationSort     = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات پلن')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام پلن')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                        if ($operation === 'create') {
                            $set('slug', Str::slug($state));
                        }
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label('اسلاگ (slug)')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100)
                    ->helperText('شناسه یکتا — به‌صورت خودکار از نام پر می‌شود'),

                Forms\Components\Select::make('category_id')
                    ->label('دسته‌بندی پلن')
                    ->relationship('category', 'title')
                    ->searchable()->preload()->native(false)
                    ->placeholder('بدون دسته‌بندی'),

                Forms\Components\TextInput::make('short_description')
                    ->label('توضیح کوتاه')
                    ->maxLength(255)
                    ->helperText('روی کارت پلن نمایش داده می‌شود'),

                Forms\Components\Textarea::make('description')
                    ->label('توضیحات')
                    ->rows(3)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('badge')
                    ->label('برچسب (badge)')
                    ->maxLength(50)
                    ->placeholder('مثال: محبوب‌ترین')
                    ->helperText('اختیاری — روی کارت پلن نمایش داده می‌شود'),

                Forms\Components\Select::make('badge_type')
                    ->label('نوع برچسب')
                    ->options([
                        'primary' => 'اصلی', 'success' => 'موفقیت', 'warning' => 'هشدار',
                        'danger' => 'خطر', 'accent' => 'تأکید',
                    ])->native(false)->placeholder('پیش‌فرض'),

                Forms\Components\TagsInput::make('feature_list')
                    ->label('ویژگی‌ها (هر مورد یک خط)')
                    ->placeholder('افزودن ویژگی')
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('قیمت و مشخصات')->schema([
                Forms\Components\TextInput::make('price_toman')
                    ->label('قیمت (تومان)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix('تومان'),

                Forms\Components\TextInput::make('old_price_toman')
                    ->label('قیمت قبلی (تومان) — اختیاری')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('تومان')
                    ->helperText('برای نمایش تخفیف'),

                Forms\Components\TextInput::make('traffic_gb')
                    ->label('حجم (گیگابایت)')
                    ->numeric()
                    ->minValue(1)
                    ->suffix('GB')
                    ->helperText('خالی بگذارید برای نامحدود'),

                Forms\Components\TextInput::make('duration_days')
                    ->label('مدت (روز)')
                    ->numeric()
                    ->minValue(1)
                    ->suffix('روز')
                    ->helperText('خالی بگذارید برای مدت نامحدود'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('ترتیب نمایش')
                    ->numeric()
                    ->default(0),
            ])->columns(2),

            Forms\Components\Section::make('وضعیت')->schema([
                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),

                Forms\Components\Toggle::make('is_featured')
                    ->label('پلن ویژه (برجسته)')
                    ->default(false),

                Forms\Components\Toggle::make('is_economic')
                    ->label('پلن اقتصادی')
                    ->default(false),
            ])->columns(3),

            Forms\Components\Section::make('ویژگی‌های پلن')->schema([
                Forms\Components\Select::make('features')
                    ->label('ویژگی‌ها')
                    ->multiple()
                    ->relationship('features', 'title')
                    ->options(fn () => Feature::active()->ordered()->pluck('title', 'id'))
                    ->preload()
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('تنظیمات تمدید سرویس')
                ->description('تنظیمات اختصاصی هنگامی که کاربر از این پلن برای تمدید سرویس موجود استفاده می‌کند.')
                ->collapsible()
                ->schema([
                    Forms\Components\Toggle::make('renewal_enabled')
                        ->label('قابل استفاده برای تمدید')
                        ->helperText('اگر غیرفعال باشد، این پلن در صفحه تمدید سرویس نمایش داده نمی‌شود.')
                        ->default(true),

                    Forms\Components\TextInput::make('renewal_price')
                        ->label('قیمت تمدید (تومان)')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('تومان')
                        ->helperText('خالی بگذارید تا از قیمت اصلی پلن استفاده شود.'),

                    Forms\Components\TextInput::make('renewal_duration_days')
                        ->label('مدت تمدید (روز)')
                        ->numeric()
                        ->minValue(1)
                        ->suffix('روز')
                        ->helperText('خالی بگذارید تا از مدت اصلی پلن استفاده شود.'),

                    Forms\Components\Toggle::make('renewal_cashback_enabled')
                        ->label('کش‌بک تمدید فعال')
                        ->default(false)
                        ->live(),

                    Forms\Components\Select::make('renewal_cashback_type')
                        ->label('نوع کش‌بک تمدید')
                        ->options([
                            'percent' => 'درصدی',
                            'fixed'   => 'مبلغ ثابت',
                        ])
                        ->visible(fn (Forms\Get $get) => $get('renewal_cashback_enabled')),

                    Forms\Components\TextInput::make('renewal_cashback_value')
                        ->label('مقدار کش‌بک تمدید')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('برای درصدی: عدد درصد (مثال: ۱۰). برای ثابت: مبلغ به تومان.')
                        ->visible(fn (Forms\Get $get) => $get('renewal_cashback_enabled')),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width('50px'),
                Tables\Columns\TextColumn::make('name')
                    ->label('نام')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_toman')
                    ->label('قیمت (تومان)')
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('traffic_gb')
                    ->label('حجم')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' GB' : 'نامحدود')
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_days')
                    ->label('مدت')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' روز' : 'نامحدود')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\IconColumn::make('renewal_enabled')->label('تمدید')->boolean()
                    ->trueColor('success')->falseColor('gray'),
                Tables\Columns\IconColumn::make('is_featured')->label('ویژه')->boolean(),
                Tables\Columns\IconColumn::make('is_economic')->label('اقتصادی')->boolean(),
                Tables\Columns\TextColumn::make('badge')
                    ->label('برچسب')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('وضعیت'),
                Tables\Filters\TernaryFilter::make('is_featured')->label('پلن ویژه'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit'   => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
