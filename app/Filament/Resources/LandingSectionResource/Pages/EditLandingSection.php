<?php

namespace App\Filament\Resources\LandingSectionResource\Pages;

use App\Filament\Resources\LandingSectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLandingSection extends EditRecord
{
    protected static string $resource = LandingSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
