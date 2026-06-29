<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    const STATUS_OPEN          = 'open';
    const STATUS_WAITING_USER  = 'waiting_user';
    const STATUS_WAITING_ADMIN = 'waiting_admin';
    const STATUS_ANSWERED      = 'answered';
    const STATUS_CLOSED        = 'closed';

    const PRIORITY_LOW    = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH   = 'high';
    const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'ticket_number',
        'user_id',
        'category_id',
        'assigned_admin_id',
        'order_id',
        'user_service_id',
        'subject',
        'status',
        'priority',
        'last_reply_at',
        'user_unread',
        'admin_unread',
        'closed_at',
    ];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'closed_at'     => 'datetime',
        'user_unread'   => 'boolean',
        'admin_unread'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
        });
    }

    /**
     * Generate a unique random 10-digit numeric ticket number.
     * Numeric only, no prefix, non-sequential. Retries on collision.
     */
    public static function generateTicketNumber(): string
    {
        do {
            $candidate = (string) random_int(1000000000, 9999999999);
        } while (self::where('ticket_number', $candidate)->exists());

        return $candidate;
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportTicketCategory::class, 'category_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function userService(): BelongsTo
    {
        return $this->belongsTo(UserService::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    /** Messages visible to the ticket owner (internal admin notes excluded). */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal_note', false);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::priorities()[$this->priority] ?? $this->priority;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN          => 'باز',
            self::STATUS_WAITING_USER  => 'در انتظار پاسخ کاربر',
            self::STATUS_WAITING_ADMIN => 'در انتظار پاسخ پشتیبانی',
            self::STATUS_ANSWERED      => 'پاسخ داده شده',
            self::STATUS_CLOSED        => 'بسته شده',
        ];
    }

    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW    => 'کم',
            self::PRIORITY_NORMAL => 'عادی',
            self::PRIORITY_HIGH   => 'زیاد',
            self::PRIORITY_URGENT => 'فوری',
        ];
    }
}
