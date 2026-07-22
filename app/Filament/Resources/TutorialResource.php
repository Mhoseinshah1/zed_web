<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TutorialResource\Pages;
use App\Models\Tutorial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TutorialResource extends Resource
{
    protected static ?string $model = Tutorial::class;

    protected static ?string $navigationIcon   = 'heroicon-o-academic-cap';
    protected static ?string $navigationGroup   = 'مدیریت محتوا';
    protected static ?string $navigationLabel   = 'آموزش‌ها';
    protected static ?string $modelLabel        = 'آموزش';
    protected static ?string $pluralModelLabel  = 'آموزش‌ها';
    protected static ?int    $navigationSort    = 90;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('محتوای آموزش')->schema([
                Forms\Components\TextInput::make('title')->label('عنوان')->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                        if ($operation === 'create') {
                            $set('slug', Str::slug($state) ?: 'tutorial-' . Str::random(6));
                        }
                    }),
                Forms\Components\TextInput::make('slug')->label('اسلاگ (آدرس)')->required()
                    ->unique(ignoreRecord: true)->maxLength(150)->prefix('/tutorials/'),
                Forms\Components\Select::make('platform')->label('پلتفرم')
                    ->options(Tutorial::platforms())->required()->default('general')->native(false),
                Forms\Components\TextInput::make('video_url')->label('لینک ویدیو')->url()->maxLength(255),
                Forms\Components\TextInput::make('short_description')->label('توضیح کوتاه')->maxLength(255)->columnSpanFull(),
                Forms\Components\RichEditor::make('content')->label('محتوا')->columnSpanFull()
                    ->disableToolbarButtons(['attachFiles']),
                Forms\Components\FileUpload::make('image')->label('تصویر')
                    ->image()->disk('public')->directory('tutorials')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
            ])->columns(2),

            Forms\Components\Section::make('سئو و نمایش')->collapsed()->schema([
                Forms\Components\TextInput::make('meta_title')->label('عنوان سئو')->maxLength(70)
                    ->helperText('خالی = عنوان آموزش. طول پیشنهادی ۵۰ تا ۶۰ کاراکتر.'),
                Forms\Components\Textarea::make('meta_description')->label('توضیحات سئو')->rows(2)->maxLength(180),
                Forms\Components\TextInput::make('canonical_url')->label('آدرس کنونیکال')->url()->maxLength(255)
                    ->placeholder('خالی = ساخت خودکار')->columnSpanFull(),
                Forms\Components\Toggle::make('robots_index')->label('اجازه ایندکس')->default(true),
                Forms\Components\Toggle::make('robots_follow')->label('اجازه دنبال‌کردن لینک‌ها')->default(true),
                Forms\Components\TextInput::make('og_title')->label('عنوان اشتراک‌گذاری')->maxLength(255),
                Forms\Components\Textarea::make('og_description')->label('توضیحات اشتراک‌گذاری')->rows(2),
                Forms\Components\FileUpload::make('og_image')->label('تصویر اشتراک‌گذاری')
                    ->image()->disk('public')->directory('tutorials')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
                Forms\Components\TextInput::make('twitter_title')->label('عنوان توییتر')->maxLength(255),
                Forms\Components\Textarea::make('twitter_description')->label('توضیحات توییتر')->rows(2),
                Forms\Components\Select::make('schema_type')->label('نوع اسکیما')
                    ->options([
                        'Article'     => 'مقاله (Article)',
                        'TechArticle' => 'مقاله فنی (TechArticle)',
                        'HowTo'       => 'راهنمای گام‌به‌گام (HowTo)',
                    ])->default('Article')->native(false)
                    ->helperText('HowTo فقط زمانی استفاده می‌شود که مراحل واقعی وجود داشته باشد؛ در غیر این صورت به TechArticle تبدیل می‌شود.'),
                Forms\Components\TextInput::make('author_name')->label('نام نویسنده')->maxLength(120)
                    ->helperText('اختیاری — تنها در صورت وجود، در اسکیما درج می‌شود.'),
                Forms\Components\DateTimePicker::make('published_at')->label('تاریخ انتشار'),
                Forms\Components\TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('سایت‌مپ')->collapsed()
                ->schema(\App\Filament\Support\SeoFormFields::sitemapFields())->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('50px'),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('platform')->label('پلتفرم')->badge()
                    ->formatStateUsing(fn ($state) => Tutorial::platforms()[$state] ?? $state),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('ویرایش')->dateTime('Y/m/d')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')->label('پلتفرم')->options(Tutorial::platforms()),
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
            'index'  => Pages\ListTutorials::route('/'),
            'create' => Pages\CreateTutorial::route('/create'),
            'edit'   => Pages\EditTutorial::route('/{record}/edit'),
        ];
    }
}
