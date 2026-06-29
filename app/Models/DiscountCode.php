<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscountCode extends Model
{
    use SoftDeletes;

    const TYPE_PERCENT = 'percent';
    const TYPE_FIXED   = 'fixed';

    protected $fillable = [
        'title',
        'code',
        'type',
        'value',
        'max_discount_amount',
        'min_order_amount',
        'total_usage_limit',
        'per_user_usage_limit',
        'starts_at',
        'expires_at',
        'is_active',
        'first_purchase_only',
        'new_users_only',
        'allowed_plan_ids',
        'allowed_order_types',
        'admin_note',
    ];

    protected $casts = [
        'starts_at'           => 'datetime',
        'expires_at'          => 'datetime',
        'is_active'           => 'boolean',
        'first_purchase_only' => 'boolean',
        'new_users_only'      => 'boolean',
        'allowed_plan_ids'    => 'array',
        'allowed_order_types' => 'array',
        'value'               => 'integer',
        'max_discount_amount' => 'integer',
        'min_order_amount'    => 'integer',
        'total_usage_limit'   => 'integer',
        'per_user_usage_limit'=> 'integer',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * Whether this code may be used for the given order type.
     * Empty/null allowed_order_types means all real purchase types are allowed.
     */
    public function allowsOrderType(?string $orderType): bool
    {
        if (empty($this->allowed_order_types)) {
            return true;
        }
        return in_array($orderType, $this->allowed_order_types, true);
    }

    public function usedCount(): int
    {
        return $this->redemptions()->where('status', DiscountRedemption::STATUS_USED)->count();
    }

    public function totalDiscountGiven(): int
    {
        return (int) $this->redemptions()->where('status', DiscountRedemption::STATUS_USED)->sum('discount_amount');
    }

    public function typeLabel(): string
    {
        return $this->type === self::TYPE_PERCENT ? 'درصدی' : 'مبلغ ثابت';
    }

    public function valueLabel(): string
    {
        if ($this->type === self::TYPE_PERCENT) {
            $label = "{$this->value}٪";
            if ($this->max_discount_amount) {
                $label .= ' (حداکثر ' . number_format($this->max_discount_amount) . ' تومان)';
            }
            return $label;
        }
        return number_format($this->value) . ' تومان';
    }

    public function statusLabel(): string
    {
        if (! $this->is_active) {
            return 'غیرفعال';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'منقضی';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'هنوز شروع نشده';
        }
        return 'فعال';
    }
}
