<?php

namespace Database\Seeders;

use App\Models\SiteText;
use Illuminate\Database\Seeder;

class SiteTextSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // ── Homepage: Hero ──────────────────────────────────────────────
            ['key' => 'homepage.hero.title',                'group' => 'homepage', 'label' => 'تیتر اصلی صفحه',         'value' => "اینترنت آزاد\nبدون محدودیت",                                                                              'type' => 'textarea', 'sort_order' => 1],
            ['key' => 'homepage.hero.subtitle',             'group' => 'homepage', 'label' => 'زیرتیتر صفحه',           'value' => "با ZedProxy از هر نقطه‌ای در جهان به اینترنت آزاد دسترسی داشته باشید.\nسرعت بالا، امنیت کامل و پشتیبانی ۲۴ ساعته.", 'type' => 'textarea', 'sort_order' => 2],
            ['key' => 'homepage.hero.button_text',          'group' => 'homepage', 'label' => 'متن دکمه اصلی',          'value' => 'مشاهده پلن‌ها',                                                                                              'type' => 'text',     'sort_order' => 3],
            ['key' => 'homepage.hero.secondary_button_text','group' => 'homepage', 'label' => 'متن دکمه دوم',           'value' => 'آموزش اتصال',                                                                                                'type' => 'text',     'sort_order' => 4],
            ['key' => 'homepage.status.badge',              'group' => 'homepage', 'label' => 'متن نوار وضعیت',          'value' => 'سرویس در حال اجراست',                                                                                       'type' => 'text',     'sort_order' => 5],

            // ── Homepage: Features section ──────────────────────────────────
            ['key' => 'homepage.features.title',    'group' => 'homepage', 'label' => 'عنوان بخش ویژگی‌ها',       'value' => 'چرا ZedProxy؟',                      'type' => 'text', 'sort_order' => 10],
            ['key' => 'homepage.features.subtitle', 'group' => 'homepage', 'label' => 'زیرعنوان بخش ویژگی‌ها',    'value' => 'بهترین انتخاب برای اتصال امن و سریع', 'type' => 'text', 'sort_order' => 11],

            // ── Homepage: Plans section ─────────────────────────────────────
            ['key' => 'homepage.plans.title',    'group' => 'homepage', 'label' => 'عنوان بخش پلن‌ها در صفحه اصلی',    'value' => 'انتخاب پلن مناسب',                       'type' => 'text', 'sort_order' => 20],
            ['key' => 'homepage.plans.subtitle', 'group' => 'homepage', 'label' => 'زیرعنوان بخش پلن‌ها در صفحه اصلی', 'value' => 'قیمت‌های مناسب برای همه نیازها',          'type' => 'text', 'sort_order' => 21],

            // ── Homepage: CTA ───────────────────────────────────────────────
            ['key' => 'homepage.cta.title',    'group' => 'homepage', 'label' => 'عنوان دکمه دعوت به اقدام',    'value' => 'همین الان شروع کنید',                     'type' => 'text', 'sort_order' => 30],
            ['key' => 'homepage.cta.subtitle', 'group' => 'homepage', 'label' => 'زیرعنوان دکمه دعوت به اقدام', 'value' => 'ثبت‌نام رایگان، خرید آسان و اتصال فوری', 'type' => 'text', 'sort_order' => 31],

            // ── Plans page ──────────────────────────────────────────────────
            ['key' => 'plans.title',    'group' => 'plans', 'label' => 'عنوان صفحه پلن‌ها',    'value' => 'انتخاب پلن',                                     'type' => 'text', 'sort_order' => 1],
            ['key' => 'plans.subtitle', 'group' => 'plans', 'label' => 'زیرعنوان صفحه پلن‌ها', 'value' => 'یک پلن مناسب انتخاب کنید و همین الان شروع کنید', 'type' => 'text', 'sort_order' => 2],
            ['key' => 'plans.buy_soon_text', 'group' => 'plans', 'label' => 'متن دکمه خرید (موقت)', 'value' => 'خرید به‌زودی فعال می‌شود', 'type' => 'text', 'sort_order' => 3],

            // ── Footer ──────────────────────────────────────────────────────
            ['key' => 'footer.description',   'group' => 'footer', 'label' => 'توضیح فوتر',       'value' => 'ارائه‌دهنده خدمات VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و پشتیبانی ۲۴ ساعته.', 'type' => 'textarea', 'sort_order' => 1],
            ['key' => 'footer.support_text',  'group' => 'footer', 'label' => 'متن پشتیبانی فوتر', 'value' => 'پشتیبانی ۲۴ ساعته — ۷ روز هفته',                                                    'type' => 'text',     'sort_order' => 2],

            // ── Legal ───────────────────────────────────────────────────────
            ['key' => 'legal.terms',   'group' => 'legal', 'label' => 'متن شرایط استفاده', 'value' => 'شرایط استفاده از سرویس ZedProxy در اینجا درج خواهد شد.', 'type' => 'textarea', 'sort_order' => 1, 'is_public' => true],
            ['key' => 'legal.privacy', 'group' => 'legal', 'label' => 'متن حریم خصوصی',    'value' => 'سیاست حریم خصوصی ZedProxy در اینجا درج خواهد شد.',       'type' => 'textarea', 'sort_order' => 2, 'is_public' => true],
        ];

        foreach ($defaults as $item) {
            SiteText::firstOrCreate(
                ['key' => $item['key']],
                [
                    'group'       => $item['group'] ?? null,
                    'label'       => $item['label'] ?? null,
                    'value'       => $item['value'],
                    'type'        => $item['type'] ?? 'text',
                    'is_public'   => $item['is_public'] ?? true,
                    'sort_order'  => $item['sort_order'] ?? 0,
                ]
            );
        }
    }
}
