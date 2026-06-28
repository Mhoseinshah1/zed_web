<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;
    const TYPE_MANUAL_CREDIT = 'manual_credit';
    const TYPE_MANUAL_DEBIT  = 'manual_debit';
    const TYPE_ORDER_PAYMENT = 'order_payment';
    const TYPE_TOPUP         = 'topup';
    const TYPE_REFUND        = 'refund';
    const TYPE_ADJUSTMENT    = 'adjustment';

    const DIRECTION_CREDIT = 'credit';
    const DIRECTION_DEBIT  = 'debit';

    const STATUS_COMPLETED = 'completed';
    const STATUS_PENDING   = 'pending';
    const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'user_id',
        'order_id',
        'payment_transaction_id',
        'type',
        'direction',
        'amount_toman',
        'balance_before_toman',
        'balance_after_toman',
        'status',
        'description',
        'reference_type',
        'reference_id',
        'admin_id',
    ];

    protected $casts = [
        'amount_toman'         => 'integer',
        'balance_before_toman' => 'integer',
        'balance_after_toman'  => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function typeLabel(): string
    {
        return match($this->type) {
            self::TYPE_MANUAL_CREDIT => 'شارژ دستی',
            self::TYPE_MANUAL_DEBIT  => 'برداشت دستی',
            self::TYPE_ORDER_PAYMENT => 'پرداخت سفارش',
            self::TYPE_TOPUP         => 'شارژ کیف پول',
            self::TYPE_REFUND        => 'برگشت وجه',
            self::TYPE_ADJUSTMENT    => 'تعدیل',
            default                  => $this->type,
        };
    }

    public static function allTypes(): array
    {
        return [
            self::TYPE_MANUAL_CREDIT => 'شارژ دستی',
            self::TYPE_MANUAL_DEBIT  => 'برداشت دستی',
            self::TYPE_ORDER_PAYMENT => 'پرداخت سفارش',
            self::TYPE_TOPUP         => 'شارژ کیف پول',
            self::TYPE_REFUND        => 'برگشت وجه',
            self::TYPE_ADJUSTMENT    => 'تعدیل',
        ];
    }
}
