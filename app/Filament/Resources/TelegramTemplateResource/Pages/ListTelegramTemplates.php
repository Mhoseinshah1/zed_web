<?php

namespace App\Filament\Resources\TelegramTemplateResource\Pages;

use App\Filament\Resources\TelegramTemplateResource;
use App\Services\Telegram\TelegramTemplates;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTelegramTemplates extends ListRecords
{
    protected static string $resource = TelegramTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedDefaults')
                ->label('ایجاد قالب‌های پیش‌فرض')
                ->icon('heroicon-o-sparkles')
                ->action(function () {
                    app(TelegramTemplates::class)->seedDefaults();
                    Notification::make()->title('قالب‌های پیش‌فرض ایجاد شد.')->success()->send();
                }),
        ];
    }
}
