<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteTextResource\Pages;
use App\Models\SiteText;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SiteTextResource extends Resource
{
    protected static ?string $model = SiteText::class;

    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'تنظیمات سایت';
    protected static ?string $navigationLabel = 'متن‌های سایت';
    protected static ?string $modelLabel      = 'متن';
    protected static ?string $pluralModelLabel = 'متن‌های سایت';
    protected static ?int $navigationSort     = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('key')
                    ->label('کلید')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(191)
                    ->helperText('مثال: homepage.hero.title'),

                Forms\Components\TextInput::make('description')
                    ->label('توضیح')
                    ->maxLength(255)
                    ->helperText('توضیح مختصر برای ادمین'),

                Forms\Components\Textarea::make('value')
                    ->label('مقدار')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('کلید')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('description')
                    ->label('توضیح')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('value')
                    ->label('مقدار')
                    ->limit(80)
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخرین ویرایش')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteTexts::route('/'),
            'edit'  => Pages\EditSiteText::route('/{record}/edit'),
        ];
    }
}
