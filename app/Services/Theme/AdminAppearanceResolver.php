<?php

namespace App\Services\Theme;

use App\Support\Theme\CssVariableBuilder;

/**
 * Resolves the /zed-admin (Filament) sizing variables from TWO practical
 * controls — admin_density and admin_sidebar_size — plus the brand options.
 *
 * This replaces the old pile of individual sliders with two reliable presets
 * that map cleanly onto real Filament selectors. All values carry safe defaults
 * and migrate the previous fine-grained settings without data loss.
 */
class AdminAppearanceResolver
{
    /** density => [sidebar_item_height, group_font, table_row, card_pad, form_control] */
    private const DENSITY = [
        'compact'     => [36, 12, 40, 12, 38],
        'normal'      => [40, 13, 48, 16, 42],
        'comfortable' => [46, 14, 56, 20, 46],
    ];

    /** sidebar size => [width, brand_size, menu_font, icon_size] */
    private const SIDEBAR = [
        'small'  => [250, 20, 13, 15],
        'normal' => [280, 24, 14, 16],
        'large'  => [320, 28, 15, 18],
    ];

    /**
     * @return array{
     *   density:string, sidebar_size:string, brand_display:string,
     *   brand_text:string, appearance:string, preset:string,
     *   vars: array<string,string>
     * }
     */
    public static function resolve(): array
    {
        $density = self::density();
        $sidebar = self::sidebarSize();

        [$itemH, $groupFont, $rowH, $cardPad, $ctrlH] = self::DENSITY[$density];
        [$sbWidth, $brandSize, $menuFont, $iconSize]  = self::SIDEBAR[$sidebar];

        // Table cell padding derived from the row height for a real visual change.
        [$cellPy, $cellPx] = match ($density) {
            'compact'     => [8, 10],
            'comfortable' => [14, 16],
            default       => [10, 12],
        };
        $gap = match ($density) {
            'compact'     => 10,
            'comfortable' => 18,
            default       => 14,
        };

        $vars = [
            // Sidebar (from admin_sidebar_size).
            '--zp-admin-sidebar-width'            => self::px($sbWidth),
            '--sidebar-width'                     => self::px($sbWidth),
            '--zp-admin-sidebar-brand-size'       => self::px($brandSize),
            '--zp-admin-sidebar-font-size'        => self::px($menuFont),
            '--zp-admin-sidebar-icon-size'        => self::px($iconSize),
            // Sidebar density bits (from admin_density).
            '--zp-admin-sidebar-item-height'      => self::px($itemH),
            '--zp-admin-sidebar-group-font-size'  => self::px($groupFont),
            '--zp-admin-sidebar-group-label-size' => self::px($groupFont),
            '--zp-admin-sidebar-item-gap'         => self::px(4),
            '--zp-admin-sidebar-chevron-size'     => self::px(12),
            // Tables / cards / forms (from admin_density).
            '--zp-admin-table-row-height'         => self::px($rowH),
            '--zp-admin-table-cell-py'            => self::px($cellPy),
            '--zp-admin-table-cell-px'            => self::px($cellPx),
            '--zp-admin-card-padding'             => self::px($cardPad),
            '--zp-admin-form-control-height'      => self::px($ctrlH),
            '--zp-admin-density-gap'              => self::px($gap),
            // Fixed, sensible chrome sizes (no longer user-tweaked).
            '--zp-admin-icon-size'                => '16px',
            '--zp-admin-action-icon-size'         => '16px',
            '--zp-admin-form-icon-size'           => '16px',
            '--zp-admin-select-caret-size'        => '14px',
            '--zp-admin-logo-size'                => self::px(max(24, min(56, $brandSize + 8))),
            '--zp-admin-font-scale'               => '1',
            '--zp-admin-animation-speed'          => '180ms',
            // Radius pulled from the global tokens (kept in one place).
            '--zp-admin-card-radius'              => 'var(--zp-card-radius, 0.9rem)',
            '--zp-admin-button-radius'            => 'var(--zp-button-radius, 0.6rem)',
        ];

        return [
            'density'       => $density,
            'sidebar_size'  => $sidebar,
            'brand_display' => self::brandDisplay(),
            'brand_text'    => self::brandText(),
            'appearance'    => AppearanceManager::appearanceMode(),
            'preset'        => AppearanceManager::activePreset(),
            'vars'          => $vars,
        ];
    }

    /** `name:value;` declaration body for the admin <style> block. */
    public static function cssDeclarations(): string
    {
        return CssVariableBuilder::declarations(self::resolve()['vars']);
    }

    public static function density(): string
    {
        // Migrate from the old card_density/table_density if admin_density unset.
        $v = (string) ThemeSettingsService::firstOf(['admin_density', 'card_density', 'table_density'], 'normal');
        return array_key_exists($v, self::DENSITY) ? $v : 'normal';
    }

    public static function sidebarSize(): string
    {
        $v = (string) ThemeSettingsService::get('admin_sidebar_size', '');
        if (array_key_exists($v, self::SIDEBAR)) {
            return $v;
        }
        // Migrate from the previous explicit width if present.
        $oldWidth = (int) self::toPx((string) ThemeSettingsService::get('admin_sidebar_width', '280px'));
        return $oldWidth <= 260 ? 'small' : ($oldWidth >= 300 ? 'large' : 'normal');
    }

    public static function brandDisplay(): string
    {
        $v = (string) ThemeSettingsService::get('admin_brand_display', 'text');
        return in_array($v, ['logo', 'text', 'logo_text'], true) ? $v : 'text';
    }

    public static function brandText(): string
    {
        $v = trim((string) ThemeSettingsService::get('admin_brand_text', ''));
        return $v !== '' ? $v : 'ZedProxy Admin';
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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
        return $num;
    }

    private static function px(int|float $v): string
    {
        return ((int) round($v)) . 'px';
    }
}
