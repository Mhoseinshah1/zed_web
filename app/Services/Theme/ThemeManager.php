<?php

namespace App\Services\Theme;

use App\Models\SiteSetting;
use App\Models\User;

/**
 * Resolves the active theme preset, appearance (light/dark/system) and shape
 * tokens from database settings + per-user preference. All values are stored in
 * the database; nothing requires .env changes.
 */
class ThemeManager
{
    public const SURFACE_PUBLIC = 'public';
    public const SURFACE_USER   = 'user';
    public const SURFACE_ADMIN  = 'admin';

    /**
     * Catalog of presets: key => [name_fa, dots(primary,accent,secondary), appearance].
     *
     * @return array<string, array{name:string, dots:array<int,string>, appearance:string}>
     */
    public static function presets(): array
    {
        return [
            'cyber-dark'    => ['name' => 'Zed Cyber Dark',    'dots' => ['#6366f1', '#22d3ee', '#a855f7'], 'appearance' => 'dark'],
            'luxury-gold'   => ['name' => 'Zed Luxury Gold',   'dots' => ['#d4af37', '#facc15', '#b8860b'], 'appearance' => 'dark'],
            'ocean'         => ['name' => 'Zed Ocean',         'dots' => ['#3b82f6', '#22d3ee', '#0ea5e9'], 'appearance' => 'dark'],
            'emerald'       => ['name' => 'Zed Emerald',       'dots' => ['#10b981', '#2dd4bf', '#059669'], 'appearance' => 'dark'],
            'graphite'      => ['name' => 'Zed Graphite',      'dots' => ['#64748b', '#38bdf8', '#475569'], 'appearance' => 'dark'],
            'aurora'        => ['name' => 'Zed Aurora',        'dots' => ['#a855f7', '#60a5fa', '#ec4899'], 'appearance' => 'dark'],
            'minimal-light' => ['name' => 'Zed Minimal Light', 'dots' => ['#2563eb', '#0ea5e9', '#6366f1'], 'appearance' => 'light'],
            'frost'         => ['name' => 'Zed Frost',         'dots' => ['#38bdf8', '#22d3ee', '#818cf8'], 'appearance' => 'light'],
            'matrix'        => ['name' => 'Zed Matrix',        'dots' => ['#22c55e', '#a3e635', '#16a34a'], 'appearance' => 'dark'],
            'royal'         => ['name' => 'Zed Royal',         'dots' => ['#7c3aed', '#cbd5e1', '#4f46e5'], 'appearance' => 'dark'],
            'crimson'       => ['name' => 'Zed Crimson',       'dots' => ['#ef4444', '#fb923c', '#b91c1c'], 'appearance' => 'dark'],
            'sky-light'     => ['name' => 'Zed Sky Light',     'dots' => ['#0ea5e9', '#06b6d4', '#3b82f6'], 'appearance' => 'light'],
            'neon'          => ['name' => 'Zed Neon',          'dots' => ['#22d3ee', '#a78bfa', '#d946ef'], 'appearance' => 'dark'],
            'titanium'      => ['name' => 'Zed Titanium',      'dots' => ['#60a5fa', '#38bdf8', '#64748b'], 'appearance' => 'dark'],
            'sunset'        => ['name' => 'Zed Sunset',        'dots' => ['#f97316', '#a855f7', '#db2777'], 'appearance' => 'dark'],
        ];
    }

    public static function presetKeys(): array
    {
        return array_keys(self::presets());
    }

    public static function isValidPreset(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::presets());
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public static function defaultTheme(string $surface): string
    {
        $key = match ($surface) {
            self::SURFACE_USER  => (string) SiteSetting::get('default_theme_user', 'cyber-dark'),
            self::SURFACE_ADMIN => (string) SiteSetting::get('default_theme_admin', 'cyber-dark'),
            default             => (string) SiteSetting::get('default_theme_public', 'cyber-dark'),
        };
        return self::isValidPreset($key) ? $key : 'cyber-dark';
    }

    /** @return array<int,string> enabled preset keys */
    public static function enabledThemes(): array
    {
        $raw = SiteSetting::get('enabled_themes', null);
        if (blank($raw)) {
            return self::presetKeys();
        }
        $keys = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($k) => self::isValidPreset($k)));
        return $keys ?: self::presetKeys();
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

    public static function animationIntensity(): string
    {
        $v = (string) SiteSetting::get('animation_intensity', 'subtle');
        return in_array($v, ['none', 'subtle', 'rich'], true) ? $v : 'subtle';
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    public static function resolveTheme(string $surface, ?User $user = null): string
    {
        if (self::forceGlobalTheme()) {
            return self::defaultTheme($surface);
        }

        if (self::allowUserThemeSwitch()) {
            $pref = $user?->theme_preference ?? request()->cookie('zed_theme');
            if (self::isValidPreset($pref) && in_array($pref, self::enabledThemes(), true)) {
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
        if (self::animationIntensity() === 'none') {
            $classes[] = 'zed-anim-none';
        }
        return implode(' ', $classes);
    }

    /** Inline CSS custom properties from shape/density settings. */
    public static function inlineStyle(): string
    {
        $cardRadius   = (string) SiteSetting::get('card_radius', '0.85rem');
        $buttonRadius = (string) SiteSetting::get('button_radius', '0.6rem');
        $anim         = self::animationIntensity() === 'rich' ? '320ms' : '200ms';
        $fontScale    = (string) SiteSetting::get('font_scale', '100');

        $style = "--zed-radius-card:{$cardRadius};--zed-radius-button:{$buttonRadius};--zed-anim:{$anim};";
        if (is_numeric($fontScale) && (int) $fontScale !== 100) {
            $style .= 'font-size:' . (round((int) $fontScale / 100 * 16, 1)) . 'px;';
        }
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
