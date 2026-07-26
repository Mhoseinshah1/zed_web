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
     * The WHOLE trusted admin creation is ONE transaction: the user row, the
     * explicit is_admin assignment, the explicit verification state (from the
     * create form's toggle — never a mass-assigned timestamp), and the
     * per-user obligation marker commit together or not at all. Admin-created
     * accounts are DELIBERATELY never auto-obligated to verify (marker stays
     * false regardless of the current global registration policy) — an admin
     * who wants an unverified account chose that state explicitly, and
     * admins themselves remain middleware-exempt anyway.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $isAdmin = (bool) ($data['is_admin'] ?? false);
        $isVerified = (bool) ($data['email_is_verified'] ?? false);
        unset($data['is_admin'], $data['email_is_verified'], $data['email_verified_at']);

        try {
            // Own transaction (a savepoint under tests): a failed INSERT must
            // never poison an outer PostgreSQL transaction.
            $user = DB::transaction(function () use ($data, $isAdmin, $isVerified) {
                $user = static::getModel()::create($data);
                $user->forceFill([
                    'is_admin' => $isAdmin,
                    'email_verified_at' => $isVerified ? now() : null,
                    'email_verification_required_at_registration' => false,
                ])->save();

                return $user;
            });
        } catch (QueryException $e) {
            // TOCTOU race past the form's case-insensitive rule: the DB
            // unique index is the final authority — an EMAIL collision
            // becomes a normal field error, anything else stays fatal.
            EmailUniqueViolationProbe::translateOrRethrow($e, 'data.email');
        }

        return $user;
    }
}
