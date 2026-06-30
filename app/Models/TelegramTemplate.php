<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Editable Telegram message templates (same pattern as NotificationTemplate).
 * Falls back to built-in defaults (see TelegramTemplates) when none exists.
 */
class TelegramTemplate extends Model
{
    protected $fillable = [
        'key', 'title', 'message', 'is_active', 'available_variables',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
