<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Defaults for the shop homepage template. Uses firstOrCreate so re-running
 * (e.g. via update.sh) never overwrites a value the admin has set. No sample
 * testimonials are seeded — the testimonials section stays hidden until the
 * admin enables it AND adds at least one testimonial.
 */
class ShopTemplateSeeder extends Seeder
{
    public function run(): void
    {
        SiteSetting::firstOrCreate(
            ['key' => 'shop_testimonials_enabled'],
            ['value' => 'false'],
        );
    }
}
