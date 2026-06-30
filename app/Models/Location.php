<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;
    protected $fillable = [
        'country_name', 'country_code', 'flag_emoji',
        'latitude', 'longitude', 'ping_ms',
        'description', 'is_active', 'is_youtube_special', 'sort_order',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'is_youtube_special' => 'boolean',
        'sort_order'        => 'integer',
        'latitude'          => 'float',
        'longitude'         => 'float',
        'ping_ms'           => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /** Active locations that have both coordinates (plottable on the map). */
    public function scopeMappable($query)
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Project lat/lng onto the 1000×500 equirectangular map viewBox used by the
     * world-map partial. Returns ['x' => float, 'y' => float] or null.
     */
    public function mapPoint(): ?array
    {
        if (! $this->hasCoordinates()) {
            return null;
        }
        return [
            'x' => round((($this->longitude + 180) / 360) * 1000, 1),
            'y' => round(((90 - $this->latitude) / 180) * 500, 1),
        ];
    }
}
