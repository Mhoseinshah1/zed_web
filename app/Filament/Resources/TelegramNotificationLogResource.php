<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramNotificationLogResource\Pages;
use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only audit of admin Telegram notifications. The bot token never appears
 * here — only the safe, already-escaped message summary.
 */
class TelegramNotificationLogResource extends Resource
{
    protected static ?string $model = TelegramAdminNotificationLog::class;

    protected static ?string $navigationIcon   = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup   = 'اعلان‌ها و پیام‌ها';
    protected static ?string $navigationLabel   = 'لاگ بات تلگرام';
    protected static ?string $modelLabel        = 'لاگ تلگرام';
    protected static ?string $pluralModelLabel  = 'لاگ بات تلگرام';
    protected static ?int    $navigationSort    = 32;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('زمان')->dateTime('Y/m/d H:i')->sortable(),
                Tables\Columns\TextColumn::make('topic_key')->label('تاپیک')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('event_key')->label('رویداد')->searchable(),
                Tables\Columns\TextColumn::make('title')->label('عنوان')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()
                    ->formatStateUsing(fn ($state) => TelegramAdminNotificationLog::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        TelegramAdminNotificationLog::STATUS_SENT    => 'success',
                        TelegramAdminNotificationLog::STATUS_FAILED  => 'danger',
                        TelegramAdminNotificationLog::STATUS_PENDING => 'warning',
                        default                                      => 'gray',
                    }),
                Tables\Columns\TextColumn::make('error')->label('خطا')->limit(40)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('sent_at')->label('ارسال')->dateTime('Y/m/d H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options(TelegramAdminNotificationLog::statusLabels()),
                SelectFilter::make('topic_key')->label('تاپیک')
                    ->options(fn () => TelegramAdminTopic::query()->pluck('title', 'key')->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramNotificationLogs::route('/'),
        ];
    }
}
