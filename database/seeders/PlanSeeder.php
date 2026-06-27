<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'name'          => 'استارتر',
                'slug'          => 'starter',
                'description'   => 'مناسب برای استفاده روزانه سبک',
                'traffic_gb'    => 30,
                'duration_days' => 30,
                'price_toman'   => 49000,
                'is_active'     => true,
                'is_featured'   => false,
                'is_economic'   => true,
                'sort_order'    => 1,
                'badge'         => null,
            ],
            [
                'name'          => 'حرفه‌ای',
                'slug'          => 'pro',
                'description'   => 'مناسب برای استفاده روزانه حرفه‌ای',
                'traffic_gb'    => 100,
                'duration_days' => 30,
                'price_toman'   => 89000,
                'is_active'     => true,
                'is_featured'   => true,
                'is_economic'   => false,
                'sort_order'    => 2,
                'badge'         => 'محبوب‌ترین',
            ],
            [
                'name'          => 'بیزینس',
                'slug'          => 'business',
                'description'   => 'مناسب برای تیم‌ها و کاربری سنگین',
                'traffic_gb'    => null,
                'duration_days' => 30,
                'price_toman'   => 189000,
                'is_active'     => true,
                'is_featured'   => false,
                'is_economic'   => false,
                'sort_order'    => 3,
                'badge'         => null,
            ],
        ];

        foreach ($defaults as $item) {
            Plan::firstOrCreate(
                ['slug' => $item['slug']],
                $item
            );
        }
    }
}
