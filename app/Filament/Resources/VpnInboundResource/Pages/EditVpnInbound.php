<?php

namespace App\Filament\Resources\VpnInboundResource\Pages;

use App\Filament\Resources\VpnInboundResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVpnInbound extends EditRecord
{
    protected static string $resource = VpnInboundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
