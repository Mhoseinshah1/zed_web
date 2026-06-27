<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnServiceProvisionLog extends Model
{
    protected $fillable = [
        'user_service_id',
        'vpn_panel_id',
        'action',
        'status',
        'message',
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
    ];

    public function userService(): BelongsTo
    {
        return $this->belongsTo(UserService::class);
    }

    public function vpnPanel(): BelongsTo
    {
        return $this->belongsTo(VpnPanel::class);
    }
}
