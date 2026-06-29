<?php

namespace App\Filament\Resources\PlanCategoryResource\Pages;

use App\Filament\Resources\PlanCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlanCategory extends EditRecord
{
    protected static string $resource = PlanCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
