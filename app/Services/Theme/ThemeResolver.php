<?php

namespace App\Services\Theme;

use App\Models\User;

/**
 * Resolves the effective theme preset + appearance mode for each surface
 * (public site, user dashboard, admin panel). A thin, explicit facade over the
 * existing resolution logic in {@see ThemeManager} so callers have a clear,
 * named entry-point per surface without duplicating the rules.
 */
class ThemeResolver
{
    public static function publicTheme(?User $user = null): string
    {
        return ThemeManager::resolveTheme(ThemeManager::SURFACE_PUBLIC, $user);
    }

    public static function userTheme(?User $user = null): string
    {
        return ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, $user);
    }

    public static function adminTheme(): string
    {
        return ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN);
    }

    /** @return string one of light|dark|system */
    public static function appearance(?User $user = null): string
    {
        return ThemeManager::resolveAppearance($user);
    }

    public static function defaultAppearance(): string
    {
        return (string) ThemeSettingsService::get('default_appearance', 'dark');
    }
}
