<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeatureResource\Pages;
use App\Models\Feature;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class FeatureResource extends Resource
{
    protected static ?string $model = Feature::class;

    protected static ?string $navigationIcon  = 'heroicon-o-star';
    protected static ?string $navigationGroup = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel = 'ویژگی‌ها';
    protected static ?string $modelLabel      = 'ویژگی';
    protected static ?string $pluralModelLabel = 'ویژگی‌ها';
    protected static ?int $navigationSort     = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                        if ($operation === 'create') {
                            $slug = Str::slug($state, '-', 'fa');
                            $set('slug', $slug ?: 'feature-' . Str::random(6));
                        }
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label('اسلاگ')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),

                Forms\Components\TextInput::make('icon')
                    ->label('آیکون (ایموجی یا کلاس)')
                    ->maxLength(50)
                    ->placeholder('مثال: ✓ یا ⚡'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('ترتیب')
                    ->numeric()
                    ->default(0),

                Forms\Components\Textarea::make('description')
                    ->label('توضیحات')
                    ->rows(2)
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),
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
                Tables\Columns\TextColumn::make('slug')->label('اسلاگ')->searchable()->fontFamily('mono')->size('sm'),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('آخرین ویرایش')->dateTime()->sortable(),
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
            'index'  => Pages\ListFeatures::route('/'),
            'create' => Pages\CreateFeature::route('/create'),
            'edit'   => Pages\EditFeature::route('/{record}/edit'),
        ];
    }
}
