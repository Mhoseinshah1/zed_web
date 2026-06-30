<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanCategoryResource\Pages;
use App\Models\PlanCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PlanCategoryResource extends Resource
{
    protected static ?string $model = PlanCategory::class;

    protected static ?string $navigationIcon   = 'heroicon-o-tag';
    protected static ?string $navigationGroup   = 'فروشگاه و پلن‌ها';
    protected static ?string $navigationLabel   = 'دسته‌بندی پلن‌ها';
    protected static ?string $modelLabel        = 'دسته‌بندی پلن';
    protected static ?string $pluralModelLabel  = 'دسته‌بندی پلن‌ها';
    protected static ?int    $navigationSort    = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('title')->label('عنوان')->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                        if ($operation === 'create') {
                            $set('slug', Str::slug($state, '-', 'fa') ?: 'cat-' . Str::random(6));
                        }
                    }),
                Forms\Components\TextInput::make('slug')->label('اسلاگ')->required()
                    ->unique(ignoreRecord: true)->maxLength(120),
                Forms\Components\TextInput::make('icon')->label('آیکون (ایموجی)')->maxLength(50)->placeholder('مثال: ⚡'),
                Forms\Components\TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Forms\Components\TextInput::make('description')->label('توضیحات')->maxLength(255)->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('50px'),
                Tables\Columns\TextColumn::make('icon')->label('آیکون'),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('plans_count')->counts('plans')->label('تعداد پلن')->badge(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('وضعیت'),
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
            'index'  => Pages\ListPlanCategories::route('/'),
            'create' => Pages\CreatePlanCategory::route('/create'),
            'edit'   => Pages\EditPlanCategory::route('/{record}/edit'),
        ];
    }
}
