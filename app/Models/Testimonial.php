<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'name', 'role', 'body', 'rating', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'rating'     => 'integer',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** First letter for the avatar circle. */
    public function initial(): string
    {
        return mb_substr(trim($this->name), 0, 1) ?: '؟';
    }

    /** Clamped 1–5 star rating. */
    public function stars(): int
    {
        return max(1, min(5, (int) $this->rating));
    }
}
