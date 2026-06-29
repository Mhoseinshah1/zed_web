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
                PanelsRenderHook::HEAD_START,
                fn (): HtmlString => new HtmlString($this->adminBootScript()),
            )
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

    /**
     * Boot script injected at the very start of <head>: applies the active
     * admin theme (data-theme + shape tokens) before first paint to avoid a
     * flash of the wrong theme. Filament manages the light/dark class itself.
     */
    protected function adminBootScript(): string
    {
        try {
            $theme       = ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN);
            $cardRadius  = (string) SiteSetting::get('card_radius', '0.9rem');
            $btnRadius   = (string) SiteSetting::get('button_radius', '0.6rem');
            $anim        = ThemeManager::animationSpeed();
            $iconSize    = (string) SiteSetting::get('icon_size', '1.25rem');
            $sidebarIcon = (string) SiteSetting::get('sidebar_icon_size', '1.25rem');
            $animOff     = ThemeManager::animationIntensity() === 'off' ? '1' : '0';
        } catch (\Throwable $e) {
            return '';
        }

        $theme = e($theme);
        return <<<HTML
<script>(function(){try{var el=document.documentElement;
el.setAttribute('data-theme','{$theme}');
if({$animOff})el.classList.add('zed-anim-none');
el.style.setProperty('--zp-card-radius','{$cardRadius}');
el.style.setProperty('--zp-button-radius','{$btnRadius}');
el.style.setProperty('--zp-animation-speed','{$anim}');
el.style.setProperty('--zp-icon-size','{$iconSize}');
el.style.setProperty('--zp-sidebar-icon-size','{$sidebarIcon}');
}catch(e){}})();</script>
HTML;
    }

    /**
     * Injects the full design-token stylesheet (plain CSS — Filament does not
     * load app.css) plus an admin-scoped light-mode sync and density tweaks, so
     * the selected admin theme reskins the Filament chrome.
     */
    protected function adminHeadStyles(): string
    {
        $tokens = $this->themeTokensCss();
        if ($tokens === '') {
            return '';
        }

        $out = '<style id="zp-theme-tokens">' . $tokens . '</style>';

        // Filament toggles `.dark` on <html>; mirror its light mode onto our
        // neutral ramp (admin-scoped so the user side is unaffected).
        $lightRamp = 'html:not(.dark){'
            . '--zp-bg:#eef2fb;--zp-bg-soft:#e6ebf7;--zp-surface:#ffffff;--zp-surface-soft:#f3f6fc;'
            . '--zp-surface-hover:#e9eef8;--zp-text:#1c2233;--zp-text-muted:#5f6883;--zp-border:#d8deec;'
            . '--zp-card-shadow:0 10px 30px -14px rgb(30 40 80 / .18);}';
        $out .= '<style id="zp-admin-light">' . $lightRamp . '</style>';

        try {
            $fontScale  = (int) SiteSetting::get('font_scale', 100);
            $tableDense = (string) SiteSetting::get('table_density', 'comfortable');
        } catch (\Throwable $e) {
            $fontScale = 100;
            $tableDense = 'comfortable';
        }

        $extra = '';
        if ($fontScale !== 100 && $fontScale >= 80 && $fontScale <= 130) {
            $extra .= 'html{font-size:' . round($fontScale / 100 * 16, 1) . 'px;}';
        }
        if ($tableDense === 'compact') {
            $extra .= '.fi-ta-cell{padding-top:.4rem!important;padding-bottom:.4rem!important;}';
        } elseif ($tableDense === 'comfortable') {
            $extra .= '.fi-ta-cell{padding-top:.85rem!important;padding-bottom:.85rem!important;}';
        }
        if ($extra !== '') {
            $out .= '<style>' . $extra . '</style>';
        }

        return $out;
    }

    /** Read + cache the plain-CSS design token file. */
    protected function themeTokensCss(): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $path = resource_path('css/theme-tokens.css');
        return $cache = is_file($path) ? (string) file_get_contents($path) : '';
    }
}
