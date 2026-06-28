<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PaymentMethod extends Model
{
    const TYPE_MANUAL_CRYPTO = 'manual_crypto';
    const TYPE_MANUAL_STARS  = 'manual_stars';
    const TYPE_MANUAL_RIAL   = 'manual_rial';
    const TYPE_WALLET        = 'wallet';
    const TYPE_NOWPAYMENTS   = 'nowpayments';

    protected $fillable = [
        'title',
        'slug',
        'type',
        'description',
        'instructions',
        'account_label',
        'account_value',
        'network',
        'min_amount_toman',
        'max_amount_toman',
        'fee_percent',
        'is_active',
        'sort_order',
        'config',
        'api_key',
        'ipn_secret',
    ];

    protected $hidden = [
        'api_key',
        'ipn_secret',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'sort_order'        => 'integer',
        'min_amount_toman'  => 'integer',
        'max_amount_toman'  => 'integer',
        'fee_percent'       => 'float',
        'config'            => 'array',
        'api_key'           => 'encrypted',
        'ipn_secret'        => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentMethod $method) {
            if (empty($method->slug)) {
                $method->slug = Str::slug($method->title);
            }
        });
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isWallet(): bool
    {
        return $this->type === self::TYPE_WALLET;
    }

    public function isManual(): bool
    {
        return str_starts_with($this->type, 'manual_');
    }

    public function isNowPayments(): bool
    {
        return $this->type === self::TYPE_NOWPAYMENTS;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    public function typeLabel(): string
    {
        return match($this->type) {
            self::TYPE_MANUAL_CRYPTO => 'ارز دیجیتال (دستی)',
            self::TYPE_MANUAL_STARS  => 'تلگرام استارز (دستی)',
            self::TYPE_MANUAL_RIAL   => 'انتقال ریالی (دستی)',
            self::TYPE_WALLET        => 'کیف پول',
            self::TYPE_NOWPAYMENTS   => 'NOWPayments (کریپتو)',
            default                  => $this->type,
        };
    }

    public static function allTypes(): array
    {
        return [
            self::TYPE_MANUAL_CRYPTO => 'ارز دیجیتال (دستی)',
            self::TYPE_MANUAL_STARS  => 'تلگرام استارز (دستی)',
            self::TYPE_MANUAL_RIAL   => 'انتقال ریالی (دستی)',
            self::TYPE_WALLET        => 'کیف پول',
            self::TYPE_NOWPAYMENTS   => 'NOWPayments (کریپتو)',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
