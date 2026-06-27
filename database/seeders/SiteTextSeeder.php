<?php

namespace Database\Seeders;

use App\Models\SiteText;
use Illuminate\Database\Seeder;

class SiteTextSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Hero section
            ['key' => 'homepage.status.badge',    'value' => 'سرویس در حال اجراست',                                                                                        'description' => 'وضعیت سرویس — نمایش در نوار بالای صفحه اصلی'],
            ['key' => 'homepage.hero.title',       'value' => "اینترنت آزاد\nبدون محدودیت",                                                                                  'description' => 'تیتر اصلی صفحه اصلی'],
            ['key' => 'homepage.hero.subtitle',    'value' => "با ZedProxy از هر نقطه‌ای در جهان به اینترنت آزاد دسترسی داشته باشید.\n    سرعت بالا، امنیت کامل و پشتیبانی ۲۴ ساعته.", 'description' => 'زیرتیتر صفحه اصلی'],

            // Features section
            ['key' => 'homepage.features.title',    'value' => 'چرا ZedProxy؟',                             'description' => 'عنوان بخش ویژگی‌ها'],
            ['key' => 'homepage.features.subtitle',  'value' => 'بهترین انتخاب برای اتصال امن و سریع',     'description' => 'زیرعنوان بخش ویژگی‌ها'],

            // Feature cards
            ['key' => 'homepage.feature.1.icon',  'value' => '⚡',                                                                      'description' => 'آیکون ویژگی ۱'],
            ['key' => 'homepage.feature.1.title', 'value' => 'سرعت فوق‌العاده',                                                          'description' => 'عنوان ویژگی ۱'],
            ['key' => 'homepage.feature.1.desc',  'value' => 'سرورهای پرسرعت در چندین کشور با پینگ پایین و پهنای باند نامحدود',         'description' => 'توضیح ویژگی ۱'],

            ['key' => 'homepage.feature.2.icon',  'value' => '🔒',                                                                      'description' => 'آیکون ویژگی ۲'],
            ['key' => 'homepage.feature.2.title', 'value' => 'امنیت کامل',                                                               'description' => 'عنوان ویژگی ۲'],
            ['key' => 'homepage.feature.2.desc',  'value' => 'رمزگذاری پیشرفته و حریم خصوصی کامل برای تمام ترافیک شما',               'description' => 'توضیح ویژگی ۲'],

            ['key' => 'homepage.feature.3.icon',  'value' => '🌍',                                                                      'description' => 'آیکون ویژگی ۳'],
            ['key' => 'homepage.feature.3.title', 'value' => 'سرورهای جهانی',                                                            'description' => 'عنوان ویژگی ۳'],
            ['key' => 'homepage.feature.3.desc',  'value' => 'دسترسی به سرورهای متعدد در اروپا، آمریکا و آسیا',                       'description' => 'توضیح ویژگی ۳'],

            ['key' => 'homepage.feature.4.icon',  'value' => '📱',                                                                      'description' => 'آیکون ویژگی ۴'],
            ['key' => 'homepage.feature.4.title', 'value' => 'همه دستگاه‌ها',                                                            'description' => 'عنوان ویژگی ۴'],
            ['key' => 'homepage.feature.4.desc',  'value' => 'پشتیبانی از اندروید، iOS، ویندوز، مک و لینوکس',                         'description' => 'توضیح ویژگی ۴'],

            ['key' => 'homepage.feature.5.icon',  'value' => '🔄',                                                                      'description' => 'آیکون ویژگی ۵'],
            ['key' => 'homepage.feature.5.title', 'value' => 'اتصال خودکار',                                                             'description' => 'عنوان ویژگی ۵'],
            ['key' => 'homepage.feature.5.desc',  'value' => 'لینک اشتراک V2Ray با به‌روزرسانی خودکار سرور',                           'description' => 'توضیح ویژگی ۵'],

            ['key' => 'homepage.feature.6.icon',  'value' => '🎧',                                                                      'description' => 'آیکون ویژگی ۶'],
            ['key' => 'homepage.feature.6.title', 'value' => 'پشتیبانی ۲۴/۷',                                                           'description' => 'عنوان ویژگی ۶'],
            ['key' => 'homepage.feature.6.desc',  'value' => 'تیم پشتیبانی آماده پاسخگویی در تمام ساعات شبانه‌روز',                   'description' => 'توضیح ویژگی ۶'],

            // CTA section
            ['key' => 'homepage.cta.title',    'value' => 'همین الان شروع کنید',                     'description' => 'عنوان بخش دعوت به اقدام'],
            ['key' => 'homepage.cta.subtitle', 'value' => 'ثبت‌نام رایگان، خرید آسان و اتصال فوری', 'description' => 'زیرعنوان بخش دعوت به اقدام'],
        ];

        foreach ($defaults as $item) {
            // Only insert if the key does not exist — never overwrite admin-edited values
            SiteText::firstOrCreate(
                ['key' => $item['key']],
                ['value' => $item['value'], 'description' => $item['description']]
            );
        }
    }
}
