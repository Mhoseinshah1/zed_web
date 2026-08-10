<?php

namespace App\Services\Settings;

use App\Models\SiteSetting;

class SettingsLifecycle
{
    public function reset(): void
    {
        SiteSetting::flush();
    }
}
