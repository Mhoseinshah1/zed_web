<?php

namespace App\Filament\Resources\UserServiceResource\Pages;

use App\Filament\Resources\UserServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUserService extends CreateRecord
{
    protected static string $resource = UserServiceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
