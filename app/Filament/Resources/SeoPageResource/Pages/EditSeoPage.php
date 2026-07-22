<?php

namespace App\Filament\Resources\SeoPageResource\Pages;

use App\Filament\Resources\SeoPageResource;
use Filament\Resources\Pages\EditRecord;

class EditSeoPage extends EditRecord
{
    protected static string $resource = SeoPageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
