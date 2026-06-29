<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'key',
        'title',
        'message',
        'is_active',
        'available_variables',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
