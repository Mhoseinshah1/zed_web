<?php

namespace App\Filament\Resources\UserServiceResource\Pages;

use App\Filament\Resources\UserServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUserService extends EditRecord
{
    protected static string $resource = UserServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
