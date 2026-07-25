<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Email\EmailVerificationService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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
        // require the user to verify it with a fresh OTP. The change goes
        // through the SAME lock-protected service path as the user's own
        // flow, so it serializes against issuance, verification and an
        // in-flight delivery job — and a lost uniqueness race surfaces as a
        // normal field validation error, never a 500 (with the record's
        // original email/timestamp/codes untouched).
        $markVerified = (bool) ($data['email_change_mark_verified'] ?? true);
        unset($data['email_change_mark_verified']);
        if (array_key_exists('email', $data)) {
            $newEmail = strtolower(trim((string) $data['email']));
            unset($data['email']);
            if ($newEmail !== strtolower((string) $record->email)) {
                $changed = app(EmailVerificationService::class)->changeAddressTo(
                    $record,
                    $newEmail,
                    markVerified: $markVerified,
                    errorAttribute: 'data.email',
                );
                if (! $changed) {
                    throw ValidationException::withMessages([
                        'data.email' => EmailVerificationService::BUSY_MESSAGE,
                    ]);
                }
            }
        }

        $record->fill($data);
        $record->save();

        return $record;
    }
}
