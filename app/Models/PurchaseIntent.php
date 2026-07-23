<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server-enforced idempotency record for one order-creation attempt.
 *
 * Lifecycle: pending → consumed (order created/linked) | expired (pruned).
 * The unique `key` is the database-level concurrency guarantee; the linked
 * order is returned verbatim to every retry/duplicate/concurrent submission.
 */
class PurchaseIntent extends Model
{
    const STATUS_PENDING  = 'pending';
    const STATUS_CONSUMED = 'consumed';
    const STATUS_EXPIRED  = 'expired';

    const OP_NEW_SERVICE   = 'new_service';
    const OP_RENEWAL       = 'renewal';
    const OP_EXTRA_TRAFFIC = 'extra_traffic';
    const OP_EXTRA_TIME    = 'extra_time';

    protected $fillable = [
        'key',
        'user_id',
        'operation_type',
        'plan_id',
        'user_service_id',
        'order_id',
        'request_fingerprint',
        'status',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->status === self::STATUS_CONSUMED;
    }

    /** Pending intents whose validity window has passed and that created no order. */
    public function scopePrunable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNull('order_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }
}
