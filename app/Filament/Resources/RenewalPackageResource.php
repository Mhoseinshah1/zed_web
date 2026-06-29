<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RenewalPackageResource\Pages;
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
    protected static ?string $navigationGroup  = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel  = 'پکیج‌های تمدید';
    protected static ?string $modelLabel       = 'پکیج تمدید';
    protected static ?string $pluralModelLabel = 'پکیج‌های تمدید';
    protected static ?int    $navigationSort   = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات پکیج تمدید')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('عنوان پکیج')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('مثلاً: تمدید ۳۰ روزه'),

                Forms\Components\TextInput::make('duration_days')
                    ->label('مدت (روز)')
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

                Forms\Components\Textarea::make('description')
                    ->label('توضیحات')
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
                    ->label('مدت (روز)')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state . ' روز'),

                Tables\Columns\TextColumn::make('price_toman')
                    ->label('قیمت')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' تومان')
                    ->sortable(),

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
