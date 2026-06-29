<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BannerResource\Pages;
use App\Models\Banner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon   = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup   = 'مدیریت محتوا';
    protected static ?string $navigationLabel   = 'بنرها';
    protected static ?string $modelLabel        = 'بنر';
    protected static ?string $pluralModelLabel  = 'بنرها';
    protected static ?int    $navigationSort    = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('محتوای بنر')->schema([
                Forms\Components\TextInput::make('title')->label('عنوان')->maxLength(255),
                Forms\Components\TextInput::make('subtitle')->label('زیرعنوان')->maxLength(255),
                Forms\Components\Textarea::make('description')->label('توضیحات')->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('button_text')->label('متن دکمه')->maxLength(100),
                Forms\Components\TextInput::make('button_url')->label('لینک دکمه')->maxLength(255)->url(),
            ])->columns(2),

            Forms\Components\Section::make('تصاویر و ظاهر')->schema([
                Forms\Components\FileUpload::make('image')->label('تصویر')
                    ->image()->disk('public')->directory('banners')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
                    ->maxSize(2048),
                Forms\Components\FileUpload::make('background_image')->label('تصویر پس‌زمینه')
                    ->image()->disk('public')->directory('banners')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
                    ->maxSize(2048),
                Forms\Components\Select::make('theme_style')->label('سبک رنگ')
                    ->options(['primary' => 'اصلی', 'accent' => 'تأکید', 'success' => 'موفقیت', 'warning' => 'هشدار', 'danger' => 'خطر', 'gradient' => 'گرادینت'])
                    ->native(false),
            ])->columns(2),

            Forms\Components\Section::make('جایگاه و زمان‌بندی')->schema([
                Forms\Components\Select::make('placement')->label('جایگاه نمایش')
                    ->options(Banner::placements())->required()->default('home_top')->native(false),
                Forms\Components\TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Forms\Components\DateTimePicker::make('starts_at')->label('شروع نمایش')->placeholder('بدون محدودیت'),
                Forms\Components\DateTimePicker::make('ends_at')->label('پایان نمایش')->placeholder('بدون محدودیت'),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('50px'),
                Tables\Columns\ImageColumn::make('image')->label('تصویر')->disk('public')->height(36),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('placement')->label('جایگاه')->badge()
                    ->formatStateUsing(fn ($state) => Banner::placements()[$state] ?? $state),
                Tables\Columns\TextColumn::make('starts_at')->label('شروع')->dateTime('Y/m/d')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('ends_at')->label('پایان')->dateTime('Y/m/d')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('placement')->label('جایگاه')->options(Banner::placements()),
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
            'index'  => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit'   => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
