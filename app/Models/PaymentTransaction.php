<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_PAID      = 'paid';
    const STATUS_FAILED    = 'failed';
    const STATUS_REFUNDED  = 'refunded';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'user_id',
        'provider',
        'method',
        'status',
        'amount_toman',
        'currency',
        'reference_id',
        'external_id',
        'payload',
        'paid_at',
    ];

    protected $casts = [
        'amount_toman' => 'integer',
        'payload'      => 'array',
        'paid_at'      => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING   => 'در انتظار',
            self::STATUS_PAID      => 'پرداخت شده',
            self::STATUS_FAILED    => 'ناموفق',
            self::STATUS_REFUNDED  => 'برگشت داده شده',
            self::STATUS_CANCELLED => 'لغو شده',
            default                => $this->status,
        };
    }
}
