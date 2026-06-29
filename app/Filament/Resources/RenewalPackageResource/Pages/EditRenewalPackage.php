<?php

namespace App\Filament\Resources\RenewalPackageResource\Pages;

use App\Filament\Resources\RenewalPackageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRenewalPackage extends EditRecord
{
    protected static string $resource = RenewalPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
