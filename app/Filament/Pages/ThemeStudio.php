<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Legacy redirect — the old «استودیو تم» (Theme Studio) has been replaced by the
 * simpler «تنظیمات ظاهر» ({@see AppearanceSettings}). This stub keeps the old
 * /zed-admin/theme-studio URL working by redirecting it, and is hidden from the
 * navigation so only the new page appears. No settings data is touched.
 */
class ThemeStudio extends Page
{
    protected static ?string $slug = 'theme-studio';

    protected static bool $shouldRegisterNavigation = false;

    /** Keep the heading minimal; users are redirected away on mount. */
    protected static ?string $title = 'تنظیمات ظاهر';

    protected static string $view = 'filament.pages.appearance-redirect';

    public function mount(): void
    {
        $this->redirect(AppearanceSettings::getUrl());
    }
}
