<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UserService extends Model
{
    // Service statuses
    const STATUS_PENDING_PROVISION = 'pending_provision';
    const STATUS_ACTIVE            = 'active';
    const STATUS_DISABLED          = 'disabled';
    const STATUS_EXPIRED           = 'expired';
    const STATUS_CANCELLED         = 'cancelled';
    const STATUS_FAILED            = 'failed';

    // Provision statuses
    const PROVISION_PENDING         = 'pending';
    const PROVISION_MANUAL_REQUIRED = 'manual_required';
    const PROVISION_PROVISIONED     = 'provisioned';
    const PROVISION_FAILED          = 'failed';
    const PROVISION_SKIPPED         = 'skipped';

    protected $fillable = [
        'service_number',
        'user_id',
        'order_id',
        'plan_id',
        'name',
        'status',
        'provision_status',
        'plan_name',
        'traffic_total_gb',
        'traffic_used_gb',
        'traffic_remaining_gb',
        'duration_days',
        'starts_at',
        'expires_at',
        'activated_at',
        'disabled_at',
        'last_synced_at',
        'config_link',
        'subscription_link',
        'qr_code_path',
        'vpn_panel_id',
        'vpn_inbound_id',
        'remote_client_id',
        'remote_username',
        'admin_notes',
        'user_notes',
    ];

    protected $casts = [
        'traffic_total_gb'     => 'integer',
        'traffic_used_gb'      => 'integer',
        'traffic_remaining_gb' => 'integer',
        'duration_days'        => 'integer',
        'starts_at'            => 'datetime',
        'expires_at'           => 'datetime',
        'activated_at'         => 'datetime',
        'disabled_at'          => 'datetime',
        'last_synced_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserService $service) {
            if (empty($service->service_number)) {
                $service->service_number = self::generateServiceNumber();
            }
        });
    }

    private static function generateServiceNumber(): string
    {
        do {
            $number = 'SVC-' . date('Ymd') . '-' . strtoupper(Str::random(5));
        } while (self::where('service_number', $number)->exists());

        return $number;
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function vpnPanel(): BelongsTo
    {
        return $this->belongsTo(VpnPanel::class);
    }

    public function vpnInbound(): BelongsTo
    {
        return $this->belongsTo(VpnInbound::class);
    }

    public function provisionLogs(): HasMany
    {
        return $this->hasMany(VpnServiceProvisionLog::class);
    }

    // ── Status helpers ─────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) return true;
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function daysRemaining(): ?int
    {
        if (! $this->expires_at) return null;
        $days = (int) now()->diffInDays($this->expires_at, false);
        return max(0, $days);
    }

    public function trafficRemainingGb(): ?int
    {
        if ($this->traffic_total_gb === null) return null;
        return max(0, $this->traffic_total_gb - ($this->traffic_used_gb ?? 0));
    }

    public function markActive(?string $startsAt = null): void
    {
        $starts = $startsAt ? now()->parse($startsAt) : ($this->starts_at ?? now());
        $expires = $this->expires_at ?? (
            $this->duration_days ? $starts->copy()->addDays($this->duration_days) : null
        );

        $this->update([
            'status'           => self::STATUS_ACTIVE,
            'provision_status' => ($this->config_link || $this->subscription_link)
                ? self::PROVISION_PROVISIONED
                : self::PROVISION_MANUAL_REQUIRED,
            'activated_at'     => $this->activated_at ?? now(),
            'starts_at'        => $starts,
            'expires_at'       => $expires,
            'disabled_at'      => null,
        ]);
    }

    public function markDisabled(): void
    {
        $this->update([
            'status'      => self::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);
    }

    public function markExpired(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    public function markCancelled(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING_PROVISION => 'در انتظار ساخت',
            self::STATUS_ACTIVE            => 'فعال',
            self::STATUS_DISABLED          => 'غیرفعال',
            self::STATUS_EXPIRED           => 'منقضی شده',
            self::STATUS_CANCELLED         => 'لغو شده',
            self::STATUS_FAILED            => 'ناموفق',
            default                        => $this->status,
        };
    }

    public function provisionStatusLabel(): string
    {
        return match($this->provision_status) {
            self::PROVISION_PENDING         => 'در انتظار',
            self::PROVISION_MANUAL_REQUIRED => 'نیاز به اقدام دستی',
            self::PROVISION_PROVISIONED     => 'ساخته شده',
            self::PROVISION_FAILED          => 'ناموفق',
            self::PROVISION_SKIPPED         => 'رد شده',
            default                         => $this->provision_status,
        };
    }

    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING_PROVISION => 'در انتظار ساخت',
            self::STATUS_ACTIVE            => 'فعال',
            self::STATUS_DISABLED          => 'غیرفعال',
            self::STATUS_EXPIRED           => 'منقضی شده',
            self::STATUS_CANCELLED         => 'لغو شده',
            self::STATUS_FAILED            => 'ناموفق',
        ];
    }

    public static function allProvisionStatuses(): array
    {
        return [
            self::PROVISION_PENDING         => 'در انتظار',
            self::PROVISION_MANUAL_REQUIRED => 'نیاز به اقدام دستی',
            self::PROVISION_PROVISIONED     => 'ساخته شده',
            self::PROVISION_FAILED          => 'ناموفق',
            self::PROVISION_SKIPPED         => 'رد شده',
        ];
    }
}
