<?php

namespace App\Services\Theme;

use App\Support\Theme\CssVariableBuilder;

/**
 * Resolves the generic (public site + user dashboard) appearance variables.
 *
 * These are the shared `--zp-*` shape/size tokens the user side already consumes
 * through {@see ThemeManager::inlineStyle()} in the public/user layouts. This
 * resolver exposes the same values as a structured, testable map (and a ready
 * CSS declaration string) without changing how the user layouts inject them —
 * so the currently-good user styling is never disturbed.
 */
class UserAppearanceResolver
{
    /** @return array<string,string> name => CSS value */
    public static function vars(): array
    {
        $cardRadius   = (string) ThemeSettingsService::get('card_radius', '0.9rem');
        $buttonRadius = (string) ThemeSettingsService::get('button_radius', '0.6rem');
        $iconSize     = (string) ThemeSettingsService::get('icon_size', '1.25rem');
        $sidebarIcon  = (string) ThemeSettingsService::get('sidebar_icon_size', '1.25rem');
        $logoSize     = (string) ThemeSettingsService::get('logo_size', '1.15rem');
        $imageSize    = (string) ThemeSettingsService::get('image_size', '2.5rem');
        $fontScale    = (int) ThemeSettingsService::get('font_scale', 100);

        $vars = [
            '--zp-card-radius'       => $cardRadius,
            '--zp-button-radius'     => $buttonRadius,
            '--zp-animation-speed'   => ThemeManager::animationSpeed(),
            '--zp-icon-size'         => $iconSize,
            '--zp-sidebar-icon-size' => $sidebarIcon,
            '--zp-logo-size'         => $logoSize,
            '--zp-image-size'        => $imageSize,
        ];

        if ($fontScale >= 80 && $fontScale <= 130 && $fontScale !== 100) {
            $vars['--zp-font-scale'] = (string) round($fontScale / 100, 3);
        }

        return $vars;
    }

    /** `name:value;` declaration body for inline injection. */
    public static function cssDeclarations(): string
    {
        return CssVariableBuilder::declarations(self::vars());
    }
}
