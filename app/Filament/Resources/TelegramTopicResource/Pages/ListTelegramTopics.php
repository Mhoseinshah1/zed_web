<?php

namespace App\Filament\Resources\TelegramTopicResource\Pages;

use App\Filament\Resources\TelegramTopicResource;
use App\Models\TelegramAdminTopic;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTelegramTopics extends ListRecords
{
    protected static string $resource = TelegramTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedDefaults')
                ->label('ایجاد تاپیک‌های پیش‌فرض')
                ->icon('heroicon-o-sparkles')
                ->action(function () {
                    TelegramAdminTopic::seedDefaults();
                    Notification::make()->title('تاپیک‌های پیش‌فرض ایجاد/به‌روزرسانی شد.')->success()->send();
                }),
        ];
    }
}
