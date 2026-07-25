<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Email\EmailVerificationService;
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

        // Email changes NEVER silently retain the old verification timestamp.
        // The admin explicitly chooses: mark the new address verified, or
        // require the user to verify it with a fresh OTP.
        $markVerified = (bool) ($data['email_change_mark_verified'] ?? true);
        unset($data['email_change_mark_verified']);
        if (array_key_exists('email', $data)) {
            $newEmail = strtolower(trim((string) $data['email']));
            $data['email'] = $newEmail;
            if ($newEmail !== strtolower((string) $record->email)) {
                app(EmailVerificationService::class)->invalidateCodes($record);
                $record->forceFill([
                    'email_verified_at' => $markVerified ? now() : null,
                ]);
            }
        }

        $record->fill($data);
        $record->save();

        return $record;
    }
}
