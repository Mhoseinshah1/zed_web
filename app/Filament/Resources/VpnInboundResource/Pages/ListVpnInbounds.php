<?php

namespace App\Filament\Resources\VpnInboundResource\Pages;

use App\Filament\Resources\VpnInboundResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVpnInbounds extends ListRecords
{
    protected static string $resource = VpnInboundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('افزودن اینباند'),
        ];
    }
}
