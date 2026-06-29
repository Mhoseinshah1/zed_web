<?php

namespace App\Filament\Resources\PlanCategoryResource\Pages;

use App\Filament\Resources\PlanCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlanCategories extends ListRecords
{
    protected static string $resource = PlanCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
