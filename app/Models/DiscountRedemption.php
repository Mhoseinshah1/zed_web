<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount-code reservation with an explicit lifecycle:
 *
 *   reserved → used      (payment completed)
 *   reserved → released  (removed / replaced / cancelled / failed checkout)
 *   reserved → expired   (abandoned; reclaimed by the scheduled expirer)
 *
 * Capacity policy:
 *   - `used` consumes capacity permanently.
 *   - non-expired `reserved` consumes capacity temporarily.
 *   - `released` and `expired` do NOT consume capacity.
 */
class DiscountRedemption extends Model
{
    const STATUS_RESERVED = 'reserved';

    const STATUS_USED = 'used';

    const STATUS_RELEASED = 'released';

    const STATUS_EXPIRED = 'expired';

    /** @deprecated legacy alias, migrated to STATUS_RELEASED. Kept for BC only. */
    const STATUS_CANCELLED = 'released';

    protected $fillable = [
        'discount_code_id',
        'user_id',
        'order_id',
        'status',
        'original_amount',
        'discount_amount',
        'final_amount',
        'reserved_at',
        'expires_at',
        'used_at',
        'released_at',
    ];

    protected $casts = [
        'original_amount' => 'integer',
        'discount_amount' => 'integer',
        'final_amount' => 'integer',
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'released_at' => 'datetime',
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

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Redemptions that currently consume capacity: used, or reserved and not yet expired. */
    public function scopeConsumingCapacity(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', self::STATUS_USED)
                ->orWhere(function (Builder $r) {
                    $r->where('status', self::STATUS_RESERVED)
                        ->where(function (Builder $e) {
                            $e->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
        });
    }

    public function scopeReserved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    /** Reserved rows whose hold has expired (candidates for the expirer). */
    public function scopeExpirable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESERVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isUsed(): bool
    {
        return $this->status === self::STATUS_USED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_RESERVED => 'رزرو شده',
            self::STATUS_USED => 'استفاده شده',
            self::STATUS_RELEASED => 'آزاد شده',
            self::STATUS_EXPIRED => 'منقضی',
            default => $this->status,
        };
    }
}
