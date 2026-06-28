<?php

namespace Database\Seeders;

use App\Models\SiteText;
use Illuminate\Database\Seeder;

class ServiceSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key'         => 'services.allow_user_revoke_subscription',
                'group'       => 'services',
                'label'       => 'اجازه تغییر لینک اشتراک توسط کاربر',
                'value'       => 'true',
                'type'        => 'boolean',
                'description' => 'اگر فعال باشد، کاربر می‌تواند لینک اشتراک خود را از داشبورد تغییر دهد.',
                'is_public'   => false,
                'sort_order'  => 10,
            ],
            [
                'key'         => 'services.allow_user_sync_service',
                'group'       => 'services',
                'label'       => 'اجازه بروزرسانی وضعیت سرویس توسط کاربر',
                'value'       => 'true',
                'type'        => 'boolean',
                'description' => 'اگر فعال باشد، کاربر می‌تواند وضعیت سرویس خود را از داشبورد بروزرسانی کند.',
                'is_public'   => false,
                'sort_order'  => 11,
            ],
            [
                'key'         => 'services.allow_user_reset_traffic',
                'group'       => 'services',
                'label'       => 'اجازه ریست ترافیک توسط کاربر',
                'value'       => 'false',
                'type'        => 'boolean',
                'description' => 'اگر فعال باشد، کاربر می‌تواند مصرف ترافیک سرویس خود را ریست کند. پیش‌فرض: غیرفعال.',
                'is_public'   => false,
                'sort_order'  => 12,
            ],
            [
                'key'         => 'services.allow_user_disable_service',
                'group'       => 'services',
                'label'       => 'اجازه غیرفعال‌سازی سرویس توسط کاربر',
                'value'       => 'false',
                'type'        => 'boolean',
                'description' => 'اگر فعال باشد، کاربر می‌تواند سرویس فعال خود را موقتاً غیرفعال کند. پیش‌فرض: غیرفعال.',
                'is_public'   => false,
                'sort_order'  => 13,
            ],
            [
                'key'         => 'services.allow_user_enable_service',
                'group'       => 'services',
                'label'       => 'اجازه فعال‌سازی سرویس توسط کاربر',
                'value'       => 'false',
                'type'        => 'boolean',
                'description' => 'اگر فعال باشد، کاربر می‌تواند سرویس غیرفعال خود را دوباره فعال کند. پیش‌فرض: غیرفعال.',
                'is_public'   => false,
                'sort_order'  => 14,
            ],
            [
                'key'         => 'services.revoke_subscription_cooldown_seconds',
                'group'       => 'services',
                'label'       => 'فاصله زمانی تغییر لینک اشتراک (ثانیه)',
                'value'       => '600',
                'type'        => 'number',
                'description' => 'حداقل فاصله زمانی بین دو درخواست تغییر لینک اشتراک توسط کاربر (پیش‌فرض: ۶۰۰ ثانیه = ۱۰ دقیقه).',
                'is_public'   => false,
                'sort_order'  => 15,
            ],
        ];

        foreach ($settings as $setting) {
            // firstOrCreate so admin-edited values are never overwritten
            SiteText::firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
