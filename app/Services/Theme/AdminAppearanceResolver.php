<?php

namespace App\Services\Theme;

use App\Support\Theme\CssVariableBuilder;

/**
 * Single source of truth for the resolved /zed-admin (Filament) appearance.
 *
 * Reads the database-driven Theme Studio settings through {@see
 * ThemeSettingsService}, normalises + CLAMPS every value into a safe range, and
 * returns ready-to-use CSS values plus the categorical state. The output drives
 * the declarative <style> block injected on every admin page, so saved settings
 * apply with NO dependency on JavaScript.
 *
 * Admin/user separation: each metric first reads its dedicated `admin_*` key;
 * if that has never been set it falls back to the legacy shared key (e.g.
 * `icon_size`) so existing installs keep their look, and finally to a built-in
 * default. The user side keeps reading the shared keys, so changing an admin
 * control never moves the user panel.
 */
class AdminAppearanceResolver
{
    /**
     * @return array{
     *   theme:string, appearance:string, animation_intensity:string,
     *   table_density:string, card_density:string,
     *   vars: array<string,string>, normalized: array<string,string>,
     *   raw: array<string,mixed>
     * }
     */
    public static function resolve(): array
    {
        // ── icon family (admin_* px override → legacy rem*factor → default) ──
        $iconPx   = self::metric('admin_icon_size', 'icon_size', '1.25rem', 0.85, 12, 24);
        $sidePx   = self::metric('admin_sidebar_icon_size', 'sidebar_icon_size', '1.25rem', 0.9, 14, 24);
        $actionPx = self::metric('admin_action_icon_size', 'icon_size', '1.25rem', 0.85, 12, 22);
        $formPx   = self::metric('admin_form_icon_size', 'icon_size', '1.25rem', 0.85, 12, 22);
        $caretPx  = self::clamp(self::px2(self::firstPx('admin_select_caret_size'), $iconPx - 2), 10, 18);
        $logoPx   = self::logoMetric();

        // ── shape ────────────────────────────────────────────────────────────
        $cardR    = self::metric('admin_card_radius', 'card_radius', '0.9rem', 1.0, 8, 28);
        $btnR     = self::metric('admin_button_radius', 'button_radius', '0.6rem', 1.0, 6, 24);
        $fontScale = self::fontScale();

        // ── density (admin_* category override → legacy → default) ───────────
        $cardDensity  = self::density('admin_card_density', 'card_density');
        $tableDensity = self::density('admin_table_density', 'table_density');

        [$cardPad, $ctrlH, $gap] = match ($cardDensity) {
            'compact'     => [12, 38, 10],
            'comfortable' => [20, 46, 18],
            default       => [16, 42, 14],
        };
        [$rowH, $cellPy, $cellPx] = match ($tableDensity) {
            'compact'     => [40, 8, 10],
            'comfortable' => [56, 14, 16],
            default       => [48, 10, 12],
        };
        // Explicit admin_* overrides win over the density preset when present.
        $ctrlH = self::override('admin_form_control_height', $ctrlH, 30, 64);
        $cardPad = self::override('admin_card_padding', $cardPad, 6, 40);
        $gap   = self::override('admin_density_gap', $gap, 4, 32);
        $rowH  = self::override('admin_table_row_height', $rowH, 32, 72);

        // ── animation ────────────────────────────────────────────────────────
        $anim      = self::animationIntensity();
        $animSpeed = (string) ThemeSettingsService::firstOf(['admin_animation_speed'], match ($anim) {
            'off'  => '0ms',
            'low'  => '160ms',
            'high' => '320ms',
            default => '220ms',
        });
        $animSpeed = self::sanitizeMs($animSpeed);

        // ── sidebar controls (admin-only, clamped) ──────────────────────────
        $sbBrand  = self::override('admin_sidebar_brand_size', 24, 18, 32);
        $sbFont   = self::override('admin_sidebar_font_size', 14, 12, 16);
        $sbGroup  = self::override('admin_sidebar_group_label_size', 13, 11, 15);
        $sbChev   = self::override('admin_sidebar_chevron_size', 12, 10, 16);
        $sbItemH  = self::override('admin_sidebar_item_height', 40, 34, 48);
        $sbItemG  = self::override('admin_sidebar_item_gap', 4, 2, 10);
        $sbWidth  = self::override('admin_sidebar_width', 280, 240, 340);

        $theme      = self::safeTheme();
        $appearance = (string) ThemeSettingsService::get('default_appearance', 'dark');

        // Normalised admin_* map (Task 5 shape) — handy for diagnostics/tests.
        $normalized = [
            'admin_icon_size'              => self::px($iconPx),
            'admin_sidebar_icon_size'      => self::px($sidePx),
            'admin_action_icon_size'       => self::px($actionPx),
            'admin_form_icon_size'         => self::px($formPx),
            'admin_select_caret_size'      => self::px($caretPx),
            'admin_logo_size'              => self::px($logoPx),
            'admin_font_scale'             => self::num($fontScale),
            'admin_card_radius'            => self::px($cardR),
            'admin_button_radius'          => self::px($btnR),
            'admin_card_padding'           => self::px($cardPad),
            'admin_table_row_height'       => self::px($rowH),
            'admin_form_control_height'    => self::px($ctrlH),
            'admin_density_gap'            => self::px($gap),
            'admin_animation_speed'        => $animSpeed,
            'admin_sidebar_brand_size'       => self::px($sbBrand),
            'admin_sidebar_font_size'        => self::px($sbFont),
            'admin_sidebar_group_label_size' => self::px($sbGroup),
            'admin_sidebar_chevron_size'     => self::px($sbChev),
            'admin_sidebar_item_height'      => self::px($sbItemH),
            'admin_sidebar_item_gap'         => self::px($sbItemG),
            'admin_sidebar_width'            => self::px($sbWidth),
        ];

        // CSS custom-property map consumed by the Filament selector layer.
        $vars = [
            '--zp-admin-icon-size'          => self::px($iconPx),
            '--zp-admin-sidebar-icon-size'  => self::px($sidePx),
            '--zp-admin-action-icon-size'   => self::px($actionPx),
            '--zp-admin-form-icon-size'     => self::px($formPx),
            '--zp-admin-select-caret-size'  => self::px($caretPx),
            '--zp-admin-logo-size'          => self::px($logoPx),
            '--zp-admin-font-scale'         => self::num($fontScale),
            '--zp-admin-card-radius'        => self::px($cardR),
            '--zp-admin-button-radius'      => self::px($btnR),
            '--zp-admin-card-padding'       => self::px($cardPad),
            '--zp-admin-table-row-height'   => self::px($rowH),
            '--zp-admin-table-cell-py'      => self::px($cellPy),
            '--zp-admin-table-cell-px'      => self::px($cellPx),
            '--zp-admin-form-control-height' => self::px($ctrlH),
            '--zp-admin-density-gap'        => self::px($gap),
            '--zp-admin-animation-speed'    => $animSpeed,
            '--zp-admin-sidebar-brand-size'       => self::px($sbBrand),
            '--zp-admin-sidebar-font-size'        => self::px($sbFont),
            '--zp-admin-sidebar-group-label-size' => self::px($sbGroup),
            '--zp-admin-sidebar-chevron-size'     => self::px($sbChev),
            '--zp-admin-sidebar-item-height'      => self::px($sbItemH),
            '--zp-admin-sidebar-item-gap'         => self::px($sbItemG),
            '--zp-admin-sidebar-width'            => self::px($sbWidth),
            '--sidebar-width'                     => self::px($sbWidth),
            // Admin-scoped base aliases so existing .fi-* rules reading the
            // canonical tokens follow the resolved admin radius/motion too.
            '--zp-card-radius'              => self::px($cardR),
            '--zp-button-radius'           => self::px($btnR),
            '--zp-animation-speed'         => $animSpeed,
        ];

        return [
            'theme'               => $theme,
            'appearance'          => $appearance,
            'animation_intensity' => $anim,
            'table_density'       => $tableDensity,
            'card_density'        => $cardDensity,
            'vars'                => $vars,
            'normalized'          => $normalized,
            'raw'                 => [
                'icon_size'         => (string) ThemeSettingsService::firstOf(['admin_icon_size', 'icon_size'], '1.25rem'),
                'sidebar_icon_size' => (string) ThemeSettingsService::firstOf(['admin_sidebar_icon_size', 'sidebar_icon_size'], '1.25rem'),
                'logo_size'         => (string) ThemeSettingsService::firstOf(['admin_logo_size', 'logo_size'], '1.15rem'),
                'card_radius'       => (string) ThemeSettingsService::firstOf(['admin_card_radius', 'card_radius'], '0.9rem'),
                'button_radius'     => (string) ThemeSettingsService::firstOf(['admin_button_radius', 'button_radius'], '0.6rem'),
                'font_scale'        => (int) ThemeSettingsService::firstOf(['admin_font_scale', 'font_scale'], 100),
                'table_density'     => $tableDensity,
                'card_density'      => $cardDensity,
                'animation_intensity' => $anim,
                'admin_sidebar_brand_size'       => (string) ThemeSettingsService::get('admin_sidebar_brand_size', '24px'),
                'admin_sidebar_font_size'        => (string) ThemeSettingsService::get('admin_sidebar_font_size', '14px'),
                'admin_sidebar_group_label_size' => (string) ThemeSettingsService::get('admin_sidebar_group_label_size', '13px'),
                'admin_sidebar_chevron_size'     => (string) ThemeSettingsService::get('admin_sidebar_chevron_size', '12px'),
                'admin_sidebar_item_height'      => (string) ThemeSettingsService::get('admin_sidebar_item_height', '40px'),
                'admin_sidebar_item_gap'         => (string) ThemeSettingsService::get('admin_sidebar_item_gap', '4px'),
                'admin_sidebar_width'            => (string) ThemeSettingsService::get('admin_sidebar_width', '280px'),
            ],
        ];
    }

