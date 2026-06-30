<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RenewalPackageResource\Pages;
use App\Models\Plan;
use App\Models\RenewalPackage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RenewalPackageResource extends Resource
{
    protected static ?string $model = RenewalPackage::class;

    protected static ?string $navigationIcon   = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup  = 'فروشگاه و پلن‌ها';
    protected static bool   $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel  = 'بسته‌های تمدید';
    protected static ?string $modelLabel       = 'بسته تمدید';
    protected static ?string $pluralModelLabel = 'بسته‌های تمدید';
    protected static ?int    $navigationSort   = 60;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات بسته تمدید')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('عنوان')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('مثلاً: تمدید ۳۰ روزه'),

                Forms\Components\TextInput::make('duration_days')
                    ->label('مدت تمدید (روز)')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->placeholder('30'),

                Forms\Components\TextInput::make('price_toman')
                    ->label('قیمت (تومان)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('150000'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('ترتیب نمایش')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true)
                    ->inline(false),

                Forms\Components\Select::make('allowed_plan_ids')
                    ->label('پلن‌های مجاز')
                    ->multiple()
                    ->options(fn () => Plan::where('is_active', true)->pluck('name', 'id'))
                    ->helperText('خالی بگذارید برای اعمال روی همه پلن‌ها')
                    ->nullable(),

                Forms\Components\Textarea::make('description')
                    ->label('توضیحات')
                    ->rows(2)
                    ->nullable()
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('admin_note')
                    ->label('توضیحات ادمین')
                    ->rows(2)
                    ->nullable()
                    ->columnSpanFull(),
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
                    ->width('60px'),

                Tables\Columns\TextColumn::make('name')
                    ->label('عنوان')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration_days')
                    ->label('مدت تمدید')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state . ' روز'),

                Tables\Columns\TextColumn::make('price_toman')
                    ->label('قیمت')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' تومان')
                    ->sortable(),

                Tables\Columns\TextColumn::make('allowed_plan_ids')
                    ->label('پلن‌های مجاز')
                    ->getStateUsing(function (RenewalPackage $record): string {
                        if (empty($record->allowed_plan_ids)) {
                            return 'همه پلن‌ها';
                        }
                        $names = Plan::whereIn('id', $record->allowed_plan_ids)->pluck('name');
                        return $names->implode('، ');
                    })
                    ->default('همه پلن‌ها')
                    ->wrap(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('تعداد استفاده')
                    ->counts('orders')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime('Y/m/d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->label('فعال')
                    ->query(fn (Builder $query) => $query->where('is_active', true)),

                Tables\Filters\Filter::make('inactive')
                    ->label('غیرفعال')
                    ->query(fn (Builder $query) => $query->where('is_active', false)),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (RenewalPackage $record) => $record->is_active ? 'غیرفعال کن' : 'فعال کن')
                    ->icon(fn (RenewalPackage $record) => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (RenewalPackage $record) => $record->is_active ? 'warning' : 'success')
                    ->action(function (RenewalPackage $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                    }),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف انتخاب‌شده'),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRenewalPackages::route('/'),
            'create' => Pages\CreateRenewalPackage::route('/create'),
            'edit'   => Pages\EditRenewalPackage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(SoftDeletingScope::class);
    }
}
