<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use App\Services\Theme\TemplateManager;
use Illuminate\Database\Seeder;

/**
 * Seeds the default active homepage template (classic) ONLY if missing, using
 * firstOrCreate so re-running (e.g. via update.sh) never overwrites the value
 * an admin has chosen in the panel.
 */
class HomepageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        SiteSetting::firstOrCreate(
            ['key' => TemplateManager::SETTING_KEY],
            ['value' => TemplateManager::DEFAULT_TEMPLATE],
        );
    }
}
