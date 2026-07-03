<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Legacy redirect — the old Theme Studio («پنل تم» / «استودیو تم») was replaced
 * by the single, simple «تنظیمات ظاهر» page ({@see AppearanceSettings}). This
 * stub keeps the old /zed-admin/theme-studio URL working and is hidden from
 * navigation so only one appearance entry appears. No settings data is touched;
 * every previously saved key is still read by the appearance resolvers.
 */
class ThemeStudio extends Page
{
    protected static ?string $slug = 'theme-studio';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'تنظیمات ظاهر';

    protected static string $view = 'filament.pages.appearance-redirect';

    public function mount(): void
    {
        $this->redirect(AppearanceSettings::getUrl());
    }
}
