<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Default copy for the WoodMart homepage template. Uses firstOrCreate so
 * re-running (e.g. via update.sh) NEVER overwrites a value the admin has edited
 * in the panel. Only static copy is seeded — all products/categories/locations
 * are read live from the real models at render time.
 */
class WoodmartTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // top utility bar
            'woodmart_topbar_delivery'  => 'تحویل آنی',
            'woodmart_topbar_support'   => 'پشتیبانی ۲۴ ساعته',
            'woodmart_topbar_guarantee' => '۷ روز ضمانت بازگشت وجه',
            // header
            'woodmart_search_placeholder' => 'جستجوی پلن، لوکیشن...',
            'woodmart_login_label'        => 'ورود / پنل',
            'woodmart_nav_all'            => 'همه محصولات',
            'woodmart_nav_tutorials'      => 'آموزش‌ها',
            'woodmart_nav_support'        => 'پشتیبانی',
            // hero banner
            'woodmart_hero_tag'   => '🔥 پیشنهاد ویژه این هفته',
            'woodmart_hero_title' => 'اینترنت آزاد، سریع و بدون محدودیت',
            'woodmart_hero_desc'  => 'پلن‌های پرسرعت با تحویل آنی و پشتیبانی همیشگی. همین حالا انتخاب کن.',
            'woodmart_hero_cta'   => 'مشاهده پلن‌ها',
            // side banners
            'woodmart_side1_title' => '۲ ماه رایگان 🎁',
            'woodmart_side1_desc'  => 'با خرید پلن سالانه',
            'woodmart_side1_link'  => 'فعال‌سازی',
            'woodmart_side2_title' => 'سرورهای جهانی',
            'woodmart_side2_desc'  => 'سرور در سراسر دنیا',
            'woodmart_side2_link'  => 'لوکیشن‌ها',
            // feature strip
            'woodmart_feat1_title' => 'تحویل آنی',    'woodmart_feat1_desc' => 'فعال‌سازی خودکار',
            'woodmart_feat2_title' => 'اتصال امن',    'woodmart_feat2_desc' => 'رمزنگاری کامل',
            'woodmart_feat3_title' => 'پشتیبانی ۲۴/۷', 'woodmart_feat3_desc' => 'همیشه کنارت',
            'woodmart_feat4_title' => 'پرداخت امن',   'woodmart_feat4_desc' => 'درگاه معتبر',
            // section heads
            'woodmart_cats_title'     => 'دسته‌بندی سرویس‌ها',
            'woodmart_cats_sub'       => 'سرویس مناسب خودت رو پیدا کن',
            'woodmart_products_title' => 'پرفروش‌ترین پلن‌ها',
            'woodmart_products_sub'   => 'محبوب‌ترین انتخاب کاربران',
            'woodmart_products_more'  => 'مشاهده همه',
            'woodmart_more'           => 'همه',
            // footer
            'woodmart_footer_text' => 'فروشگاه سرویس‌های امن و پرسرعت اتصال به اینترنت آزاد.',
            'woodmart_footer_col1' => 'محصولات',
            'woodmart_footer_col2' => 'پشتیبانی',
            'woodmart_footer_col3' => 'حساب',
        ];

        foreach ($defaults as $key => $value) {
            SiteSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
