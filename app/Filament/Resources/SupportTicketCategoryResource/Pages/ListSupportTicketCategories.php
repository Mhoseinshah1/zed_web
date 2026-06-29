<?php
namespace App\Filament\Resources\SupportTicketCategoryResource\Pages;
use App\Filament\Resources\SupportTicketCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListSupportTicketCategories extends ListRecords
{
    protected static string $resource = SupportTicketCategoryResource::class;
    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
