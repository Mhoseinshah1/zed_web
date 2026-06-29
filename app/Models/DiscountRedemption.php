<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountRedemption extends Model
{
    const STATUS_RESERVED  = 'reserved';
    const STATUS_USED      = 'used';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED   = 'expired';

    protected $fillable = [
        'discount_code_id',
        'user_id',
        'order_id',
        'status',
        'original_amount',
        'discount_amount',
        'final_amount',
        'used_at',
    ];

    protected $casts = [
        'original_amount' => 'integer',
        'discount_amount' => 'integer',
        'final_amount'    => 'integer',
        'used_at'         => 'datetime',
    ];

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_RESERVED  => 'رزرو شده',
            self::STATUS_USED      => 'استفاده شده',
            self::STATUS_CANCELLED => 'لغو شده',
            self::STATUS_EXPIRED   => 'منقضی',
            default                => $this->status,
        };
    }
}
