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
        'name', 'slug', 'description', 'traffic_gb', 'duration_days',
        'price_toman', 'old_price_toman', 'is_active', 'is_featured',
        'is_economic', 'sort_order', 'badge',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'is_featured' => 'boolean',
        'is_economic' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_plan');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
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
}
