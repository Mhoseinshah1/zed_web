<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * is_admin is intentionally not mass-assignable on the model, so set it
     * explicitly here (this is the trusted admin panel). Every other field
     * still goes through normal mass assignment.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (array_key_exists('is_admin', $data)) {
            $isAdmin = (bool) $data['is_admin'];
            unset($data['is_admin']);
            $record->forceFill(['is_admin' => $isAdmin]);
        }

        $record->fill($data);
        $record->save();

        return $record;
    }
}
