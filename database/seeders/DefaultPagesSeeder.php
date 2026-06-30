<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Guarantees the core static pages (about / terms / privacy) always exist so
 * their routes never 404. Uses firstOrCreate keyed on the slug, so admin-edited
 * content is never overwritten on re-run (update.sh safe).
 */
class DefaultPagesSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug'  => 'about',
                'title' => 'درباره ما',
                'body'  => '<p>ZedProxy ارائه‌دهنده‌ی سرویس‌های امن و پرسرعت اتصال به اینترنت آزاد است. این متن نمونه است و از پنل مدیریت قابل ویرایش می‌باشد.</p>',
            ],
            [
                'slug'  => 'terms',
                'title' => 'قوانین و مقررات',
                'body'  => '<p>با خرید و استفاده از سرویس‌های ZedProxy، قوانین و مقررات زیر را می‌پذیرید. این متن نمونه است و از پنل مدیریت قابل ویرایش می‌باشد.</p>',
            ],
            [
                'slug'  => 'privacy',
                'title' => 'حریم خصوصی',
                'body'  => '<p>ما به حریم خصوصی شما متعهد هستیم و هیچ لاگی از فعالیت کاربران نگه نمی‌داریم. این متن نمونه است و از پنل مدیریت قابل ویرایش می‌باشد.</p>',
            ],
        ];

        $order = 0;
        foreach ($pages as $page) {
            Page::firstOrCreate(
                ['slug' => $page['slug']],
                [
                    'title'          => $page['title'],
                    'content'        => $page['body'],
                    'is_active'      => true,
                    'show_in_footer' => true,
                    'sort_order'     => $order++,
                ],
            );
        }
    }
}
