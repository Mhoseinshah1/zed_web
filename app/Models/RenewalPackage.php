<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RenewalPackage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'duration_days',
        'price_toman',
        'is_active',
        'sort_order',
        'allowed_plan_ids',
        'admin_note',
    ];

    protected $casts = [
        'duration_days'   => 'integer',
        'price_toman'     => 'integer',
        'is_active'       => 'boolean',
        'sort_order'      => 'integer',
        'allowed_plan_ids' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'renewal_package_id');
    }

    public function formattedPrice(): string
    {
        return number_format($this->price_toman) . ' تومان';
    }

    public function durationLabel(): string
    {
        return $this->duration_days . ' روز';
    }

    /**
     * Whether this package is allowed for a given plan_id.
     * Empty/null allowed_plan_ids means unrestricted.
     */
    public function isAllowedForPlan(?int $planId): bool
    {
        if (empty($this->allowed_plan_ids)) {
            return true;
        }
        return in_array($planId, $this->allowed_plan_ids, false);
    }
}
