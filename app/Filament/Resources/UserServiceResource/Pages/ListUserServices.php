<?php

namespace App\Filament\Resources\UserServiceResource\Pages;

use App\Filament\Resources\UserServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUserServices extends ListRecords
{
    protected static string $resource = UserServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('ایجاد سرویس جدید'),
        ];
    }
}
