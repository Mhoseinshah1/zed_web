<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\Notification as NotificationModel;
use App\Models\NotificationTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static ?string $navigationIcon   = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup   = 'سیستم و یکپارچه‌سازی';
    protected static ?string $navigationLabel   = 'قالب پیام‌ها';
    protected static ?string $modelLabel        = 'قالب پیام';
    protected static ?string $pluralModelLabel  = 'قالب پیام‌ها';
    protected static ?int    $navigationSort    = 21;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('قالب پیام')->schema([
                Forms\Components\TextInput::make('key')
                    ->label('کلید رویداد')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($state) => (NotificationModel::typeLabels()[$state] ?? $state) . "  ({$state})"),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),

                Forms\Components\TextInput::make('title')
                    ->label('عنوان پیام')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('message')
                    ->label('متن پیام')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('available_variables_help')
                    ->label('متغیرهای قابل استفاده')
                    ->content(fn (?NotificationTemplate $record) => $record?->available_variables ?: '—')
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('رویداد')
                    ->formatStateUsing(fn ($state) => NotificationModel::typeLabels()[$state] ?? $state)
                    ->description(fn (NotificationTemplate $record) => $record->key)
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('message')
                    ->label('متن پیام')
                    ->limit(70)
                    ->wrap(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'edit'  => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
