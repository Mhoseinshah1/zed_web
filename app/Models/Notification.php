<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    // ── User notification types ──────────────────────────────────────────────
    const TYPE_PAYMENT_SUCCESS          = 'payment_success';
    const TYPE_PAYMENT_FAILED           = 'payment_failed';
    const TYPE_WALLET_TOPUP_SUCCESS     = 'wallet_topup_success';
    const TYPE_WALLET_PAYMENT_SUCCESS   = 'wallet_payment_success';
    const TYPE_NEW_SERVICE_CREATED      = 'new_service_created';
    const TYPE_RENEWAL_SUCCESS          = 'renewal_success';
    const TYPE_EXTRA_TRAFFIC_SUCCESS    = 'extra_traffic_success';
    const TYPE_EXTRA_TIME_SUCCESS       = 'extra_time_success';
    const TYPE_RENEWAL_CASHBACK_SUCCESS = 'renewal_cashback_success';
    const TYPE_DISCOUNT_USED            = 'discount_used';

    // ── Admin / system notification types ────────────────────────────────────
    const TYPE_MARZBAN_UPDATE_FAILED = 'marzban_update_failed';
    const TYPE_PROVISIONING_FAILED   = 'provisioning_failed';
    const TYPE_ADMIN_WARNING         = 'admin_warning';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'dedupe_key',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** System/admin notifications have no owning user. */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public function isAdminType(): bool
    {
        return in_array($this->type, [
            self::TYPE_MARZBAN_UPDATE_FAILED,
            self::TYPE_PROVISIONING_FAILED,
            self::TYPE_ADMIN_WARNING,
        ], true);
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_PAYMENT_SUCCESS          => 'پرداخت موفق',
            self::TYPE_PAYMENT_FAILED           => 'پرداخت ناموفق',
            self::TYPE_WALLET_TOPUP_SUCCESS     => 'شارژ کیف پول',
            self::TYPE_WALLET_PAYMENT_SUCCESS   => 'پرداخت از کیف پول',
            self::TYPE_NEW_SERVICE_CREATED      => 'ساخت سرویس',
            self::TYPE_RENEWAL_SUCCESS          => 'تمدید سرویس',
            self::TYPE_EXTRA_TRAFFIC_SUCCESS    => 'خرید حجم اضافه',
            self::TYPE_EXTRA_TIME_SUCCESS       => 'خرید زمان اضافه',
            self::TYPE_RENEWAL_CASHBACK_SUCCESS => 'کش‌بک تمدید',
            self::TYPE_DISCOUNT_USED            => 'استفاده از کد تخفیف',
            self::TYPE_MARZBAN_UPDATE_FAILED    => 'خطای Marzban',
            self::TYPE_PROVISIONING_FAILED      => 'خطای ساخت سرویس',
            self::TYPE_ADMIN_WARNING            => 'هشدار سیستم',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? $this->type;
    }
}
