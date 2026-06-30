<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log of every admin Telegram notification attempt. The message is the
 * already-safe, escaped summary that was (or would be) sent — it never contains
 * secrets. The bot token is NEVER stored here.
 */
class TelegramAdminNotificationLog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_MUTED   = 'muted';

    protected $fillable = [
        'event_key', 'topic_key', 'chat_id', 'message_thread_id',
        'title', 'message', 'status', 'telegram_message_id', 'error',
        'related_type', 'related_id', 'metadata', 'sent_at', 'failed_at',
    ];

    protected $casts = [
        'message_thread_id'   => 'integer',
        'telegram_message_id' => 'integer',
        'related_id'          => 'integer',
        'metadata'            => 'array',
        'sent_at'             => 'datetime',
        'failed_at'           => 'datetime',
    ];

    /** @return array<string,string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'در صف',
            self::STATUS_SENT    => 'ارسال‌شده',
            self::STATUS_FAILED  => 'ناموفق',
            self::STATUS_SKIPPED => 'رد شده',
            self::STATUS_MUTED   => 'محدودشده',
        ];
    }
}
