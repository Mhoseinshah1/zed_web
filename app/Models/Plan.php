<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'slug', 'description', 'short_description', 'feature_list',
        'traffic_gb', 'duration_days',
        'price_toman', 'old_price_toman', 'is_active', 'is_featured',
        'is_economic', 'sort_order', 'badge', 'badge_text', 'badge_type',
        'category_id',
        'renewal_enabled', 'renewal_price', 'renewal_duration_days',
        'renewal_cashback_enabled', 'renewal_cashback_type', 'renewal_cashback_value',
    ];

    protected $casts = [
        'is_active'                => 'boolean',
        'is_featured'              => 'boolean',
        'is_economic'              => 'boolean',
        'sort_order'               => 'integer',
        'feature_list'             => 'array',
        'renewal_enabled'          => 'boolean',
        'renewal_price'            => 'integer',
        'renewal_duration_days'    => 'integer',
        'renewal_cashback_enabled' => 'boolean',
        'renewal_cashback_value'   => 'integer',
    ];

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_plan');
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlanCategory::class, 'category_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(UserService::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Plan $plan) {
            if (empty($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function formattedPrice(): string
    {
        return number_format($this->price_toman);
    }

    public function trafficLabel(): string
    {
        return $this->traffic_gb ? $this->traffic_gb . ' گیگابایت' : 'نامحدود';
    }

    public function durationLabel(): string
    {
        return $this->duration_days ? $this->duration_days . ' روزه' : 'نامحدود';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price_toman');
    }

    public function scopeRenewable($query)
    {
        return $query->where('is_active', true)->where('renewal_enabled', true);
    }

    public function effectiveRenewalPrice(): int
    {
        return $this->renewal_price ?? $this->price_toman;
    }

    public function effectiveRenewalDays(): ?int
    {
        return $this->renewal_duration_days ?? $this->duration_days;
    }

    public function effectiveCashbackAmount(): ?int
    {
        if (! $this->renewal_cashback_enabled || ! $this->renewal_cashback_value) {
            return null;
        }
        $price = $this->effectiveRenewalPrice();
        if ($this->renewal_cashback_type === 'percent') {
            return (int) round($price * $this->renewal_cashback_value / 100);
        }

        return (int) $this->renewal_cashback_value;
    }
}
