<?php

namespace App\Filament\Resources\SiteTextResource\Pages;

use App\Filament\Resources\SiteTextResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSiteText extends EditRecord
{
    protected static string $resource = SiteTextResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
