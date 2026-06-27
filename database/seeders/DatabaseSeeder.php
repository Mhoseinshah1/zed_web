<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SiteTextSeeder::class,
            FeatureSeeder::class,
            LocationSeeder::class,
            PlanSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
