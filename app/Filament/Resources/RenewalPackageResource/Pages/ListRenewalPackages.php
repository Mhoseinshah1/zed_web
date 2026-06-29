<?php

namespace App\Filament\Resources\RenewalPackageResource\Pages;

use App\Filament\Resources\RenewalPackageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRenewalPackages extends ListRecords
{
    protected static string $resource = RenewalPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('پکیج جدید'),
        ];
    }
}
