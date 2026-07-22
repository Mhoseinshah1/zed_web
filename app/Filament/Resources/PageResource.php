<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon   = 'heroicon-o-document';
    protected static ?string $navigationGroup   = 'مدیریت محتوا';
    protected static ?string $navigationLabel   = 'صفحات سایت';
    protected static ?string $modelLabel        = 'صفحه';
    protected static ?string $pluralModelLabel  = 'صفحات سایت';
    protected static ?int    $navigationSort    = 80;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('محتوای صفحه')->schema([
                Forms\Components\TextInput::make('title')->label('عنوان')->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                        if ($operation === 'create') {
                            $set('slug', Str::slug($state) ?: 'page-' . Str::random(6));
                        }
                    }),
                Forms\Components\TextInput::make('slug')->label('اسلاگ (آدرس)')->required()
                    ->unique(ignoreRecord: true)->maxLength(150)->prefix('/pages/'),
                Forms\Components\TextInput::make('excerpt')->label('خلاصه')->maxLength(255)->columnSpanFull(),
                Forms\Components\RichEditor::make('content')->label('محتوا')->columnSpanFull()
                    ->disableToolbarButtons(['attachFiles']),
            ])->columns(2),

            Forms\Components\Section::make('سئو و اشتراک‌گذاری')->collapsed()->schema([
                Forms\Components\TextInput::make('meta_title')->label('عنوان سئو')->maxLength(70)
                    ->helperText('طول پیشنهادی: ۵۰ تا ۶۰ کاراکتر. خالی بگذارید تا از عنوان صفحه استفاده شود.'),
                Forms\Components\TextInput::make('meta_keywords')->label('کلمات کلیدی')->maxLength(255),
                Forms\Components\Textarea::make('meta_description')->label('توضیحات سئو')->rows(2)->maxLength(180)->columnSpanFull()
                    ->helperText('طول پیشنهادی: ۱۲۰ تا ۱۶۰ کاراکتر. خالی بگذارید تا از خلاصه صفحه استفاده شود.'),
                Forms\Components\TextInput::make('canonical_url')->label('آدرس کنونیکال')->url()->maxLength(255)
                    ->placeholder('خالی = ساخت خودکار')->columnSpanFull(),
                Forms\Components\Toggle::make('robots_index')->label('اجازه ایندکس')->default(true),
                Forms\Components\Toggle::make('robots_follow')->label('اجازه دنبال‌کردن لینک‌ها')->default(true),
                Forms\Components\TextInput::make('og_title')->label('عنوان اشتراک‌گذاری')->maxLength(255),
                Forms\Components\Textarea::make('og_description')->label('توضیحات اشتراک‌گذاری')->rows(2),
                Forms\Components\FileUpload::make('og_image')->label('تصویر اشتراک‌گذاری')
                    ->image()->disk('public')->directory('pages')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
                Forms\Components\TextInput::make('twitter_title')->label('عنوان توییتر')->maxLength(255),
                Forms\Components\Textarea::make('twitter_description')->label('توضیحات توییتر')->rows(2),
                Forms\Components\TextInput::make('schema_type')->label('نوع اسکیما')->placeholder('WebPage, Article, …')->maxLength(60),
            ])->columns(2),

            Forms\Components\Section::make('سایت‌مپ')->collapsed()
                ->schema(\App\Filament\Support\SeoFormFields::sitemapFields())->columns(3),

            Forms\Components\Section::make('نمایش')->schema([
                Forms\Components\TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
                Forms\Components\Toggle::make('show_in_footer')->label('نمایش در فوتر')->default(false),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('آدرس')->fontFamily('mono')->size('sm')->prefix('/pages/'),
                Tables\Columns\IconColumn::make('show_in_footer')->label('فوتر')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('ویرایش')->dateTime('Y/m/d')->sortable()->toggleable(),
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
            'index'  => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit'   => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
