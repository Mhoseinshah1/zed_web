<?php

namespace App\Services\Theme;

use App\Models\SiteSetting;
use App\Models\User;

/**
 * Resolves the active theme preset, appearance (light/dark/system) and shape
 * tokens from database settings + per-user preference. All values are stored in
 * the database; nothing requires .env changes.
 *
 * Canonical preset slugs are prefixed `zed-`. Older unprefixed slugs are
 * normalised on read so previously-saved preferences keep working.
 */
class ThemeManager
{
    public const SURFACE_PUBLIC = 'public';
    public const SURFACE_USER   = 'user';
    public const SURFACE_ADMIN  = 'admin';

    public const DEFAULT_THEME = 'zed-ocean';

    /**
     * Full preset catalog with rich metadata for the Theme Studio gallery and
     * live preview.
     *
     * @return array<string, array{
     *   name:string, title:string, description:string, group:string,
     *   appearance:string, dots:array<int,string>, colors:array<string,string>
     * }>
     */
    public static function presets(): array
    {
        return [
            'zed-ocean' => [
                'name' => 'Zed Ocean', 'title' => 'دریای آبی',
                'description' => 'آبی · پیش‌فرض · حرفه‌ای', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#3b82f6', '#22d3ee', '#0ea5e9'],
                'colors' => ['primary' => '#3b82f6', 'secondary' => '#0ea5e9', 'accent' => '#22d3ee', 'bg' => '#0a0e1a', 'surface' => '#141a2b', 'surface_soft' => '#1c2438', 'text' => '#e8ebf5', 'muted' => '#9aa3bd', 'border' => '#283047', 'gradient' => 'linear-gradient(135deg,#1e3a8a,#3b82f6 50%,#22d3ee)'],
            ],
            'zed-cyber-dark' => [
                'name' => 'Zed Cyber Dark', 'title' => 'سایبر دارک',
                'description' => 'مشکی · آبی · نئون', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#6366f1', '#22d3ee', '#a855f7'],
                'colors' => ['primary' => '#6366f1', 'secondary' => '#a855f7', 'accent' => '#22d3ee', 'bg' => '#070912', 'surface' => '#111527', 'surface_soft' => '#181d33', 'text' => '#e8ebf5', 'muted' => '#9aa3bd', 'border' => '#232a47', 'gradient' => 'linear-gradient(135deg,#6366f1,#a855f7 50%,#22d3ee)'],
            ],
            'zed-luxury-gold' => [
                'name' => 'Zed Luxury Gold', 'title' => 'طلایی لوکس',
                'description' => 'مشکی · طلایی · پرمیوم', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#d4af37', '#facc15', '#b8860b'],
                'colors' => ['primary' => '#d4af37', 'secondary' => '#b8860b', 'accent' => '#facc15', 'bg' => '#0b0a07', 'surface' => '#191510', 'surface_soft' => '#221c13', 'text' => '#f1ece0', 'muted' => '#b6ab92', 'border' => '#3a3020', 'gradient' => 'linear-gradient(135deg,#b8860b,#d4af37 50%,#facc15)'],
            ],
            'zed-graphite' => [
                'name' => 'Zed Graphite', 'title' => 'گرافیت',
                'description' => 'خاکستری · سازمانی · مینیمال', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#64748b', '#38bdf8', '#475569'],
                'colors' => ['primary' => '#64748b', 'secondary' => '#475569', 'accent' => '#38bdf8', 'bg' => '#0d0f12', 'surface' => '#191d24', 'surface_soft' => '#212630', 'text' => '#e6e9ef', 'muted' => '#969eae', 'border' => '#313844', 'gradient' => 'linear-gradient(135deg,#334155,#64748b 50%,#94a3b8)'],
            ],
            'zed-emerald' => [
                'name' => 'Zed Emerald', 'title' => 'زمرد سبز',
                'description' => 'سبز · امن · آرام', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#10b981', '#2dd4bf', '#059669'],
                'colors' => ['primary' => '#10b981', 'secondary' => '#059669', 'accent' => '#2dd4bf', 'bg' => '#06100c', 'surface' => '#0f1f18', 'surface_soft' => '#152a20', 'text' => '#e3f1ea', 'muted' => '#8fb0a2', 'border' => '#234534', 'gradient' => 'linear-gradient(135deg,#065f46,#10b981 50%,#2dd4bf)'],
            ],
            'zed-aurora' => [
                'name' => 'Zed Aurora', 'title' => 'بنفش رویا',
                'description' => 'بنفش · مدرن · گرادینت', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#a855f7', '#60a5fa', '#ec4899'],
                'colors' => ['primary' => '#a855f7', 'secondary' => '#ec4899', 'accent' => '#60a5fa', 'bg' => '#0b0814', 'surface' => '#181228', 'surface_soft' => '#211837', 'text' => '#ece6f5', 'muted' => '#a194b8', 'border' => '#342552', 'gradient' => 'linear-gradient(135deg,#7c3aed,#ec4899 50%,#60a5fa)'],
            ],
            'zed-matrix' => [
                'name' => 'Zed Matrix', 'title' => 'ماتریکس',
                'description' => 'مشکی · سبز · تکنیکال', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#22c55e', '#a3e635', '#16a34a'],
                'colors' => ['primary' => '#22c55e', 'secondary' => '#16a34a', 'accent' => '#a3e635', 'bg' => '#050a07', 'surface' => '#0d1a12', 'surface_soft' => '#122418', 'text' => '#d9f5e1', 'muted' => '#84ab90', 'border' => '#1d3d28', 'gradient' => 'linear-gradient(135deg,#14532d,#22c55e 50%,#a3e635)'],
            ],
            'zed-crimson' => [
                'name' => 'Zed Crimson', 'title' => 'قرمز عمیق',
                'description' => 'مشکی · قرمز · قدرتمند', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#ef4444', '#fb923c', '#b91c1c'],
                'colors' => ['primary' => '#ef4444', 'secondary' => '#b91c1c', 'accent' => '#fb923c', 'bg' => '#0f0708', 'surface' => '#1f1012', 'surface_soft' => '#2a1518', 'text' => '#f5e3e3', 'muted' => '#b89494', 'border' => '#451f24', 'gradient' => 'linear-gradient(135deg,#7f1d1d,#ef4444 50%,#fb923c)'],
            ],
            'zed-titanium' => [
                'name' => 'Zed Titanium', 'title' => 'تیتانیوم',
                'description' => 'فلزی · آبی · مدیریتی', 'group' => 'dark', 'appearance' => 'dark',
                'dots' => ['#60a5fa', '#38bdf8', '#64748b'],
                'colors' => ['primary' => '#60a5fa', 'secondary' => '#64748b', 'accent' => '#38bdf8', 'bg' => '#0a0d11', 'surface' => '#151b24', 'surface_soft' => '#1d2532', 'text' => '#e6eaf1', 'muted' => '#94a0b3', 'border' => '#2d3949', 'gradient' => 'linear-gradient(135deg,#475569,#60a5fa 60%,#94a3b8)'],
            ],
            'zed-neon' => [
                'name' => 'Zed Neon', 'title' => 'نئون',
                'description' => 'بنفش · آبی · آینده‌نگر', 'group' => 'special', 'appearance' => 'dark',
                'dots' => ['#a78bfa', '#22d3ee', '#d946ef'],
                'colors' => ['primary' => '#a78bfa', 'secondary' => '#d946ef', 'accent' => '#22d3ee', 'bg' => '#070611', 'surface' => '#120f27', 'surface_soft' => '#191436', 'text' => '#ece9fb', 'muted' => '#9d93c4', 'border' => '#2a2056', 'gradient' => 'linear-gradient(135deg,#06b6d4,#d946ef 50%,#a78bfa)'],
            ],
            'zed-frost' => [
                'name' => 'Zed Frost', 'title' => 'فروزن',
                'description' => 'شیشه‌ای · آبی یخی · نرم', 'group' => 'light', 'appearance' => 'light',
                'dots' => ['#38bdf8', '#22d3ee', '#818cf8'],
                'colors' => ['primary' => '#38bdf8', 'secondary' => '#818cf8', 'accent' => '#22d3ee', 'bg' => '#eef2fb', 'surface' => '#ffffff', 'surface_soft' => '#f3f6fc', 'text' => '#1c2233', 'muted' => '#5f6883', 'border' => '#d8deec', 'gradient' => 'linear-gradient(135deg,#0ea5e9,#38bdf8 50%,#a5b4fc)'],
            ],
            'zed-minimal-light' => [
                'name' => 'Zed Minimal Light', 'title' => 'روشن مینیمال',
                'description' => 'سفید · تمیز · سبک', 'group' => 'light', 'appearance' => 'light',
                'dots' => ['#2563eb', '#0ea5e9', '#6366f1'],
                'colors' => ['primary' => '#2563eb', 'secondary' => '#6366f1', 'accent' => '#0ea5e9', 'bg' => '#eef2fb', 'surface' => '#ffffff', 'surface_soft' => '#f3f6fc', 'text' => '#1c2233', 'muted' => '#5f6883', 'border' => '#d8deec', 'gradient' => 'linear-gradient(135deg,#2563eb,#0ea5e9)'],
            ],
            'zed-sky-light' => [
                'name' => 'Zed Sky Light', 'title' => 'آسمان روشن',
                'description' => 'آبی روشن · SaaS · حرفه‌ای', 'group' => 'light', 'appearance' => 'light',
                'dots' => ['#0ea5e9', '#06b6d4', '#3b82f6'],
                'colors' => ['primary' => '#0ea5e9', 'secondary' => '#3b82f6', 'accent' => '#06b6d4', 'bg' => '#eef2fb', 'surface' => '#ffffff', 'surface_soft' => '#f3f6fc', 'text' => '#1c2233', 'muted' => '#5f6883', 'border' => '#d8deec', 'gradient' => 'linear-gradient(135deg,#0ea5e9,#06b6d4)'],
            ],
            'zed-mint' => [
                'name' => 'Zed Mint', 'title' => 'نعناع سبز',
                'description' => 'سبز روشن · تازه · ساده', 'group' => 'light', 'appearance' => 'light',
                'dots' => ['#10b981', '#34d399', '#14b8a6'],
                'colors' => ['primary' => '#10b981', 'secondary' => '#14b8a6', 'accent' => '#34d399', 'bg' => '#edf7f2', 'surface' => '#ffffff', 'surface_soft' => '#f1faf5', 'text' => '#142b22', 'muted' => '#5b7568', 'border' => '#d2e9df', 'gradient' => 'linear-gradient(135deg,#10b981,#34d399)'],
            ],
            'zed-sunset' => [
                'name' => 'Zed Sunset', 'title' => 'غروب گرم',
                'description' => 'نارنجی · کهربایی · گرم', 'group' => 'special', 'appearance' => 'dark',
                'dots' => ['#f97316', '#a855f7', '#db2777'],
                'colors' => ['primary' => '#f97316', 'secondary' => '#db2777', 'accent' => '#a855f7', 'bg' => '#0f0a08', 'surface' => '#1f130d', 'surface_soft' => '#2a1912', 'text' => '#f5e8e0', 'muted' => '#b89c8c', 'border' => '#45261a', 'gradient' => 'linear-gradient(135deg,#9a3412,#f97316 45%,#a855f7)'],
            ],
        ];
    }

