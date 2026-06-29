<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use App\Models\SiteSetting;
use App\Services\Theme\ThemeManager;
use Filament\Enums\ThemeMode;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        [$primaryColor, $themeMode] = $this->resolveAdminTheme();

        return $panel
            ->default()
            ->id('zed-admin')
            ->path('zed-admin')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName('ZedProxy Admin')
            ->defaultThemeMode($themeMode)
            ->colors([
                'primary' => $primaryColor,
                'danger'  => Color::Red,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->navigationGroups([
                'کاربران و سفارش‌ها',
                'فروشگاه',
                'مالی',
                'پشتیبانی',
                'بازاریابی',
                'سیستم و یکپارچه‌سازی',
                'تنظیمات',
                'ظاهر سایت',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\StatsOverviewWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString($this->adminHeadStyles()),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Resolve the admin primary color + default theme mode from DB settings.
     * Wrapped defensively so a missing settings table (fresh install/migrate)
     * never breaks the panel boot.
     *
     * @return array{0: array<int,string>, 1: ThemeMode}
     */
    protected function resolveAdminTheme(): array
    {
        $primary   = Color::Indigo;
        $themeMode = ThemeMode::Dark;

        try {
            $themeKey = ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN);
            $hex      = ThemeManager::presets()[$themeKey]['dots'][0] ?? null;
            if ($hex) {
                $primary = Color::hex($hex);
            }

            $appearance = (string) SiteSetting::get('default_appearance', 'dark');
            $themeMode  = match ($appearance) {
                'light'  => ThemeMode::Light,
                'system' => ThemeMode::System,
                default  => ThemeMode::Dark,
            };
        } catch (\Throwable $e) {
            // Fall back to defaults if settings aren't available yet.
        }

        return [$primary, $themeMode];
    }

    /** Inline density / shape / font tokens for the admin panel <head>. */
    protected function adminHeadStyles(): string
    {
        try {
            $fontScale   = (int) SiteSetting::get('font_scale', 100);
            $tableDense  = (string) SiteSetting::get('table_density', 'comfortable');
        } catch (\Throwable $e) {
            return '';
        }

        $css = '';
        if ($fontScale !== 100 && $fontScale >= 80 && $fontScale <= 130) {
            $css .= 'html{font-size:' . round($fontScale / 100 * 16, 1) . 'px;}';
        }
        if ($tableDense === 'compact') {
            $css .= '.fi-ta-cell{padding-top:.4rem!important;padding-bottom:.4rem!important;}';
        }

        return $css === '' ? '' : '<style>' . $css . '</style>';
    }
}
