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
    protected static ?string $modelLabel      = 'متن سایت';
    protected static ?string $pluralModelLabel = 'متن‌های سایت';
    protected static ?int $navigationSort     = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('شناسه')->schema([
                Forms\Components\TextInput::make('key')
                    ->label('کلید (key)')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(191)
                    ->helperText('مثال: homepage.hero.title — این را تغییر ندهید مگر در صورت ضرورت'),

                Forms\Components\TextInput::make('group')
                    ->label('گروه')
                    ->maxLength(100)
                    ->helperText('مثال: homepage، footer، legal'),

                Forms\Components\TextInput::make('label')
                    ->label('برچسب (نام نمایشی)')
                    ->maxLength(255),

                Forms\Components\Select::make('type')
                    ->label('نوع')
                    ->options([
                        'text'     => 'متن کوتاه',
                        'textarea' => 'متن بلند',
                        'html'     => 'HTML',
                        'image'    => 'آدرس تصویر',
                        'boolean'  => 'بله/خیر',
                        'number'   => 'عدد',
                    ])
                    ->default('text')
                    ->required(),

                Forms\Components\Toggle::make('is_public')
                    ->label('عمومی (نمایش در سایت)')
                    ->default(true),

                Forms\Components\TextInput::make('sort_order')
                    ->label('ترتیب نمایش')
                    ->numeric()
                    ->default(0),
            ])->columns(2),

            Forms\Components\Section::make('مقدار')->schema([
                Forms\Components\Textarea::make('value')
                    ->label('مقدار')
                    ->rows(5)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group')
                    ->label('گروه')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('برچسب')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('key')
                    ->label('کلید')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('value')
                    ->label('مقدار')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخرین ویرایش')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('گروه')
                    ->options(fn () => SiteText::select('group')->whereNotNull('group')->distinct()->pluck('group', 'group')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteTexts::route('/'),
            'edit'  => Pages\EditSiteText::route('/{record}/edit'),
        ];
    }
}
