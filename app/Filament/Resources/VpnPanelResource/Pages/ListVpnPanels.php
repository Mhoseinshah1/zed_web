<?php

namespace App\Filament\Resources\VpnPanelResource\Pages;

use App\Filament\Resources\VpnPanelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVpnPanels extends ListRecords
{
    protected static string $resource = VpnPanelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('افزودن پنل VPN'),
        ];
    }
}
