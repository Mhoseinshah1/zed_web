<?php

namespace App\Services\Theme;

use App\Support\Theme\CssVariableBuilder;

/**
 * Simple, reliable appearance system that replaces the old Theme Studio.
 *
 * Five practical presets, a light/dark/system mode and optional brand colour
 * overrides. Produces the global `--zp-*` colour variables that are injected on
 * the public site, the user dashboard and the admin panel, so changing the
 * preset visibly affects all three.
 *
 * All values are database-driven (via {@see ThemeSettingsService}) with safe
 * fallbacks that migrate the previous theme settings without data loss.
 */
class AppearanceManager
{
    public const DEFAULT_PRESET = 'default_dark';

    /** Fixed semantic colours shared by every preset. */
    private const SEMANTIC = [
        'success' => '#34d399',
        'warning' => '#fbbf24',
        'danger'  => '#f43f5e',
    ];

    /**
     * The five practical presets, each a full palette.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function presets(): array
    {
        return [
            'default_dark' => [
                'title' => 'پیش‌فرض تیره', 'appearance' => 'dark',
                'bg' => '#0a0e1a', 'bg_soft' => '#0e1322', 'surface' => '#141a2b', 'surface_soft' => '#1c2438',
                'surface_hover' => '#232c44', 'text' => '#e8ebf5', 'muted' => '#9aa3bd', 'border' => '#283047',
                'primary' => '#3b82f6', 'accent' => '#22d3ee',
            ],
            'minimal_light' => [
                'title' => 'روشن مینیمال', 'appearance' => 'light',
                'bg' => '#eef2fb', 'bg_soft' => '#e6ebf7', 'surface' => '#ffffff', 'surface_soft' => '#f3f6fc',
                'surface_hover' => '#e9eef8', 'text' => '#1c2233', 'muted' => '#5f6883', 'border' => '#d8deec',
                'primary' => '#2563eb', 'accent' => '#0ea5e9',
            ],
            'luxury_gold' => [
                'title' => 'طلایی لوکس', 'appearance' => 'dark',
                'bg' => '#0b0a07', 'bg_soft' => '#13110b', 'surface' => '#191510', 'surface_soft' => '#221c13',
                'surface_hover' => '#2a2218', 'text' => '#f1ece0', 'muted' => '#b6ab92', 'border' => '#3a3020',
                'primary' => '#d4af37', 'accent' => '#facc15',
            ],
            'professional_blue' => [
                'title' => 'آبی حرفه‌ای', 'appearance' => 'light',
                'bg' => '#eef3fb', 'bg_soft' => '#e5edf8', 'surface' => '#ffffff', 'surface_soft' => '#f1f6fd',
                'surface_hover' => '#e6eef9', 'text' => '#14233b', 'muted' => '#5b6b86', 'border' => '#d4deee',
                'primary' => '#0ea5e9', 'accent' => '#3b82f6',
            ],
            'graphite_admin' => [
                'title' => 'گرافیت مدیریتی', 'appearance' => 'dark',
                'bg' => '#0d0f12', 'bg_soft' => '#15181d', 'surface' => '#191d24', 'surface_soft' => '#212630',
                'surface_hover' => '#2a313c', 'text' => '#e6e9ef', 'muted' => '#969eae', 'border' => '#313844',
                'primary' => '#64748b', 'accent' => '#38bdf8',
            ],
        ];
    }

    /** @return array<int,string> */
    public static function presetKeys(): array
    {
        return array_keys(self::presets());
    }

    /** Active preset slug (migrates the old admin theme key when unset). */
    public static function activePreset(): string
    {
        $preset = (string) ThemeSettingsService::get('site_theme_preset', '');
        if ($preset !== '' && array_key_exists($preset, self::presets())) {
            return $preset;
        }
        return self::migrateLegacyPreset();
    }

    /** @return array<string,mixed> resolved active palette (with overrides). */
    public static function activePalette(): array
    {
        $preset  = self::presets()[self::activePreset()];
        $primary = self::colorOverride('primary_color', $preset['primary']);
        $accent  = self::colorOverride('accent_color', $preset['accent']);

        return array_merge($preset, ['primary' => $primary, 'accent' => $accent]);
    }

    /** light|dark|system — migrates the old default_appearance key. */
    public static function appearanceMode(): string
    {
        $mode = (string) ThemeSettingsService::firstOf(['appearance_mode', 'default_appearance'], 'dark');
        return in_array($mode, ['light', 'dark', 'system'], true) ? $mode : 'dark';
    }

    /**
     * Global colour custom-properties produced from the active palette.
     *
     * @return array<string,string>
     */
    public static function colorVars(): array
    {
        $p = self::activePalette();

        return [
            '--zp-bg'            => $p['bg'],
            '--zp-bg-soft'       => $p['bg_soft'],
            '--zp-surface'       => $p['surface'],
            '--zp-surface-soft'  => $p['surface_soft'],
            '--zp-surface-hover' => $p['surface_hover'],
            '--zp-text'          => $p['text'],
            '--zp-text-muted'    => $p['muted'],
            '--zp-border'        => $p['border'],
            '--zp-primary'       => $p['primary'],
            '--zp-primary-hover' => $p['primary'],
            '--zp-secondary'     => $p['accent'],
            '--zp-accent'        => $p['accent'],
            '--zp-success'       => self::SEMANTIC['success'],
            '--zp-warning'       => self::SEMANTIC['warning'],
            '--zp-danger'        => self::SEMANTIC['danger'],
            '--zp-gradient'      => "linear-gradient(135deg,{$p['primary']},{$p['accent']})",
        ];
    }

    /** `name:value;` declaration body for the colour variables. */
    public static function cssDeclarations(): string
    {
        return CssVariableBuilder::declarations(self::colorVars());
    }

    public static function allowUserAppearanceSwitch(): bool
    {
        return (bool) ThemeSettingsService::get('allow_user_appearance_switch', true);
    }

    public static function allowUserThemeSwitch(): bool
    {
        return (bool) ThemeSettingsService::get('allow_user_theme_switch', true);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A saved hex colour override, or the preset default when empty/invalid. */
    private static function colorOverride(string $key, string $default): string
    {
        $val = (string) ThemeSettingsService::get($key, '');
        return preg_match('/^#?[0-9a-fA-F]{6}$/', $val) === 1
            ? (str_starts_with($val, '#') ? $val : '#' . $val)
            : $default;
    }

    /** Best-effort map of the previous 15-theme admin slug onto a new preset. */
    private static function migrateLegacyPreset(): string
    {
        $old = (string) ThemeSettingsService::firstOf(['default_theme_admin', 'default_theme_public'], '');
        return match ($old) {
            'zed-minimal-light', 'zed-frost', 'zed-sky-light', 'zed-mint' => 'minimal_light',
            'zed-luxury-gold', 'zed-sunset'                               => 'luxury_gold',
            'zed-graphite', 'zed-titanium'                                => 'graphite_admin',
            'zed-cyber-dark', 'zed-aurora', 'zed-neon'                    => 'professional_blue',
            default                                                       => self::DEFAULT_PRESET,
        };
    }
}