    /** `name:value;` declaration body for the admin <style> block. */
    public static function cssDeclarations(): string
    {
        return CssVariableBuilder::declarations(self::resolve()['vars']);
    }

    // ── resolution helpers ───────────────────────────────────────────────────

    /**
     * Resolve a px metric: dedicated admin_* px override → legacy rem key
     * scaled by $factor → default, then clamp.
     */
    private static function metric(string $adminKey, string $legacyKey, string $legacyDefault, float $factor, float $min, float $max): float
    {
        $admin = ThemeSettingsService::get($adminKey, null);
        if (is_string($admin) && $admin !== '') {
            return self::clamp(self::toPx($admin), $min, $max);
        }
        $legacy = (string) ThemeSettingsService::get($legacyKey, $legacyDefault);
        return self::clamp(self::toPx($legacy) * $factor, $min, $max);
    }

    /** Admin logo: admin_logo_size px override → derive from legacy logo_size. */
    private static function logoMetric(): float
    {
        $admin = ThemeSettingsService::get('admin_logo_size', null);
        if (is_string($admin) && $admin !== '') {
            return self::clamp(self::toPx($admin), 24, 56);
        }
        $legacy = (string) ThemeSettingsService::get('logo_size', '1.15rem');
        return self::clamp(self::toPx($legacy) / 18.4 * 32, 24, 56);
    }

