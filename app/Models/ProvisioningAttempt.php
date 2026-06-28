<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningAttempt extends Model
{
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS    = 'success';
    const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'order_id',
        'user_id',
        'user_service_id',
        'vpn_panel_id',
        'status',
        'attempt_number',
        'request_payload',
        'response_payload',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
        'attempt_number'   => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userService(): BelongsTo
    {
        return $this->belongsTo(UserService::class);
    }

    public function vpnPanel(): BelongsTo
    {
        return $this->belongsTo(VpnPanel::class);
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING    => 'در انتظار',
            self::STATUS_PROCESSING => 'در حال انجام',
            self::STATUS_SUCCESS    => 'موفق',
            self::STATUS_FAILED     => 'ناموفق',
            default                 => $this->status,
        };
    }
}
