<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_CREDITED  = 'credited';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REVERSED  = 'reversed';

    const TYPE_PERCENT = 'percent';
    const TYPE_FIXED   = 'fixed';

    protected $fillable = [
        'representative_user_id',
        'referred_user_id',
        'order_id',
        'order_type',
        'original_amount',
        'final_amount',
        'commission_type',
        'commission_value',
        'commission_amount',
        'status',
        'credited_at',
        'cancelled_at',
        'admin_note',
        'metadata',
    ];

    protected $casts = [
        'original_amount'   => 'integer',
        'final_amount'      => 'integer',
        'commission_value'  => 'integer',
        'commission_amount' => 'integer',
        'credited_at'       => 'datetime',
        'cancelled_at'      => 'datetime',
        'metadata'          => 'array',
    ];

    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'representative_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING   => 'در انتظار',
            self::STATUS_CREDITED  => 'پرداخت‌شده',
            self::STATUS_CANCELLED => 'لغوشده',
            self::STATUS_REVERSED  => 'برگشت‌خورده',
        ];
    }
}
