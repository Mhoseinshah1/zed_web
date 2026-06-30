<?php

namespace App\Services\Theme;

use App\Models\SiteSetting;

/**
 * Single source of truth for the resolved /zed-admin (Filament) appearance.
 *
 * It reads the database-driven Theme Studio settings, normalises and CLAMPS
 * every value into a safe range, and returns ready-to-use CSS values (px / rem
 * / ms) together with the categorical state (theme / appearance / density /
 * animation). The output drives a declarative <style> block injected into every
 * admin page — so the saved settings apply with NO dependency on JavaScript.
 *
 * Deliberately separate from the user-side --zp-* tokens: the admin panel is
 * sized independently and can never be blown up by an extreme value.
 */
class AdminAppearanceResolver
{
    /**
     * Resolve the full admin appearance from the database.
     *
     * @return array{
     *   theme:string, appearance:string, animation_intensity:string,
     *   table_density:string, card_density:string,
     *   vars: array<string,string>, raw: array<string,mixed>
     * }
     */
    public static function resolve(): array
    {
        $get = static function (string $key, mixed $default): mixed {
            try {
                return SiteSetting::get($key, $default);
            } catch (\Throwable $e) {
                return $default;
            }
        };

        // ── raw saved values (for diagnostics) ──────────────────────────────
        $rawIcon    = (string) $get('icon_size', '1.25rem');
        $rawSidebar = (string) $get('sidebar_icon_size', '1.25rem');
        $rawLogo    = (string) $get('logo_size', '1.15rem');
        $rawCardR   = (string) $get('card_radius', '0.9rem');
        $rawBtnR    = (string) $get('button_radius', '0.6rem');
        $rawFont    = (int) $get('font_scale', 100);

        // Admin sidebar controls (new, admin-only). Stored as px strings.
        $rawSbBrand  = (string) $get('admin_sidebar_brand_size', '24px');
        $rawSbFont   = (string) $get('admin_sidebar_font_size', '14px');
        $rawSbGroup  = (string) $get('admin_sidebar_group_label_size', '13px');
        $rawSbChev   = (string) $get('admin_sidebar_chevron_size', '12px');
        $rawSbItemH  = (string) $get('admin_sidebar_item_height', '40px');
        $rawSbItemG  = (string) $get('admin_sidebar_item_gap', '4px');
        $rawSbWidth  = (string) $get('admin_sidebar_width', '280px');

        $tableDensity = self::oneOf((string) $get('table_density', 'comfortable'), ['compact', 'normal', 'comfortable'], 'comfortable');
        $cardDensity  = self::oneOf((string) $get('card_density', 'comfortable'), ['compact', 'normal', 'comfortable'], 'comfortable');

        try {
            $anim       = ThemeManager::animationIntensity();
            $theme      = ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN);
        } catch (\Throwable $e) {
            $anim  = 'medium';
            $theme = ThemeManager::DEFAULT_THEME;
        }
        $appearance = (string) $get('default_appearance', 'dark');

        // ── numeric, clamped pixel values ───────────────────────────────────
        $iconPx   = self::clamp(self::toPx($rawIcon) * 0.85, 12, 24);
        $sidePx   = self::clamp(self::toPx($rawSidebar) * 0.9, 14, 26);
        $actionPx = self::clamp($iconPx, 12, 22);
        $formPx   = self::clamp($iconPx, 12, 22);
        $caretPx  = self::clamp($iconPx - 2, 10, 18);
        $logoPx   = self::clamp(self::toPx($rawLogo) / 18.4 * 32, 24, 56);
        $fontScale = self::clampFloat($rawFont / 100, 0.9, 1.15);
        $cardR    = self::clamp(self::toPx($rawCardR), 8, 28);
        $btnR     = self::clamp(self::toPx($rawBtnR), 6, 24);

