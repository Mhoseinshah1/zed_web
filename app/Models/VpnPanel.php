<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnPanel extends Model
{
    const TYPE_MARZBAN    = 'marzban';
    const TYPE_XUI        = 'xui';
    const TYPE_SANAEI_XUI = 'sanaei_3xui';
    const TYPE_OTHER      = 'other';

    protected $fillable = [
        'name',
        'type',
        'base_url',
        'api_docs_url',
        'username',
        'password',
        'token',
        'is_active',
        'is_default',
        'notes',
        'last_checked_at',
        'last_error',
        'last_health_checked_at',
        'health_status',
        'health_error',
        'system_info',
        'allow_user_sync_service',
        'allow_user_revoke_subscription',
        'allow_user_reset_traffic',
        'allow_user_disable_service',
        'allow_user_enable_service',
        'allow_user_view_subscription_qr',
        'allow_user_view_config_qr',
        'allow_user_copy_subscription_link',
        'allow_user_copy_config_link',
        'allow_user_view_all_config_links',
    ];

    const HEALTH_ONLINE  = 'online';
    const HEALTH_OFFLINE = 'offline';

    protected $casts = [
        'is_active'                        => 'boolean',
        'is_default'                       => 'boolean',
        'last_checked_at'                  => 'datetime',
        'last_health_checked_at'           => 'datetime',
        'system_info'                      => 'array',
        'password'                         => 'encrypted',
        'token'                            => 'encrypted',
        'allow_user_sync_service'          => 'boolean',
        'allow_user_revoke_subscription'   => 'boolean',
        'allow_user_reset_traffic'         => 'boolean',
        'allow_user_disable_service'       => 'boolean',
        'allow_user_enable_service'        => 'boolean',
        'allow_user_view_subscription_qr'  => 'boolean',
        'allow_user_view_config_qr'        => 'boolean',
        'allow_user_copy_subscription_link' => 'boolean',
        'allow_user_copy_config_link'      => 'boolean',
        'allow_user_view_all_config_links' => 'boolean',
    ];

    protected $attributes = [
        'allow_user_sync_service'           => true,
        'allow_user_revoke_subscription'    => true,
        'allow_user_reset_traffic'          => false,
        'allow_user_disable_service'        => false,
        'allow_user_enable_service'         => false,
        'allow_user_view_subscription_qr'   => true,
        'allow_user_view_config_qr'         => true,
        'allow_user_copy_subscription_link' => true,
        'allow_user_copy_config_link'       => true,
        'allow_user_view_all_config_links'  => true,
    ];

    protected $hidden = ['password', 'token'];

    public function inbounds(): HasMany
    {
        return $this->hasMany(VpnInbound::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(UserService::class);
    }

    public function provisionLogs(): HasMany
    {
        return $this->hasMany(VpnServiceProvisionLog::class);
    }

    public function typeLabel(): string
    {
        return match($this->type) {
            self::TYPE_MARZBAN    => 'Marzban',
            self::TYPE_XUI        => 'X-UI',
            self::TYPE_SANAEI_XUI => '3X-UI (Sanaei)',
            self::TYPE_OTHER      => 'سایر',
            default               => $this->type,
        };
    }

    public static function allTypes(): array
    {
        return [
            self::TYPE_MARZBAN    => 'Marzban',
            self::TYPE_XUI        => 'X-UI',
            self::TYPE_SANAEI_XUI => '3X-UI (Sanaei)',
            self::TYPE_OTHER      => 'سایر',
        ];
    }
}
