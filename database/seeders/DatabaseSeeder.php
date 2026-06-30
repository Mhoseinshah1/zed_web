<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SiteTextSeeder::class,
            WalletSettingsSeeder::class,
            ServiceSettingsSeeder::class,
            FeatureSeeder::class,
            LocationSeeder::class,
            PlanSeeder::class,
            PaymentMethodSeeder::class,
            DefaultPagesSeeder::class,
            ContentSeeder::class,
            HomepageTemplateSeeder::class,
            ShopTemplateSeeder::class,
            WoodmartTemplateSeeder::class,
            TelegramAdminSeeder::class,
        ]);
    }
}
