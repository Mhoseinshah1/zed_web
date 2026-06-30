<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A forum topic inside the admin Telegram management group. Each notification
 * category maps to a topic; the message_thread_id routes messages to the right
 * forum thread. All admin-manageable from the panel.
 */
class TelegramAdminTopic extends Model
{
    protected $fillable = [
        'key', 'title', 'description', 'chat_id', 'message_thread_id',
        'is_active', 'sort_order', 'last_sent_at', 'last_error', 'metadata',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'message_thread_id' => 'integer',
        'sort_order'        => 'integer',
        'last_sent_at'      => 'datetime',
        'metadata'          => 'array',
    ];

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }

    /**
     * The 13 default topics. Keys are stable and referenced by the notifier's
     * event → topic map.
     *
     * @return array<int, array{key:string, title:string, description:string}>
     */
    public static function defaultTopics(): array
    {
        return [
            ['key' => 'sales',          'title' => 'فروش و سفارش‌ها',     'description' => 'سفارش جدید، پرداخت‌شده، ناموفق'],
            ['key' => 'payments',       'title' => 'پرداخت‌ها',           'description' => 'پرداخت موفق/ناموفق، هشدار کال‌بک تکراری'],
            ['key' => 'wallet',         'title' => 'کیف پول',             'description' => 'شارژ کیف پول، پرداخت کیفی، اصلاح دستی'],
            ['key' => 'tickets',        'title' => 'تیکت‌ها',             'description' => 'تیکت جدید و پاسخ کاربر'],
            ['key' => 'users',          'title' => 'کاربران',             'description' => 'ثبت‌نام جدید و تایید شماره'],
            ['key' => 'services',       'title' => 'سرویس‌ها',            'description' => 'ساخت، تمدید، حجم/زمان اضافه، سینک'],
            ['key' => 'panels',         'title' => 'پنل‌های VPN',         'description' => 'قطعی/بازیابی پنل، خطای احراز، سلامت'],
            ['key' => 'errors',         'title' => 'خطاها و عملیات ناموفق', 'description' => 'خطاهای اعمال پرداخت/ساخت/تمدید و تلاش مجدد'],
            ['key' => 'daily_report',   'title' => 'گزارش روزانه',        'description' => 'خلاصه روزانه (فاز بعد)'],
            ['key' => 'representatives','title' => 'نمایندگان',           'description' => 'درخواست، تایید/رد، پورسانت'],
            ['key' => 'admin',          'title' => 'تغییرات ادمین',       'description' => 'تغییرات مهم تنظیمات (درگاه، پنل، کیف پول)'],
            ['key' => 'backup_server',  'title' => 'بکاپ و سرور',         'description' => 'وضعیت بکاپ سرور (فاز بعد)'],
            ['key' => 'system',         'title' => 'اعلان‌های سیستم',     'description' => 'هشدارهای بحرانی سیستم'],
        ];
    }

    /** Insert any missing default topics without overwriting admin edits. */
    public static function seedDefaults(): void
    {
        foreach (self::defaultTopics() as $i => $topic) {
            static::firstOrCreate(
                ['key' => $topic['key']],
                [
                    'title'       => $topic['title'],
                    'description' => $topic['description'],
                    'is_active'   => true,
                    'sort_order'  => ($i + 1) * 10,
                ],
            );
        }
    }
}
