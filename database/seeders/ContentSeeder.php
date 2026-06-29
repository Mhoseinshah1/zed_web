<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Page;
use App\Models\PlanCategory;
use App\Models\SiteText;
use App\Models\Tutorial;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds default CMS content ONLY when missing. Never overwrites admin-edited
 * values: text via SiteText::seedDefault(), records via firstOrCreate keyed on
 * a stable unique column.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedFaqs();
        $this->seedPages();
        $this->seedTutorials();
        $this->seedPlanCategories();
        $this->seedSnippets();
    }

    private function seedSettings(): void
    {
        $defaults = [
            // Branding / site
            'site_name'        => 'ZedProxy',
            'brand_name'       => 'ZedProxy',
            'site_title'       => 'ZedProxy؛ سرویس VPN و پروکسی',
            'site_description' => 'سرویس‌های پرسرعت برای اتصال امن و پایدار',
            'footer_text'      => 'ارائه‌دهنده خدمات VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و پشتیبانی ۲۴ ساعته.',
            'copyright_text'   => 'ZedProxy. تمامی حقوق محفوظ است.',
            'support_title'    => 'پشتیبانی',
            'support_description' => 'تیم پشتیبانی ما آماده کمک به شماست.',
            'primary_cta_text' => 'مشاهده پلن‌ها',
            'primary_cta_url'  => '/plans',
            // Hero
            'hero_title'                 => 'ZedProxy؛ اتصال پایدار، سریع و امن',
            'hero_subtitle'              => 'اینترنت آزاد، سریع و پایدار با ZedProxy',
            'hero_description'           => 'سرویس‌های پرسرعت برای اتصال امن و پایدار',
            'hero_badge_text'            => 'سرویس حرفه‌ای VPN و Proxy',
            'hero_primary_button_text'   => 'خرید سرویس',
            'hero_primary_button_url'    => '/plans',
            'hero_secondary_button_text' => 'ورود به داشبورد',
            'hero_secondary_button_url'  => '/dashboard',
            'hero_is_active'             => '1',
            // Shop
            'shop_page_title'    => 'پلن‌های ZedProxy',
            'shop_page_subtitle' => 'پلن مناسب خود را انتخاب کنید',
            'trust_text'         => 'بیش از هزاران کاربر به ZedProxy اعتماد کرده‌اند.',
            'guarantee_text'     => 'ضمانت بازگشت وجه در صورت عدم رضایت.',
            'payment_help_text'  => 'پرداخت امن از طریق درگاه بانکی و کیف پول.',
            'discount_help_text' => 'در صورت داشتن کد تخفیف، آن را در مرحله پرداخت وارد کنید.',
        ];

        foreach ($defaults as $key => $value) {
            SiteText::seedDefault($key, $value, ['group' => 'محتوای سایت', 'label' => $key]);
        }
    }

    private function seedFaqs(): void
    {
        $faqs = [
            'چطور سرویس بخرم؟' => 'از صفحه پلن‌ها، پلن موردنظر را انتخاب کرده و مراحل پرداخت را تکمیل کنید.',
            'بعد از خرید چطور وصل شوم؟' => 'پس از خرید، اطلاعات اتصال در داشبورد و بخش سرویس‌های من نمایش داده می‌شود.',
            'پرداخت با کیف پول چطور انجام می‌شود؟' => 'ابتدا کیف پول خود را شارژ کنید، سپس هنگام خرید گزینه پرداخت با کیف پول را انتخاب کنید.',
            'تمدید سرویس از کجاست؟' => 'از بخش سرویس‌های من، روی دکمه تمدید سرویس موردنظر کلیک کنید.',
            'اگر اتصال مشکل داشت چه کنم؟' => 'ابتدا آموزش‌ها را بررسی کنید و در صورت نیاز تیکت پشتیبانی ثبت کنید.',
            'بعد از خرید چطور سرویس را دریافت کنم؟' => 'بلافاصله پس از تأیید پرداخت، سرویس در داشبورد شما فعال می‌شود.',
            'آیا امکان تمدید سرویس وجود دارد؟' => 'بله، تمامی سرویس‌های فعال قابل تمدید هستند.',
            'پرداخت از چه روش‌هایی انجام می‌شود؟' => 'پرداخت از طریق درگاه بانکی، کیف پول و ارز دیجیتال امکان‌پذیر است.',
        ];

        $order = 0;
        foreach ($faqs as $question => $answer) {
            Faq::firstOrCreate(['question' => $question], [
                'answer' => $answer, 'is_active' => true, 'sort_order' => $order++,
            ]);
        }
    }

    private function seedPages(): void
    {
        $pages = [
            'terms'    => 'قوانین خرید',
            'privacy'  => 'حریم خصوصی',
            'about'    => 'درباره ما',
            'contact-us' => 'تماس با ما',
            'buy-guide' => 'راهنمای خرید',
            'connection-guide' => 'آموزش اتصال',
        ];

        $order = 0;
        foreach ($pages as $slug => $title) {
            Page::firstOrCreate(['slug' => $slug], [
                'title'          => $title,
                'content'        => "<p>{$title} — این متن نمونه است و از پنل مدیریت قابل ویرایش می‌باشد.</p>",
                'is_active'      => true,
                'show_in_footer' => in_array($slug, ['terms', 'privacy', 'about'], true),
                'sort_order'     => $order++,
            ]);
        }
    }

    private function seedTutorials(): void
    {
        $tutorials = [
            ['title' => 'آموزش اتصال در اندروید', 'platform' => 'android'],
            ['title' => 'آموزش اتصال در آیفون',  'platform' => 'ios'],
            ['title' => 'آموزش اتصال در ویندوز', 'platform' => 'windows'],
        ];

        $order = 0;
        foreach ($tutorials as $t) {
            Tutorial::firstOrCreate(['slug' => Str::slug($t['title']) ?: 'tutorial-' . $order], [
                'title'             => $t['title'],
                'platform'          => $t['platform'],
                'short_description' => 'راهنمای گام‌به‌گام اتصال به سرویس.',
                'content'           => '<p>مراحل اتصال در این آموزش شرح داده می‌شود. این محتوا از پنل مدیریت قابل ویرایش است.</p>',
                'is_active'         => true,
                'sort_order'        => $order++,
            ]);
        }
    }

    private function seedPlanCategories(): void
    {
        $categories = ['اقتصادی', 'پرسرعت', 'گیمینگ', 'یوتیوب', 'ویژه', 'نامحدود'];
        $order = 0;
        foreach ($categories as $title) {
            PlanCategory::firstOrCreate(['slug' => Str::slug($title, '-', 'fa') ?: 'cat-' . $order], [
                'title' => $title, 'is_active' => true, 'sort_order' => $order++,
            ]);
        }
    }

    private function seedSnippets(): void
    {
        $snippets = [
            'checkout_help_text'     => 'برای تکمیل خرید، روش پرداخت را انتخاب و مراحل را دنبال کنید.',
            'wallet_topup_help_text' => 'مبلغ موردنظر برای شارژ کیف پول را وارد کنید.',
            'service_delivery_note'  => 'پس از تأیید پرداخت، سرویس بلافاصله فعال می‌شود.',
            'support_box_text'       => 'سوالی دارید؟ تیم پشتیبانی ما آماده پاسخگویی است.',
            'discount_code_help_text' => 'کد تخفیف خود را وارد کنید تا روی مبلغ نهایی اعمال شود.',
            'empty_services_text'    => 'هنوز سرویسی ندارید. از فروشگاه یک پلن تهیه کنید.',
            'empty_orders_text'      => 'هنوز سفارشی ثبت نکرده‌اید.',
        ];

        foreach ($snippets as $key => $value) {
            SiteText::seedDefault($key, $value, ['group' => 'متن‌های عمومی', 'label' => $key]);
        }
    }
}
