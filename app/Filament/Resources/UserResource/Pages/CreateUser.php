<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\EmailUniqueViolationProbe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

        try {
            // Own transaction (a savepoint under tests): a failed INSERT must
            // never poison an outer PostgreSQL transaction.
            $user = DB::transaction(
                fn () => static::getModel()::create($data),
            );
        } catch (QueryException $e) {
            // TOCTOU race past the form's case-insensitive rule: the DB
            // unique index is the final authority — an EMAIL collision
            // becomes a normal field error, anything else stays fatal.
            EmailUniqueViolationProbe::translateOrRethrow($e, 'data.email');
        }
        $user->forceFill(['is_admin' => $isAdmin])->save();

        return $user;
    }
}