    /** @return array<int,string> */
    public static function presetKeys(): array
    {
        return array_keys(self::presets());
    }

    public static function isValidPreset(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::presets());
    }

    /**
     * Normalise a possibly-legacy slug to a canonical one (e.g. "cyber-dark"
     * → "zed-cyber-dark"). Returns null if it cannot be mapped.
     */
    public static function normalize(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (self::isValidPreset($key)) {
            return $key;
        }
        $prefixed = 'zed-' . ltrim($key, '-');
        return self::isValidPreset($prefixed) ? $prefixed : null;
    }

    /**
     * Preset slugs grouped by gallery group.
     *
     * @return array<string, array<int,string>>
     */
    public static function groups(): array
    {
        $groups = ['dark' => [], 'light' => [], 'special' => []];
        foreach (self::presets() as $key => $preset) {
            $groups[$preset['group']][] = $key;
        }
        return $groups;
    }

    /** @return array<string,string> group key => persian label */
    public static function groupLabels(): array
    {
        return ['dark' => 'تم‌های تیره', 'light' => 'تم‌های روشن', 'special' => 'تم‌های خاص'];
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public static function defaultTheme(string $surface): string
    {
        $key = match ($surface) {
            self::SURFACE_USER  => (string) SiteSetting::get('default_theme_user', self::DEFAULT_THEME),
            self::SURFACE_ADMIN => (string) SiteSetting::get('default_theme_admin', self::DEFAULT_THEME),
            default             => (string) SiteSetting::get('default_theme_public', self::DEFAULT_THEME),
        };
        return self::normalize($key) ?? self::DEFAULT_THEME;
    }

    /** @return array<int,string> enabled preset keys */
    public static function enabledThemes(): array
    {
        $raw = SiteSetting::get('enabled_themes', null);
        if (blank($raw)) {
            return self::presetKeys();
        }
        $keys = [];
        foreach (explode(',', (string) $raw) as $part) {
            $norm = self::normalize(trim($part));
            if ($norm !== null) {
                $keys[] = $norm;
            }
        }
        return array_values(array_unique($keys)) ?: self::presetKeys();
    }

    public static function allowUserThemeSwitch(): bool
    {
        return (bool) SiteSetting::get('allow_user_theme_switch', true) && ! self::forceGlobalTheme();
    }

    public static function allowUserAppearanceSwitch(): bool
    {
        return (bool) SiteSetting::get('allow_user_appearance_switch', true);
    }

    public static function forceGlobalTheme(): bool
    {
        return (bool) SiteSetting::get('force_global_theme', false);
    }

    /** @return string one of off|low|medium|high */
    public static function animationIntensity(): string
    {
        $v = (string) SiteSetting::get('animation_intensity', 'medium');
        // Back-compat with the earlier none/subtle/rich vocabulary.
        $v = match ($v) {
            'none'   => 'off',
            'subtle' => 'low',
            'rich'   => 'high',
            default  => $v,
        };
        return in_array($v, ['off', 'low', 'medium', 'high'], true) ? $v : 'medium';
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    public static function resolveTheme(string $surface, ?User $user = null): string
    {
        if (self::forceGlobalTheme()) {
            return self::defaultTheme($surface);
        }

        if (self::allowUserThemeSwitch()) {
            $pref = self::normalize($user?->theme_preference ?? request()->cookie('zed_theme'));
            if ($pref !== null && in_array($pref, self::enabledThemes(), true)) {
                return $pref;
            }
        }

        return self::defaultTheme($surface);
    }

    /** @return string one of light|dark|system */
    public static function resolveAppearance(?User $user = null): string
    {
        if (self::allowUserAppearanceSwitch()) {
            $pref = $user?->appearance ?? request()->cookie('zed_appearance');
            if (in_array($pref, ['light', 'dark', 'system'], true)) {
                return $pref;
            }
        }
        return (string) SiteSetting::get('default_appearance', 'dark');
    }

    /**
     * Server-side best guess of the html "light" class for the given appearance,
     * combined with the preset's natural appearance. The no-FOUC script refines
     * "system" before paint.
     */
    public static function htmlClassFor(string $themeKey, string $appearance): string
    {
        $isLight = $appearance === 'light'
            || ($appearance === 'system' && (self::presets()[$themeKey]['appearance'] ?? 'dark') === 'light');

        $classes = [];
        if ($isLight) {
            $classes[] = 'zed-light';
        }
        if (self::animationIntensity() === 'off') {
            $classes[] = 'zed-anim-none';
        }
        return implode(' ', $classes);
    }

    /** Animation speed (ms) for the active intensity. */
    public static function animationSpeed(): string
    {
        return match (self::animationIntensity()) {
            'off'  => '0ms',
            'low'  => '160ms',
            'high' => '320ms',
            default => '220ms',
        };
    }

    /** Inline CSS custom properties from shape/density settings. */
    public static function inlineStyle(): string
    {
        $cardRadius   = (string) SiteSetting::get('card_radius', '0.9rem');
        $buttonRadius = (string) SiteSetting::get('button_radius', '0.6rem');
        $iconSize     = (string) SiteSetting::get('icon_size', '1.25rem');
        $sidebarIcon  = (string) SiteSetting::get('sidebar_icon_size', '1.25rem');
        $logoSize     = (string) SiteSetting::get('logo_size', '1.15rem');
        $imageSize    = (string) SiteSetting::get('image_size', '2.5rem');
        $fontScale    = (int) SiteSetting::get('font_scale', 100);

        $style = "--zp-card-radius:{$cardRadius};--zp-button-radius:{$buttonRadius};"
            . "--zp-animation-speed:" . self::animationSpeed() . ";"
            . "--zp-icon-size:{$iconSize};--zp-sidebar-icon-size:{$sidebarIcon};"
            . "--zp-logo-size:{$logoSize};--zp-image-size:{$imageSize};";

        if ($fontScale >= 80 && $fontScale <= 130 && $fontScale !== 100) {
            $style .= '--zp-font-scale:' . round($fontScale / 100, 3) . ';';
            $style .= 'font-size:' . round($fontScale / 100 * 16, 1) . 'px;';
        }

        // Apply the active preset's colour palette (+ brand overrides) to the
        // public site and user dashboard, so changing the preset in
        // «تنظیمات ظاهر» visibly affects them too. The shipped default preset
        // matches the previous default palette, so nothing changes by default.
        $style .= AppearanceManager::cssDeclarations();

        return $style;
    }

    /**
     * No-FOUC inline script: refines "system" appearance before first paint and
     * keeps html[data-theme] / .zed-light in sync.
     */
    public static function noFoucScript(string $appearance): string
    {
        if ($appearance !== 'system') {
            return '';
        }
        return <<<'JS'
(function(){try{var m=window.matchMedia('(prefers-color-scheme: light)');
var el=document.documentElement;
function a(){ if(m.matches){el.classList.add('zed-light');}else{el.classList.remove('zed-light');} }
a(); m.addEventListener&&m.addEventListener('change',a);}catch(e){}})();
JS;
    }
}
