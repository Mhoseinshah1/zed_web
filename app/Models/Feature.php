<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Feature extends Model
{
    use HasFactory;
    protected $fillable = [
        'title', 'slug', 'description', 'icon', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'feature_plan');
    }

    public static function booted(): void
    {
        static::creating(function (Feature $feature) {
            if (empty($feature->slug)) {
                $feature->slug = Str::slug($feature->title, '-', 'fa');
                if (empty($feature->slug)) {
                    $feature->slug = Str::slug('feature-' . uniqid());
                }
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
