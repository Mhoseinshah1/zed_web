<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    // Order statuses
    const STATUS_PENDING              = 'pending';
    const STATUS_AWAITING_PAYMENT     = 'awaiting_payment';
    const STATUS_PAID                 = 'paid';
    const STATUS_PROCESSING           = 'processing';
    const STATUS_PROVISIONING         = 'provisioning';
    const STATUS_PROVISIONING_FAILED  = 'provisioning_failed';
    const STATUS_COMPLETED            = 'completed';
    const STATUS_CANCELLED            = 'cancelled';
    const STATUS_FAILED               = 'failed';

    // Payment statuses
    const PAYMENT_UNPAID    = 'unpaid';
    const PAYMENT_PENDING   = 'pending';
    const PAYMENT_PAID      = 'paid';
    const PAYMENT_FAILED    = 'failed';
    const PAYMENT_REFUNDED  = 'refunded';

    protected $fillable = [
        'order_number',
        'user_id',
        'plan_id',
        'status',
        'payment_status',
        'plan_name',
        'plan_slug',
        'traffic_gb',
        'duration_days',
        'price_toman',
        'final_price_toman',
        'discount_toman',
        'currency',
        'notes',
        'admin_notes',
        'paid_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'price_toman'       => 'integer',
        'final_price_toman' => 'integer',
        'discount_toman'    => 'integer',
        'traffic_gb'        => 'integer',
        'duration_days'     => 'integer',
        'paid_at'           => 'datetime',
        'completed_at'      => 'datetime',
        'cancelled_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->order_number)) {
                $order->order_number = self::generateOrderNumber();
            }
        });
    }

    private static function generateOrderNumber(): string
    {
        do {
            $number = 'ZED-' . date('Ymd') . '-' . strtoupper(Str::random(5));
        } while (self::where('order_number', $number)->exists());

        return $number;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function service(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserService::class);
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING             => 'در انتظار',
            self::STATUS_AWAITING_PAYMENT    => 'در انتظار پرداخت',
            self::STATUS_PAID                => 'پرداخت شده',
            self::STATUS_PROCESSING          => 'در حال پردازش',
            self::STATUS_PROVISIONING        => 'در حال ساخت سرویس',
            self::STATUS_PROVISIONING_FAILED => 'خطا در ساخت سرویس',
            self::STATUS_COMPLETED           => 'فعال',
            self::STATUS_CANCELLED           => 'لغو شده',
            self::STATUS_FAILED              => 'ناموفق',
            default                          => $this->status,
        };
    }

    public function paymentStatusLabel(): string
    {
        return match($this->payment_status) {
            self::PAYMENT_UNPAID   => 'پرداخت نشده',
            self::PAYMENT_PENDING  => 'در انتظار',
            self::PAYMENT_PAID     => 'پرداخت شده',
            self::PAYMENT_FAILED   => 'ناموفق',
            self::PAYMENT_REFUNDED => 'برگشت داده شده',
            default                => $this->payment_status,
        };
    }

    public function trafficLabel(): string
    {
        return $this->traffic_gb ? $this->traffic_gb . ' گیگابایت' : 'نامحدود';
    }

    public function durationLabel(): string
    {
        return $this->duration_days ? $this->duration_days . ' روز' : 'نامحدود';
    }

    public function formattedPrice(): string
    {
        return number_format($this->final_price_toman);
    }

    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING             => 'در انتظار',
            self::STATUS_AWAITING_PAYMENT    => 'در انتظار پرداخت',
            self::STATUS_PAID                => 'پرداخت شده',
            self::STATUS_PROCESSING          => 'در حال پردازش',
            self::STATUS_PROVISIONING        => 'در حال ساخت سرویس',
            self::STATUS_PROVISIONING_FAILED => 'خطا در ساخت سرویس',
            self::STATUS_COMPLETED           => 'فعال',
            self::STATUS_CANCELLED           => 'لغو شده',
            self::STATUS_FAILED              => 'ناموفق',
        ];
    }

    public static function allPaymentStatuses(): array
    {
        return [
            self::PAYMENT_UNPAID   => 'پرداخت نشده',
            self::PAYMENT_PENDING  => 'در انتظار',
            self::PAYMENT_PAID     => 'پرداخت شده',
            self::PAYMENT_FAILED   => 'ناموفق',
            self::PAYMENT_REFUNDED => 'برگشت داده شده',
        ];
    }
}