    /** Simple admin_* px override with default + clamp (no legacy fallback). */
    private static function override(string $key, float $default, float $min, float $max): float
    {
        $v = ThemeSettingsService::get($key, null);
        $px = (is_string($v) && $v !== '') ? self::toPx($v) : $default;
        return self::clamp($px, $min, $max);
    }

    private static function fontScale(): float
    {
        $admin = ThemeSettingsService::get('admin_font_scale', null);
        if (is_string($admin) && $admin !== '') {
            $n = (float) $admin;
            $n = $n > 4 ? $n / 100 : $n; // accept "110" or "1.1"
            return self::clampF($n, 0.9, 1.15);
        }
        return self::clampF(((int) ThemeSettingsService::get('font_scale', 100)) / 100, 0.9, 1.15);
    }

    private static function density(string $adminKey, string $legacyKey): string
    {
        $val = (string) ThemeSettingsService::firstOf([$adminKey, $legacyKey], 'comfortable');
        return in_array($val, ['compact', 'normal', 'comfortable'], true) ? $val : 'comfortable';
    }

    private static function animationIntensity(): string
    {
        $admin = ThemeSettingsService::get('admin_animation_intensity', null);
        if (is_string($admin) && in_array($admin, ['off', 'low', 'medium', 'high'], true)) {
            return $admin;
        }
        try {
            return ThemeManager::animationIntensity();
        } catch (\Throwable $e) {
            return 'medium';
        }
    }

    private static function safeTheme(): string
    {
        try {
            return ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN);
        } catch (\Throwable $e) {
            return ThemeManager::DEFAULT_THEME;
        }
    }

    /** First admin px value or null. */
    private static function firstPx(string $key): ?float
    {
        $v = ThemeSettingsService::get($key, null);
        return (is_string($v) && $v !== '') ? self::toPx($v) : null;
    }

    private static function px2(?float $value, float $fallback): float
    {
        return $value ?? $fallback;
    }

    private static function sanitizeMs(string $v): string
    {
        if (! preg_match('/-?\d*\.?\d+/', $v, $m)) {
            return '220ms';
        }
        $n = max(0, min(1000, (float) $m[0]));
        return (floor($n) === $n ? (string) (int) $n : (string) $n) . 'ms';
    }

    // ── primitive helpers ────────────────────────────────────────────────────

    /** Parse a CSS length ("1.25rem" | "20px" | "1.5") to pixels (1rem = 16px). */
    public static function toPx(string $value): float
    {
        $value = trim($value);
        if (! preg_match('/-?\d*\.?\d+/', $value, $m)) {
            return 16.0;
        }
        $num = (float) $m[0];
        if (str_contains($value, 'rem') || str_contains($value, 'em')) {
            return $num * 16.0;
        }
        if (str_contains($value, 'px')) {
            return $num;
        }
        return $num <= 4 ? $num * 16.0 : $num;
    }

    private static function clamp(float $px, float $min, float $max): float
    {
        return max($min, min($max, $px));
    }

    private static function clampF(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private static function px(float $v): string
    {
        $r = round($v, 1);
        return (floor($r) === $r ? (string) (int) $r : (string) $r) . 'px';
    }

    private static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
