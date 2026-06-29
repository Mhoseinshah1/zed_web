<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepresentativeRequest extends Model
{
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'user_id',
        'message',
        'contact_info',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING  => 'در انتظار بررسی',
            self::STATUS_APPROVED => 'تاییدشده',
            self::STATUS_REJECTED => 'ردشده',
            self::STATUS_DISABLED => 'غیرفعال',
        ];
    }
}
