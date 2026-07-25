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
     * The WHOLE admin edit — a possible email change (with the explicit
     * verified/unverified choice), is_admin, and every other field — commits
     * ATOMICALLY through one lock-protected service transaction: either the
     * complete edit lands or nothing does. Email uniqueness races and lock
     * contention surface as normal `data.email` field errors, never a 500;
     * unrelated database errors still rethrow.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(EmailVerificationService::class)->applyAdminUpdate($record, $data);
    }
}
