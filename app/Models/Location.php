<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;
    protected $fillable = [
        'country_name', 'country_code', 'flag_emoji',
        'description', 'is_active', 'is_youtube_special', 'sort_order',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'is_youtube_special' => 'boolean',
        'sort_order'        => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
