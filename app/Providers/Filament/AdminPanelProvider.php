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
use App\Services\Theme\AdminAppearanceResolver;
use App\Services\Theme\AppearanceManager;
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
        [$primaryColor, $themeMode, $brandText] = $this->resolveAdminTheme();

        return $panel
            ->default()
            ->id('zed-admin')
            ->path('zed-admin')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName($brandText)
            ->defaultThemeMode($themeMode)
            ->colors([
                'primary' => $primaryColor,
                'danger'  => Color::Red,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->navigationGroups([
                'داشبورد',
                'کاربران',
                'فروشگاه و پلن‌ها',
                'سرویس‌ها و پنل‌های VPN',
                'سفارش‌ها و مالی',
                'پشتیبانی',
                'نمایندگان و بازاریابی',
                'مدیریت محتوا',
                'اعلان‌ها و پیام‌ها',
                'ظاهر سایت',
                'سیستم',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
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
     * @return array{0: array<int,string>, 1: ThemeMode, 2: string}
     */
    protected function resolveAdminTheme(): array
    {
        $primary   = Color::Indigo;
        $themeMode = ThemeMode::Dark;
        $brandText = 'ZedProxy Admin';

        try {
            $hex = AppearanceManager::activePalette()['primary'] ?? null;
            if ($hex) {
                $primary = Color::hex($hex);
            }

            $themeMode = match (AppearanceManager::appearanceMode()) {
                'light'  => ThemeMode::Light,
                'system' => ThemeMode::System,
                default  => ThemeMode::Dark,
            };

            $brandText = AdminAppearanceResolver::brandText();
        } catch (\Throwable $e) {
            // Fall back to defaults if settings aren't available yet.
        }

        return [$primary, $themeMode, $brandText];
    }

    /**
     * Boot script injected at the very start of <head>: applies the active
     * admin theme (data-theme + shape tokens) before first paint to avoid a
     * flash of the wrong theme. Filament manages the light/dark class itself.
     */
    protected function adminBootScript(): string
    {
        try {
            $r = AdminAppearanceResolver::resolve();
        } catch (\Throwable $e) {
            return '';
        }

        // The actual sizing/colour variables are delivered DECLARATIVELY via the
        // theme-vars <style> block (no JS dependency). This early script only
        // sets <html> attributes that CSS hooks onto — the active preset and
        // density/sidebar/brand selectors — before first paint.
        $preset  = e($r['preset']);
        $density = e($r['density']);
        $sidebar = e($r['sidebar_size']);
        $brand   = e($r['brand_display']);

        return <<<HTML
<script>(function(){try{var el=document.documentElement;
el.setAttribute('data-theme','{$preset}');
el.setAttribute('data-zp-admin-preset','{$preset}');
el.setAttribute('data-zp-admin-density','{$density}');
el.setAttribute('data-zp-admin-sidebar','{$sidebar}');
el.setAttribute('data-zp-admin-brand','{$brand}');
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

        // Declarative, database-driven admin appearance variables. Rendered
        // AFTER the base tokens so the resolved values win the cascade, and
        // without relying on JavaScript to apply them.
        try {
            $out .= view('filament.admin.theme-vars')->render();
        } catch (\Throwable $e) {
            // If the resolver/view fails, the base token defaults still apply.
        }

        // Admin SHELL theme — the polish layer that wires Filament's fi-*
        // components to the --zp-admin-* variables (rounded cards, soft shadows,
        // consistent buttons/forms/tables/modals/badges/sidebar). Injected last
        // and admin-only (never imported into app.css), scoped under .fi-body.
        $shell = $this->adminShellCss();
        if ($shell !== '') {
            $out .= '<style id="zp-admin-shell">' . $shell . '</style>';
        }

        return $out;
    }

    /** Read + cache the plain-CSS design token file (re-reads when it changes). */
    protected function themeTokensCss(): string
    {
        return $this->readCssCached(resource_path('css/theme-tokens.css'));
    }

    /** Read + cache the admin-only Filament shell stylesheet (re-reads on change). */
    protected function adminShellCss(): string
    {
        return $this->readCssCached(resource_path('css/admin-theme.css'));
    }

    /**
     * Read a CSS file, caching by modification time.
     *
     * A plain `static` cache of the file contents is memoised for the life of
     * the PHP worker (FPM keeps workers around, and `artisan serve`/Octane keep
     * one process), so once a worker read the old CSS it kept serving it — even
     * after `optimize:clear`/`view:clear`, which don't reset in-process statics
     * or recycle workers. Keying the cache on filemtime (with a fresh stat) makes
     * an edit to the file take effect on the very next request, no restart needed.
     */
    protected function readCssCached(string $path): string
    {
        static $cache = [];

        clearstatcache(true, $path);
        if (! is_file($path)) {
            return '';
        }

        $mtime = @filemtime($path) ?: 0;
        if (! isset($cache[$path]) || $cache[$path]['mtime'] !== $mtime) {
            $cache[$path] = ['mtime' => $mtime, 'css' => (string) file_get_contents($path)];
        }

        return $cache[$path]['css'];
    }
}
