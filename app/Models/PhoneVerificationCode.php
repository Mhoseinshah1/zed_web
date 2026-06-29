<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneVerificationCode extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'normalized_phone',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
        'ip_address',
        'user_agent',
        'send_status',
        'send_error',
    ];

    const SEND_STATUS_SENT   = 'sent';
    const SEND_STATUS_FAILED = 'failed';
    const SEND_STATUS_SKIPPED = 'skipped';

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'attempts'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
