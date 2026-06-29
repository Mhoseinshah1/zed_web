<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiscountCodeResource\Pages;
use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DiscountCodeResource extends Resource
{
    protected static ?string $model = DiscountCode::class;

    protected static ?string $navigationIcon   = 'heroicon-o-tag';
    protected static ?string $navigationGroup  = 'مالی';
    protected static ?string $navigationLabel  = 'کدهای تخفیف';
    protected static ?string $modelLabel       = 'کد تخفیف';
    protected static ?string $pluralModelLabel = 'کدهای تخفیف';
    protected static ?int    $navigationSort   = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات پایه')->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان کمپین')
                    ->placeholder('مثلاً: تخفیف نوروز ۱۴۰۵')
                    ->maxLength(255),

                Forms\Components\TextInput::make('code')
                    ->label('کد تخفیف')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(64)
                    ->placeholder('مثلاً: NOROOZ20')
                    ->helperText('کد تخفیف به صورت خودکار به حروف بزرگ ذخیره می‌شود.')
                    ->dehydrateStateUsing(fn ($state) => strtoupper(trim($state ?? ''))),

                Forms\Components\Select::make('type')
                    ->label('نوع تخفیف')
                    ->required()
                    ->options([
                        DiscountCode::TYPE_PERCENT => 'درصدی',
                        DiscountCode::TYPE_FIXED   => 'مبلغ ثابت (تومان)',
                    ])
                    ->live()
                    ->default(DiscountCode::TYPE_PERCENT),

                Forms\Components\TextInput::make('value')
                    ->label(fn (Get $get) => $get('type') === DiscountCode::TYPE_PERCENT ? 'درصد تخفیف (۱ تا ۱۰۰)' : 'مبلغ تخفیف (تومان)')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(fn (Get $get) => $get('type') === DiscountCode::TYPE_PERCENT ? 100 : null),

                Forms\Components\TextInput::make('max_discount_amount')
                    ->label('حداکثر مبلغ تخفیف (تومان)')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Get $get) => $get('type') === DiscountCode::TYPE_PERCENT)
                    ->helperText('حداکثر تخفیف قابل اعمال برای تخفیف درصدی'),

                Forms\Components\TextInput::make('min_order_amount')
                    ->label('حداقل مبلغ سفارش (تومان)')
                    ->numeric()
                    ->minValue(0),
            ])->columns(3),

            Forms\Components\Section::make('محدودیت استفاده')->schema([
                Forms\Components\TextInput::make('total_usage_limit')
                    ->label('تعداد کل استفاده')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('خالی بگذارید برای نامحدود'),

                Forms\Components\TextInput::make('per_user_usage_limit')
                    ->label('تعداد استفاده برای هر کاربر')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->default(1),

                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('تاریخ شروع')
                    ->native(false)
                    ->displayFormat('Y-m-d H:i'),

                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('تاریخ پایان')
                    ->native(false)
                    ->displayFormat('Y-m-d H:i'),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true)
                    ->inline(false),

                Forms\Components\Toggle::make('first_purchase_only')
                    ->label('فقط اولین خرید')
                    ->default(false)
                    ->inline(false),

                Forms\Components\Toggle::make('new_users_only')
                    ->label('فقط کاربران جدید')
                    ->default(false)
                    ->inline(false),
            ])->columns(3),

            Forms\Components\Section::make('محدودیت پلن')->schema([
                Forms\Components\Select::make('allowed_plan_ids')
                    ->label('پلن‌های مجاز')
                    ->multiple()
                    ->options(fn () => Plan::where('is_active', true)->pluck('name', 'id'))
                    ->helperText('خالی بگذارید برای اعمال روی همه پلن‌ها'),
            ])->columns(1),

            Forms\Components\Section::make('یادداشت')->schema([
                Forms\Components\Textarea::make('admin_note')
                    ->label('توضیحات ادمین')
                    ->rows(3),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('کد')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable()
                    ->default('—')
                    ->limit(30),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => $state === DiscountCode::TYPE_PERCENT ? 'درصدی' : 'مبلغ ثابت')
                    ->colors([
                        'info'    => DiscountCode::TYPE_PERCENT,
                        'warning' => DiscountCode::TYPE_FIXED,
                    ]),

                Tables\Columns\TextColumn::make('value')
                    ->label('مقدار')
                    ->formatStateUsing(fn ($state, DiscountCode $record) => $record->valueLabel()),

                Tables\Columns\TextColumn::make('usage')
                    ->label('استفاده / سقف')
                    ->getStateUsing(function (DiscountCode $record): string {
                        $used  = $record->usedCount();
                        $limit = $record->total_usage_limit ?? '∞';
                        return "{$used} / {$limit}";
                    }),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('تاریخ شروع')
                    ->dateTime('Y/m/d H:i')
                    ->default('—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('تاریخ پایان')
                    ->dateTime('Y/m/d H:i')
                    ->default('—')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status_computed')
                    ->label('وضعیت')
                    ->getStateUsing(fn (DiscountCode $record) => $record->statusLabel())
                    ->colors([
                        'success' => 'فعال',
                        'danger'  => fn ($state) => in_array($state, ['غیرفعال', 'منقضی']),
                        'warning' => 'هنوز شروع نشده',
                    ]),

                Tables\Columns\TextColumn::make('total_discount')
                    ->label('مجموع تخفیف')
                    ->getStateUsing(fn (DiscountCode $record) => number_format($record->totalDiscountGiven()) . ' تومان')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime('Y/m/d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('active')
                    ->label('فعال')
                    ->query(fn (Builder $query) => $query
                        ->where('is_active', true)
                        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ),

                Filter::make('inactive')
                    ->label('غیرفعال')
                    ->query(fn (Builder $query) => $query->where('is_active', false)),

                Filter::make('expired')
                    ->label('منقضی')
                    ->query(fn (Builder $query) => $query->where('expires_at', '<', now())),

                SelectFilter::make('type')
                    ->label('نوع')
                    ->options([
                        DiscountCode::TYPE_PERCENT => 'درصدی',
                        DiscountCode::TYPE_FIXED   => 'مبلغ ثابت',
                    ]),

                Filter::make('has_usage_limit')
                    ->label('دارای سقف مصرف')
                    ->query(fn (Builder $query) => $query->whereNotNull('total_usage_limit')),

                Filter::make('date_range')
                    ->label('بازه تاریخ')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('از')->native(false),
                        Forms\Components\DatePicker::make('until')->label('تا')->native(false),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'],  fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view_redemptions')
                    ->label('سابقه استفاده')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalContent(function (DiscountCode $record) {
                        $redemptions = DiscountRedemption::where('discount_code_id', $record->id)
                            ->with(['user', 'order'])
                            ->latest()
                            ->limit(50)
                            ->get();
                        return view('filament.modals.discount-redemptions', compact('redemptions', 'record'));
                    })
                    ->modalHeading(fn (DiscountCode $record) => 'سابقه استفاده: ' . $record->code)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن'),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDiscountCodes::route('/'),
            'create' => Pages\CreateDiscountCode::route('/create'),
            'edit'   => Pages\EditDiscountCode::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(SoftDeletingScope::class);
    }
}
