<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;

/**
 * Central, idempotent notification dispatcher.
 *
 * Renders admin-editable templates (falling back to built-in defaults) and
 * creates notification rows for users (user_id set) or admins/system
 * (user_id null). A dedupe_key prevents duplicate notifications when the same
 * event is replayed (e.g. duplicate payment IPN/callback).
 */
class NotificationService
{
    /**
     * Create a notification for a user (or skip if dedupe_key already exists).
     */
    public function notify(string $type, ?User $user, array $context = [], ?string $dedupeKey = null): ?Notification
    {
        if ($dedupeKey !== null && $this->exists($dedupeKey)) {
            return null;
        }

        [$title, $message] = $this->render($type, $context);

        return Notification::create([
            'user_id'    => $user?->id,
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'data'       => $this->buildData($context),
            'dedupe_key' => $dedupeKey,
        ]);
    }

    /**
     * Create a system/admin notification (user_id null).
     */
    public function notifyAdmins(string $type, array $context = [], ?string $dedupeKey = null): ?Notification
    {
        if ($dedupeKey !== null && $this->exists($dedupeKey)) {
            return null;
        }

        [$title, $message] = $this->render($type, $context);

        return Notification::create([
            'user_id'    => null,
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'data'       => $this->buildData($context),
            'dedupe_key' => $dedupeKey,
        ]);
    }

    /**
     * Render a template's title + message for a type, substituting {variables}.
     * Falls back to the built-in default when no active template exists.
     *
     * @return array{0:string,1:string} [title, message]
     */
    public function render(string $type, array $context = []): array
    {
        $template = NotificationTemplate::findByKey($type);

        if ($template && $template->is_active) {
            $title   = $template->title;
            $message = $template->message;
        } else {
            $default = self::defaults()[$type] ?? ['title' => $type, 'message' => ''];
            $title   = $default['title'];
            $message = $default['message'];
        }

        return [
            $this->substitute($title, $context),
            $this->substitute($message, $context),
        ];
    }

    private function exists(string $dedupeKey): bool
    {
        return Notification::where('dedupe_key', $dedupeKey)->exists();
    }

    private function substitute(string $text, array $context): string
    {
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }
        return $text;
    }

    /**
     * Keep only scalar context values for the stored data payload, plus the
     * stable identifiers used for dedupe/lookup.
     */
    private function buildData(array $context): array
    {
        return array_filter(
            $context,
            fn ($v) => $v === null || is_scalar($v),
        );
    }

    /**
     * Built-in default templates. Used as a fallback and seeded into the
     * database (only when missing) so admins can edit them.
     *
     * @return array<string, array{title:string,message:string,variables:string}>
     */
    public static function defaults(): array
    {
        return [
            Notification::TYPE_PAYMENT_SUCCESS => [
                'title'     => 'پرداخت موفق',
                'message'   => 'خرید شما با موفقیت پرداخت شد و سرویس در حال آماده‌سازی است.',
                'variables' => '{user_name}, {order_id}, {amount}, {final_amount}',
            ],
            Notification::TYPE_PAYMENT_FAILED => [
                'title'     => 'پرداخت ناموفق',
                'message'   => 'پرداخت سفارش شما ناموفق بود. لطفاً دوباره تلاش کنید.',
                'variables' => '{user_name}, {order_id}, {amount}',
            ],
            Notification::TYPE_NEW_SERVICE_CREATED => [
                'title'     => 'سرویس فعال شد',
                'message'   => 'سرویس شما با موفقیت فعال شد.',
                'variables' => '{user_name}, {service_name}, {order_id}',
            ],
            Notification::TYPE_RENEWAL_SUCCESS => [
                'title'     => 'تمدید موفق',
                'message'   => 'سرویس شما با موفقیت تمدید شد.',
                'variables' => '{user_name}, {service_name}, {order_id}, {days}, {expiry_date}',
            ],
            Notification::TYPE_EXTRA_TRAFFIC_SUCCESS => [
                'title'     => 'حجم اضافه شد',
                'message'   => 'حجم اضافه با موفقیت به سرویس شما اضافه شد.',
                'variables' => '{user_name}, {service_name}, {order_id}, {traffic_gb}',
            ],
            Notification::TYPE_EXTRA_TIME_SUCCESS => [
                'title'     => 'زمان اضافه شد',
                'message'   => 'زمان اضافه با موفقیت به سرویس شما اضافه شد.',
                'variables' => '{user_name}, {service_name}, {order_id}, {days}, {expiry_date}',
            ],
            Notification::TYPE_WALLET_TOPUP_SUCCESS => [
                'title'     => 'شارژ کیف پول',
                'message'   => 'کیف پول شما با موفقیت شارژ شد.',
                'variables' => '{user_name}, {wallet_amount}',
            ],
            Notification::TYPE_WALLET_PAYMENT_SUCCESS => [
                'title'     => 'پرداخت از کیف پول',
                'message'   => 'پرداخت از کیف پول با موفقیت انجام شد.',
                'variables' => '{user_name}, {order_id}, {final_amount}',
            ],
            Notification::TYPE_RENEWAL_CASHBACK_SUCCESS => [
                'title'     => 'کش‌بک تمدید',
                'message'   => 'کش‌بک تمدید به کیف پول شما اضافه شد.',
                'variables' => '{user_name}, {cashback_amount}',
            ],
            Notification::TYPE_DISCOUNT_USED => [
                'title'     => 'کد تخفیف اعمال شد',
                'message'   => 'کد تخفیف با موفقیت روی سفارش شما اعمال شد.',
                'variables' => '{user_name}, {order_id}, {discount_amount}, {final_amount}',
            ],
            Notification::TYPE_MARZBAN_UPDATE_FAILED => [
                'title'     => 'خطای به‌روزرسانی Marzban',
                'message'   => 'به‌روزرسانی کاربر در Marzban با خطا مواجه شد. کاربر: {user_name} — سفارش: {order_id} — سرویس: {service_id} — خطا: {error}. نیاز به بررسی و تلاش مجدد.',
                'variables' => '{user_name}, {order_id}, {service_id}, {error}',
            ],
            Notification::TYPE_PROVISIONING_FAILED => [
                'title'     => 'خطای ساخت سرویس',
                'message'   => 'ساخت سرویس برای سفارش {order_id} (کاربر: {user_name}) با خطا مواجه شد. خطا: {error}. نیاز به بررسی و تلاش مجدد.',
                'variables' => '{user_name}, {order_id}, {service_id}, {error}',
            ],
            Notification::TYPE_ADMIN_WARNING => [
                'title'     => 'هشدار سیستم',
                'message'   => '{message}',
                'variables' => '{user_name}, {order_id}, {service_id}, {error}, {message}',
            ],
        ];
    }

    /**
     * Insert any missing default templates without overwriting admin edits.
     */
    public function seedDefaults(): void
    {
        foreach (self::defaults() as $key => $tpl) {
            if (NotificationTemplate::where('key', $key)->exists()) {
                continue;
            }
            NotificationTemplate::create([
                'key'                 => $key,
                'title'               => $tpl['title'],
                'message'             => $tpl['message'],
                'is_active'           => true,
                'available_variables' => $tpl['variables'],
            ]);
        }
    }
}
