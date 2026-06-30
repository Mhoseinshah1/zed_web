<?php

namespace App\Services\Telegram;

use App\Models\TelegramTemplate;

/**
 * Admin-editable Telegram message templates with built-in Persian defaults.
 *
 * SECURITY: every {variable} value is escaped for the active parse mode BEFORE
 * being substituted, so user-provided text (names, ticket titles, …) can never
 * inject Telegram markup. Template bodies themselves may contain safe markup
 * (e.g. <b>…</b>) and are not escaped.
 */
class TelegramTemplates
{
    public function __construct(private readonly TelegramSettings $settings) {}

    /**
     * Render [title, message] for an event key, substituting + escaping context.
     *
     * @return array{0:string,1:string}
     */
    public function render(string $key, array $context = []): array
    {
        $template = TelegramTemplate::findByKey($key);

        if ($template && $template->is_active) {
            $title   = $template->title;
            $message = $template->message;
        } else {
            $default = self::defaults()[$key] ?? ['title' => $key, 'message' => '{message}'];
            $title   = $default['title'];
            $message = $default['message'];
        }

        $escaped = [];
        foreach ($context as $k => $v) {
            if ($v === null || is_scalar($v)) {
                $escaped[$k] = $this->escape((string) $v);
            }
        }

        return [
            $this->substitute($title, $escaped),
            $this->substitute($message, $escaped),
        ];
    }

    /** Escape a value for the active parse mode (prevents markup injection). */
    public function escape(string $text): string
    {
        if ($this->settings->parseMode() === TelegramSettings::PARSE_MARKDOWN_V2) {
            // Escape every MarkdownV2 special character.
            return preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $text) ?? $text;
        }
        // HTML mode: only &, <, > are significant.
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private function substitute(string $text, array $context): string
    {
        foreach ($context as $key => $value) {
            $text = str_replace('{' . $key . '}', $value, $text);
        }
        return $text;
    }

    /**
     * Built-in defaults (HTML parse mode formatting). Editable from the panel.
     *
     * @return array<string,array{title:string,message:string,variables:string}>
     */
    public static function defaults(): array
    {
        return [
            'payment_success' => [
                'title' => '✅ پرداخت موفق',
                'message' => "✅ <b>پرداخت موفق</b>\n👤 کاربر: {user}\n🧾 سفارش: {order}\n💳 مبلغ: {amount} تومان\n🏷 روش: {method}",
                'variables' => '{user}, {order}, {amount}, {method}',
            ],
            'payment_failed' => [
                'title' => '❌ پرداخت ناموفق',
                'message' => "❌ <b>پرداخت ناموفق</b>\n👤 کاربر: {user}\n🧾 سفارش: {order}\n💳 مبلغ: {amount} تومان\n📝 علت: {reason}",
                'variables' => '{user}, {order}, {amount}, {reason}',
            ],
            'order_created' => [
                'title' => '🆕 سفارش جدید',
                'message' => "🆕 <b>سفارش جدید</b>\n👤 کاربر: {user}\n🧾 سفارش: {order}\n📦 پلن: {plan}\n💰 مبلغ: {amount} تومان",
                'variables' => '{user}, {order}, {plan}, {amount}',
            ],
            'order_paid' => [
                'title' => '💰 سفارش پرداخت‌شده',
                'message' => "💰 <b>سفارش پرداخت شد</b>\n👤 کاربر: {user}\n🧾 سفارش: {order}\n📦 پلن: {plan}\n💵 مبلغ نهایی: {amount} تومان",
                'variables' => '{user}, {order}, {plan}, {amount}',
            ],
            'service_provisioned' => [
                'title' => '🚀 سرویس ساخته شد',
                'message' => "🚀 <b>سرویس ساخته شد</b>\n👤 کاربر: {user}\n📦 سرویس: {service}\n🧾 سفارش: {order}",
                'variables' => '{user}, {service}, {order}',
            ],
            'service_failed' => [
                'title' => '⚠️ خطای ساخت سرویس',
                'message' => "⚠️ <b>ساخت سرویس ناموفق</b>\n👤 کاربر: {user}\n🧾 سفارش: {order}\n📝 خطا: {error}",
                'variables' => '{user}, {order}, {error}',
            ],
            'service_renewed' => [
                'title' => '🔄 تمدید سرویس',
                'message' => "🔄 <b>سرویس تمدید شد</b>\n👤 کاربر: {user}\n📦 سرویس: {service}\n📅 انقضا: {expiry}",
                'variables' => '{user}, {service}, {expiry}',
            ],
            'ticket_created' => [
                'title' => '🎫 تیکت جدید',
                'message' => "🎫 <b>تیکت جدید</b>\n👤 کاربر: {user}\n🔢 شماره: {ticket}\n📝 موضوع: {subject}",
                'variables' => '{user}, {ticket}, {subject}',
            ],
            'ticket_replied' => [
                'title' => '💬 پاسخ کاربر در تیکت',
                'message' => "💬 <b>پاسخ جدید کاربر</b>\n👤 کاربر: {user}\n🔢 تیکت: {ticket}",
                'variables' => '{user}, {ticket}',
            ],
            'user_registered' => [
                'title' => '🙋 کاربر جدید',
                'message' => "🙋 <b>ثبت‌نام جدید</b>\n👤 کاربر: {user}\n🆔 شناسه: {account}",
                'variables' => '{user}, {account}',
            ],
            'panel_down' => [
                'title' => '🔴 قطعی پنل',
                'message' => "🔴 <b>پنل در دسترس نیست</b>\n🖥 پنل: {panel}\n📝 وضعیت: {detail}",
                'variables' => '{panel}, {detail}',
            ],
            'panel_recovered' => [
                'title' => '🟢 بازیابی پنل',
                'message' => "🟢 <b>پنل بازیابی شد</b>\n🖥 پنل: {panel}",
                'variables' => '{panel}',
            ],
            'wallet_topup' => [
                'title' => '👛 شارژ کیف پول',
                'message' => "👛 <b>شارژ کیف پول</b>\n👤 کاربر: {user}\n💰 مبلغ: {amount} تومان",
                'variables' => '{user}, {amount}',
            ],
            'representative_request' => [
                'title' => '🤝 درخواست نمایندگی',
                'message' => "🤝 <b>درخواست نمایندگی</b>\n👤 کاربر: {user}\n🆔 شناسه: {account}",
                'variables' => '{user}, {account}',
            ],
            'admin_change' => [
                'title' => '🛠 تغییر تنظیمات ادمین',
                'message' => "🛠 <b>تغییر مهم تنظیمات</b>\n📂 بخش: {section}\n👤 توسط: {actor}",
                'variables' => '{section}, {actor}',
            ],
            'system_alert' => [
                'title' => '🚨 هشدار سیستم',
                'message' => "🚨 <b>هشدار سیستم</b>\n{message}",
                'variables' => '{message}',
            ],
        ];
    }

    /** Insert missing default templates without overwriting admin edits. */
    public function seedDefaults(): void
    {
        foreach (self::defaults() as $key => $tpl) {
            if (TelegramTemplate::where('key', $key)->exists()) {
                continue;
            }
            TelegramTemplate::create([
                'key'                 => $key,
                'title'               => $tpl['title'],
                'message'             => $tpl['message'],
                'is_active'           => true,
                'available_variables' => $tpl['variables'],
            ]);
        }
    }
}