        // ── sidebar (clamped) ───────────────────────────────────────────────
        $sbBrand  = self::clamp(self::toPx($rawSbBrand), 18, 32);
        $sbFont   = self::clamp(self::toPx($rawSbFont), 12, 16);
        $sbGroup  = self::clamp(self::toPx($rawSbGroup), 11, 15);
        $sbChev   = self::clamp(self::toPx($rawSbChev), 10, 16);
        $sbItemH  = self::clamp(self::toPx($rawSbItemH), 34, 48);
        $sbItemG  = self::clamp(self::toPx($rawSbItemG), 2, 10);
        $sbWidth  = self::clamp(self::toPx($rawSbWidth), 240, 340);

        // ── density presets (px) ────────────────────────────────────────────
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

        $animSpeed = match ($anim) {
            'off'  => '0ms',
            'low'  => '160ms',
            'high' => '320ms',
            default => '220ms',
        };

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
            // Admin sidebar controls.
            '--zp-admin-sidebar-brand-size'       => self::px($sbBrand),
            '--zp-admin-sidebar-font-size'        => self::px($sbFont),
            '--zp-admin-sidebar-group-label-size' => self::px($sbGroup),
            '--zp-admin-sidebar-chevron-size'     => self::px($sbChev),
            '--zp-admin-sidebar-item-height'      => self::px($sbItemH),
            '--zp-admin-sidebar-item-gap'         => self::px($sbItemG),
            '--zp-admin-sidebar-width'            => self::px($sbWidth),
            // Filament's own layout width variable — set so the main content
            // offset follows the chosen sidebar width.
            '--sidebar-width'                     => self::px($sbWidth),
            // Admin-scoped base aliases so existing .fi-* rules that read the
            // canonical tokens follow the resolved admin radius/motion too.
            // (This style block is injected ONLY into the admin head.)
            '--zp-card-radius'              => self::px($cardR),
            '--zp-button-radius'            => self::px($btnR),
            '--zp-animation-speed'          => $animSpeed,
        ];

        return [
            'theme'               => $theme,
            'appearance'          => $appearance,
            'animation_intensity' => $anim,
            'table_density'       => $tableDensity,
            'card_density'        => $cardDensity,
            'vars'                => $vars,
            'raw'                 => [
                'icon_size'         => $rawIcon,
                'sidebar_icon_size' => $rawSidebar,
                'logo_size'         => $rawLogo,
                'card_radius'       => $rawCardR,
                'button_radius'     => $rawBtnR,
                'font_scale'        => $rawFont,
                'table_density'     => $tableDensity,
                'card_density'      => $cardDensity,
                'animation_intensity' => $anim,
                'admin_sidebar_brand_size'       => $rawSbBrand,
                'admin_sidebar_font_size'        => $rawSbFont,
                'admin_sidebar_group_label_size' => $rawSbGroup,
                'admin_sidebar_chevron_size'     => $rawSbChev,
                'admin_sidebar_item_height'      => $rawSbItemH,
                'admin_sidebar_item_gap'         => $rawSbItemG,
                'admin_sidebar_width'            => $rawSbWidth,
            ],
        ];
    }

    /** Build the `name:value;name:value;` declaration body for a CSS block. */
    public static function cssDeclarations(): string
    {
        $out = '';
        foreach (self::resolve()['vars'] as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }
        return $out;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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
        // Bare number: treat small values as rem (e.g. "1.5"), large as px.
        return $num <= 4 ? $num * 16.0 : $num;
    }

    private static function clamp(float $px, float $min, float $max): float
    {
        return max($min, min($max, $px));
    }

    private static function clampFloat(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private static function oneOf(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** Round to 1 decimal and append px without a trailing ".0". */
    private static function px(float $v): string
    {
        $r = round($v, 1);
        return (floor($r) === $r ? (string) (int) $r : (string) $r) . 'px';
    }

    /** Unitless number, trimmed (e.g. 1.15 → "1.15", 1.0 → "1"). */
    private static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
