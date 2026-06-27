<?php

namespace App\Filament\Resources\VpnInboundResource\Pages;

use App\Filament\Resources\VpnInboundResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnInbound extends CreateRecord
{
    protected static string $resource = VpnInboundResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
