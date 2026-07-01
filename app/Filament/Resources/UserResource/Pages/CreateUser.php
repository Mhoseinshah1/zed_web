<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * is_admin is intentionally not mass-assignable on the model, so set it
     * explicitly here (this is the trusted admin panel). Every other field
     * still goes through normal mass assignment.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $isAdmin = (bool) ($data['is_admin'] ?? false);
        unset($data['is_admin']);

        $user = static::getModel()::create($data);
        $user->forceFill(['is_admin' => $isAdmin])->save();

        return $user;
    }
}
