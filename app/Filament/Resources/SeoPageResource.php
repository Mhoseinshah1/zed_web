<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeoPageResource\Pages;
use App\Filament\Support\SeoFormFields;
use App\Models\SeoPage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manage per-page SEO for the public static pages. Records are seeded; admins
 * edit them. Login/register are lock_noindex — a serious warning is shown
 * before they can be made indexable.
 */
class SeoPageResource extends Resource
{
    protected static ?string $model = SeoPage::class;

    protected static ?string $navigationIcon   = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationGroup  = 'مدیریت محتوا';
    protected static ?string $navigationLabel  = 'سئوی صفحات';
    protected static ?string $modelLabel       = 'سئوی صفحه';
    protected static ?string $pluralModelLabel = 'سئوی صفحات';
    protected static ?int    $navigationSort   = 85;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('شناسه صفحه')->schema([
                Forms\Components\TextInput::make('label')->label('نام صفحه')->disabled()->dehydrated(false),
                Forms\Components\TextInput::make('page_key')->label('کلید صفحه')->disabled()->dehydrated(false),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
            ])->columns(3),

            // Serious warning for the auth pages (login/register).
            Forms\Components\Placeholder::make('auth_warning')
                ->label('')
                ->visible(fn (?SeoPage $record) => $record?->lock_noindex)
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="color:#dc2626;font-weight:600">هشدار: ایندکس شدن صفحات ورود و ثبت‌نام معمولاً توصیه نمی‌شود.</div>'
                ))
                ->columnSpanFull(),

            Forms\Components\Section::make('عنوان و توضیحات')
                ->schema(SeoFormFields::metaFields())->columns(2),

            Forms\Components\Section::make('تنظیمات پیشرفته و اشتراک‌گذاری')->collapsed()
                ->schema(array_merge(
                    SeoFormFields::advancedFields(),
                    [
                        Forms\Components\FileUpload::make('og_image')->label('تصویر اشتراک‌گذاری')
                            ->image()->disk('public')->directory('seo')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)
                            ->columnSpanFull(),
                    ],
                ))->columns(2),

            Forms\Components\Section::make('سایت‌مپ')->collapsed()
                ->schema(SeoFormFields::sitemapFields())->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('صفحه')->searchable(['label', 'page_key'])->sortable(),
                Tables\Columns\TextColumn::make('meta_title')->label('عنوان سئو')->limit(40)->placeholder('—'),
                Tables\Columns\IconColumn::make('robots_index')->label('ایندکس')->boolean(),
                Tables\Columns\IconColumn::make('include_in_sitemap')->label('سایت‌مپ')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('ویرایش')->dateTime('Y/m/d')->sortable()->toggleable(),
            ])
            ->defaultSort('id')
            ->actions([Tables\Actions\EditAction::make()->label('ویرایش')])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoPages::route('/'),
            'edit'  => Pages\EditSeoPage::route('/{record}/edit'),
        ];
    }
}
