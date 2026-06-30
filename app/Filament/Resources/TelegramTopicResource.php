<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramTopicResource\Pages;
use App\Models\TelegramAdminTopic;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TelegramTopicResource extends Resource
{
    protected static ?string $model = TelegramAdminTopic::class;

    protected static ?string $navigationIcon   = 'heroicon-o-hashtag';
    protected static ?string $navigationGroup   = 'اعلان‌ها و پیام‌ها';
    protected static ?string $navigationLabel   = 'تاپیک‌های تلگرام';
    protected static ?string $modelLabel        = 'تاپیک تلگرام';
    protected static ?string $pluralModelLabel  = 'تاپیک‌های تلگرام';
    protected static ?int    $navigationSort    = 31;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('key')
                    ->label('کلید')->required()->maxLength(60)
                    ->disabled(fn ($record) => $record !== null)
                    ->dehydrated()
                    ->helperText('کلید ثابت دسته (تغییر ندهید).'),
                Forms\Components\TextInput::make('title')->label('عنوان')->required()->maxLength(120),
                Forms\Components\TextInput::make('message_thread_id')
                    ->label('شناسه تاپیک (message_thread_id)')
                    ->numeric()->nullable()
                    ->helperText('از تنظیمات گروه/تاپیک تلگرام به دست می‌آید.'),
                Forms\Components\TextInput::make('chat_id')->label('Chat ID اختصاصی (اختیاری)')->nullable(),
                Forms\Components\TextInput::make('sort_order')->label('ترتیب')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('فعال')->default(true),
                Forms\Components\Textarea::make('description')->label('توضیح')->rows(2)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('60px'),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->searchable(),
                Tables\Columns\TextColumn::make('key')->label('کلید')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('message_thread_id')->label('Thread ID')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
                Tables\Columns\TextColumn::make('last_sent_at')->label('آخرین ارسال')->dateTime('Y/m/d H:i')->placeholder('—')->toggleable(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramTopics::route('/'),
            'edit'  => Pages\EditTelegramTopic::route('/{record}/edit'),
        ];
    }
}
