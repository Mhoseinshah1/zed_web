<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['title' => 'بدون محدودیت سرعت',  'icon' => '⚡', 'sort_order' => 1],
            ['title' => 'مناسب یوتیوب',        'icon' => '📺', 'sort_order' => 2],
            ['title' => 'بدون ضریب',            'icon' => '✅', 'sort_order' => 3],
            ['title' => 'پشتیبانی سریع',        'icon' => '🎧', 'sort_order' => 4],
            ['title' => 'اتصال پایدار',         'icon' => '🔗', 'sort_order' => 5],
            ['title' => 'لوکیشن‌های متنوع',    'icon' => '🌍', 'sort_order' => 6],
            ['title' => 'مناسب گیم',            'icon' => '🎮', 'sort_order' => 7],
            ['title' => 'مناسب ترید',           'icon' => '📈', 'sort_order' => 8],
            ['title' => 'مناسب اینستاگرام',     'icon' => '📸', 'sort_order' => 9],
            ['title' => 'مناسب تلگرام',         'icon' => '✈️', 'sort_order' => 10],
        ];

        foreach ($defaults as $item) {
            $slug = Str::slug($item['title'], '-', 'fa');
            if (empty($slug)) {
                $slug = 'feature-' . $item['sort_order'];
            }

            Feature::firstOrCreate(
                ['slug' => $slug],
                [
                    'title'      => $item['title'],
                    'icon'       => $item['icon'],
                    'is_active'  => true,
                    'sort_order' => $item['sort_order'],
                ]
            );
        }
    }
}
