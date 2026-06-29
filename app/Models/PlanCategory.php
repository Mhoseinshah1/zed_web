<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PlanCategory extends Model
{
    protected $fillable = [
        'title', 'slug', 'description', 'icon', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (PlanCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->title, '-', 'fa') ?: 'cat-' . uniqid();
            }
        });
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
