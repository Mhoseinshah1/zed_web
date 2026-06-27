<?php

namespace App\Filament\Resources\SiteTextResource\Pages;

use App\Filament\Resources\SiteTextResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteTexts extends ListRecords
{
    protected static string $resource = SiteTextResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
