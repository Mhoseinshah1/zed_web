<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramTemplateResource\Pages;
use App\Models\TelegramTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TelegramTemplateResource extends Resource
{
    protected static ?string $model = TelegramTemplate::class;

    protected static ?string $navigationIcon   = 'heroicon-o-document-text';
    protected static ?string $navigationGroup   = 'اعلان‌ها و پیام‌ها';
    protected static ?string $navigationLabel   = 'قالب پیام‌های تلگرام';
    protected static ?string $modelLabel        = 'قالب پیام تلگرام';
    protected static ?string $pluralModelLabel  = 'قالب پیام‌های تلگرام';
    protected static ?int    $navigationSort    = 33;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('key')
                    ->label('کلید رویداد')->required()->maxLength(60)
                    ->disabled(fn ($record) => $record !== null)->dehydrated(),
                Forms\Components\TextInput::make('title')->label('عنوان')->required()->maxLength(150),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
                Forms\Components\Textarea::make('message')
                    ->label('متن پیام')->rows(6)->required()->columnSpanFull()
                    ->helperText('از متغیرهای {...} استفاده کنید. مقادیر کاربر قبل از ارسال ایمن‌سازی می‌شوند. تگ‌های ساده HTML مثل <b> مجاز است.'),
                Forms\Components\TextInput::make('available_variables')
                    ->label('متغیرهای در دسترس')->columnSpanFull()->disabled()->dehydrated(false),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('کلید')->badge()->color('gray')->searchable(),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable(),
                Tables\Columns\TextColumn::make('available_variables')->label('متغیرها')->limit(50)->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramTemplates::route('/'),
            'edit'  => Pages\EditTelegramTemplate::route('/{record}/edit'),
        ];
    }
}
