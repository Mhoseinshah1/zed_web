<?php

namespace App\Filament\Resources\RenewalPackageResource\Pages;

use App\Filament\Resources\RenewalPackageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRenewalPackage extends CreateRecord
{
    protected static string $resource = RenewalPackageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
