<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon  = 'heroicon-o-globe-alt';
    protected static ?string $navigationGroup = 'تنظیمات سایت';
    protected static ?string $navigationLabel = 'لوکیشن‌ها';
    protected static ?string $modelLabel      = 'لوکیشن';
    protected static ?string $pluralModelLabel = 'لوکیشن‌ها';
    protected static ?int $navigationSort     = 11;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('country_name')
                    ->label('نام کشور')
                    ->required()
                    ->maxLength(100),

                Forms\Components\TextInput::make('country_code')
                    ->label('کد کشور')
                    ->maxLength(10)
                    ->placeholder('مثال: DE, US, GB')
                    ->helperText('کد ISO دو حرفی'),

                Forms\Components\TextInput::make('flag_emoji')
                    ->label('ایموجی پرچم')
                    ->maxLength(10)
                    ->placeholder('مثال: 🇩🇪'),

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

                Forms\Components\Toggle::make('is_youtube_special')
                    ->label('مناسب یوتیوب')
                    ->default(false),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('50px'),
                Tables\Columns\TextColumn::make('flag_emoji')->label('🏳'),
                Tables\Columns\TextColumn::make('country_name')->label('کشور')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('country_code')->label('کد')->fontFamily('mono')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\IconColumn::make('is_youtube_special')->label('یوتیوب')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('آخرین ویرایش')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('وضعیت'),
                Tables\Filters\TernaryFilter::make('is_youtube_special')->label('مناسب یوتیوب'),
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
            'index'  => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit'   => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
