<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LandingSectionResource\Pages;
use App\Models\LandingSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LandingSectionResource extends Resource
{
    protected static ?string $model = LandingSection::class;

    protected static ?string $navigationIcon   = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup   = 'مدیریت محتوا';
    protected static ?string $navigationLabel   = 'سکشن‌های صفحه اصلی';
    protected static ?string $modelLabel        = 'سکشن صفحه اصلی';
    protected static ?string $pluralModelLabel  = 'سکشن‌های صفحه اصلی';
    protected static ?int    $navigationSort    = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('محتوای سکشن')->schema([
                Forms\Components\Select::make('type')->label('نوع سکشن')
                    ->options(LandingSection::types())->required()->default('custom')->native(false),
                Forms\Components\TextInput::make('key')->label('کلید یکتا (اختیاری)')
                    ->unique(ignoreRecord: true)->maxLength(120)->placeholder('مثال: home_trust'),
                Forms\Components\TextInput::make('title')->label('عنوان')->maxLength(255),
                Forms\Components\TextInput::make('subtitle')->label('زیرعنوان')->maxLength(255),
                Forms\Components\Textarea::make('content')->label('متن')->rows(3)->columnSpanFull(),
                Forms\Components\TextInput::make('icon')->label('آیکون (ایموجی)')->maxLength(50),
            ])->columns(2),

            Forms\Components\Section::make('دکمه‌ها و تصاویر')->collapsed()->schema([
                Forms\Components\TextInput::make('button_text')->label('متن دکمه اصلی')->maxLength(100),
                Forms\Components\TextInput::make('button_url')->label('لینک دکمه اصلی')->maxLength(255),
                Forms\Components\TextInput::make('secondary_button_text')->label('متن دکمه دوم')->maxLength(100),
                Forms\Components\TextInput::make('secondary_button_url')->label('لینک دکمه دوم')->maxLength(255),
                Forms\Components\FileUpload::make('image')->label('تصویر')
                    ->image()->disk('public')->directory('sections')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])->maxSize(2048),
                Forms\Components\FileUpload::make('background_image')->label('تصویر پس‌زمینه')
                    ->image()->disk('public')->directory('sections')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])->maxSize(2048),
            ])->columns(2),

            Forms\Components\Section::make('آیتم‌ها')->collapsed()->schema([
                Forms\Components\Repeater::make('items')->label('آیتم‌های سکشن')
                    ->schema([
                        Forms\Components\TextInput::make('title')->label('عنوان'),
                        Forms\Components\TextInput::make('icon')->label('آیکون'),
                        Forms\Components\Textarea::make('description')->label('توضیح')->rows(2),
                    ])
                    ->columns(3)->defaultItems(0)->collapsible()->reorderable()->columnSpanFull(),
            ]),

            Forms\Components\Section::make('نمایش')->schema([
                Forms\Components\TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('50px'),
                Tables\Columns\TextColumn::make('type')->label('نوع')->badge()
                    ->formatStateUsing(fn ($state) => LandingSection::types()[$state] ?? $state),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('key')->label('کلید')->fontFamily('mono')->size('sm')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('نوع')->options(LandingSection::types()),
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
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLandingSections::route('/'),
            'create' => Pages\CreateLandingSection::route('/create'),
            'edit'   => Pages\EditLandingSection::route('/{record}/edit'),
        ];
    }
}
