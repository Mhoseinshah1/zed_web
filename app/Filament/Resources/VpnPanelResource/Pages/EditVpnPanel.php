<?php

namespace App\Filament\Resources\VpnPanelResource\Pages;

use App\Filament\Resources\VpnPanelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVpnPanel extends EditRecord
{
    protected static string $resource = VpnPanelResource::class;

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
