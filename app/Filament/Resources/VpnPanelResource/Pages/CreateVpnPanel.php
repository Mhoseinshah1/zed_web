<?php

namespace App\Filament\Resources\VpnPanelResource\Pages;

use App\Filament\Resources\VpnPanelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnPanel extends CreateRecord
{
    protected static string $resource = VpnPanelResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
