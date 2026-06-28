<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    const STATUS_PENDING    = 'pending';
    const STATUS_SUBMITTED  = 'submitted';
    const STATUS_APPROVED   = 'approved';
    const STATUS_REJECTED   = 'rejected';
    const STATUS_FAILED     = 'failed';
    const STATUS_CANCELLED  = 'cancelled';
    // NOWPayments gateway statuses
    const STATUS_WAITING    = 'waiting';
    const STATUS_CONFIRMING = 'confirming';
    const STATUS_PARTIAL    = 'partially_paid';
    const STATUS_REFUNDED   = 'refunded';
    const STATUS_EXPIRED    = 'expired';

    protected $fillable = [
        'order_id',
        'user_id',
        'payment_method_id',
        'provider',
        'method',
        'status',
        'amount_toman',
        'currency',
        'reference_id',
        'external_id',
        'payload',
        'paid_at',
        'proof_path',
        'transaction_reference',
        'user_note',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'rejected_at',
        // NOWPayments fields
        'provider_reference',
        'gateway_url',
        'gateway_status',
        'gateway_price_amount',
        'gateway_price_currency',
        'pay_amount',
        'pay_currency',
        'pay_address',
        'fee_amount',
        'payable_amount',
        'expires_at',
        'request_payload',
        'response_payload',
        'callback_payload',
        'callback_received_at',
    ];

    protected $casts = [
        'amount_toman'          => 'integer',
        'payload'               => 'array',
        'paid_at'               => 'datetime',
        'reviewed_at'           => 'datetime',
        'rejected_at'           => 'datetime',
        'gateway_price_amount'  => 'float',
        'pay_amount'            => 'float',
        'fee_amount'            => 'float',
        'payable_amount'        => 'float',
        'expires_at'            => 'datetime',
        'request_payload'       => 'array',
        'response_payload'      => 'array',
        'callback_payload'      => 'array',
        'callback_received_at'  => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING    => 'در انتظار',
            self::STATUS_SUBMITTED  => 'ارسال شده',
            self::STATUS_APPROVED   => 'تایید شده',
            self::STATUS_REJECTED   => 'رد شده',
            self::STATUS_FAILED     => 'ناموفق',
            self::STATUS_CANCELLED  => 'لغو شده',
            self::STATUS_WAITING    => 'در انتظار واریز',
            self::STATUS_CONFIRMING => 'در حال تایید',
            self::STATUS_PARTIAL    => 'پرداخت ناقص',
            self::STATUS_REFUNDED   => 'بازگشت وجه',
            self::STATUS_EXPIRED    => 'منقضی شده',
            default                 => $this->status,
        };
    }

    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING    => 'در انتظار',
            self::STATUS_SUBMITTED  => 'ارسال شده',
            self::STATUS_APPROVED   => 'تایید شده',
            self::STATUS_REJECTED   => 'رد شده',
            self::STATUS_FAILED     => 'ناموفق',
            self::STATUS_CANCELLED  => 'لغو شده',
            self::STATUS_WAITING    => 'در انتظار واریز',
            self::STATUS_CONFIRMING => 'در حال تایید',
            self::STATUS_PARTIAL    => 'پرداخت ناقص',
            self::STATUS_REFUNDED   => 'بازگشت وجه',
            self::STATUS_EXPIRED    => 'منقضی شده',
        ];
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_SUBMITTED,
            self::STATUS_WAITING,
            self::STATUS_CONFIRMING,
        ]);
    }

    public function isNowPaymentsActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_WAITING,
            self::STATUS_CONFIRMING,
            self::STATUS_PARTIAL,
        ]) && $this->provider_reference !== null;
    }
}
