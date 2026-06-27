<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['country_name' => 'آلمان',   'country_code' => 'DE', 'flag_emoji' => '🇩🇪', 'sort_order' => 1],
            ['country_name' => 'آمریکا',  'country_code' => 'US', 'flag_emoji' => '🇺🇸', 'sort_order' => 2],
            ['country_name' => 'انگلیس',  'country_code' => 'GB', 'flag_emoji' => '🇬🇧', 'sort_order' => 3],
            ['country_name' => 'فرانسه',  'country_code' => 'FR', 'flag_emoji' => '🇫🇷', 'sort_order' => 4],
            ['country_name' => 'هلند',    'country_code' => 'NL', 'flag_emoji' => '🇳🇱', 'sort_order' => 5],
            ['country_name' => 'ترکیه',   'country_code' => 'TR', 'flag_emoji' => '🇹🇷', 'sort_order' => 6],
            ['country_name' => 'فنلاند',  'country_code' => 'FI', 'flag_emoji' => '🇫🇮', 'sort_order' => 7],
            ['country_name' => 'سوئیس',   'country_code' => 'CH', 'flag_emoji' => '🇨🇭', 'sort_order' => 8],
            ['country_name' => 'ایتالیا', 'country_code' => 'IT', 'flag_emoji' => '🇮🇹', 'sort_order' => 9],
            ['country_name' => 'اسپانیا', 'country_code' => 'ES', 'flag_emoji' => '🇪🇸', 'sort_order' => 10],
        ];

        foreach ($defaults as $item) {
            Location::firstOrCreate(
                ['country_code' => $item['country_code']],
                [
                    'country_name'     => $item['country_name'],
                    'flag_emoji'       => $item['flag_emoji'],
                    'is_active'        => true,
                    'is_youtube_special' => false,
                    'sort_order'       => $item['sort_order'],
                ]
            );
        }
    }
}
