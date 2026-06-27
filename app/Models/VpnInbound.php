<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnInbound extends Model
{
    protected $fillable = [
        'vpn_panel_id',
        'name',
        'remote_inbound_id',
        'protocol',
        'port',
        'network',
        'security',
        'is_active',
        'is_default',
        'notes',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'port'       => 'integer',
    ];

    public function vpnPanel(): BelongsTo
    {
        return $this->belongsTo(VpnPanel::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(UserService::class);
    }
}
