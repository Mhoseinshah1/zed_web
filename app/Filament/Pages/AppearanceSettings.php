<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Legacy redirect — the standalone «تنظیمات ظاهر» page was consolidated into the
 * single «پنل تم» theme panel ({@see ThemeStudio}). This stub keeps the old
 * /zed-admin/appearance URL working and is hidden from navigation so only one
 * appearance entry appears. No settings data is touched.
 */
class AppearanceSettings extends Page
{
    protected static ?string $slug = 'appearance';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'پنل تم';

    protected static string $view = 'filament.pages.appearance-redirect';

    public function mount(): void
    {
        $this->redirect(ThemeStudio::getUrl());
    }
}
