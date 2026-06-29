<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;

/**
 * Shared "account id" column for user-related resources.
 *
 * Displays the owning user's 6-digit account id and makes the resource
 * searchable by account_id, phone, normalized_phone, email, name and user id —
 * so an admin can paste an account id like 384921 into orders, services,
 * payments, wallet transactions, etc. and find that user's records.
 */
class UserAccountColumn
{
    public static function make(string $relation = 'user'): TextColumn
    {
        return TextColumn::make("{$relation}.account_id")
            ->label('شناسه اکانت')
            ->fontFamily('mono')
            ->placeholder('—')
            ->toggleable()
            ->searchable(query: function ($query, string $search) use ($relation) {
                return $query->whereHas($relation, function ($q) use ($search) {
                    $q->where('account_id', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('normalized_phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                    if (ctype_digit($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            });
    }
}
