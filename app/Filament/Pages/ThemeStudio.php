<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Theme\AdminAppearanceResolver;
use App\Services\Theme\ThemeManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Premium visual Theme Studio — a full design control center for the whole
 * platform (public site, user dashboard and the Filament admin panel).
 *
 * All interactivity lives in the Blade view (Alpine), state is persisted via
 * the {@see persist()} Livewire action into the shared SiteSetting store.
 */
class ThemeStudio extends Page
{
    protected static string $view = 'filament.pages.theme-studio';

    protected static ?string $navigationIcon  = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = 'ظاهر سایت';
    protected static ?string $navigationLabel = 'استودیو تم';
    protected static ?string $title           = 'استودیو تم ZedProxy';
    protected static ?string $slug            = 'theme-studio';
    protected static ?int    $navigationSort  = 10;

    /** Initial state handed to the Alpine front-end. */
    public function getViewData(): array
    {
        return [
            'presets'      => ThemeManager::presets(),
            'groups'       => ThemeManager::groups(),
            'groupLabels'  => ThemeManager::groupLabels(),
            'state'        => $this->currentState(),
            'adminResolved' => AdminAppearanceResolver::resolve(),
        ];
    }

    /**
     * Diagnostics action — re-read settings, regenerate the resolved admin
     * appearance and reload the page so the freshly-injected variables take
     * effect. Bound to the «بررسی اعمال تنظیمات» button in the diagnostics card.
     */
    public function recheckAppearance(): void
    {
        // Re-resolve from the database (no stale cache: settings are read live).
        AdminAppearanceResolver::resolve();

        Notification::make()->title('تنظیمات ظاهر دوباره بارگذاری شد.')->success()->send();
        $this->redirect(static::getUrl());
    }

    /** @return array<string,mixed> */
    protected function currentState(): array
    {
        return [
            'activeTheme'                 => ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN),
            'default_theme_public'        => ThemeManager::defaultTheme(ThemeManager::SURFACE_PUBLIC),
            'default_theme_user'          => ThemeManager::defaultTheme(ThemeManager::SURFACE_USER),
            'default_theme_admin'         => ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN),
            'appearance'                  => (string) SiteSetting::get('default_appearance', 'dark'),
            'enabled_themes'              => ThemeManager::enabledThemes(),
            'allow_user_theme_switch'     => (bool) SiteSetting::get('allow_user_theme_switch', true),
            'allow_user_appearance_switch' => (bool) SiteSetting::get('allow_user_appearance_switch', true),
            'force_global_theme'          => (bool) SiteSetting::get('force_global_theme', false),
            'animation_intensity'         => ThemeManager::animationIntensity(),
            'card_radius'                 => (string) SiteSetting::get('card_radius', '0.9rem'),
            'button_radius'               => (string) SiteSetting::get('button_radius', '0.6rem'),
            'icon_size'                   => (string) SiteSetting::get('icon_size', '1.25rem'),
            'sidebar_icon_size'           => (string) SiteSetting::get('sidebar_icon_size', '1.25rem'),
            'logo_size'                   => (string) SiteSetting::get('logo_size', '1.15rem'),
            'image_size'                  => (string) SiteSetting::get('image_size', '2.5rem'),
            'font_scale'                  => (int) SiteSetting::get('font_scale', 100),
            'table_density'               => (string) SiteSetting::get('table_density', 'comfortable'),
            'card_density'                => (string) SiteSetting::get('card_density', 'comfortable'),
            // Admin sidebar controls.
            'admin_sidebar_brand_size'       => (string) SiteSetting::get('admin_sidebar_brand_size', '24px'),
            'admin_sidebar_font_size'        => (string) SiteSetting::get('admin_sidebar_font_size', '14px'),
            'admin_sidebar_group_label_size' => (string) SiteSetting::get('admin_sidebar_group_label_size', '13px'),
            'admin_sidebar_chevron_size'     => (string) SiteSetting::get('admin_sidebar_chevron_size', '12px'),
            'admin_sidebar_item_height'      => (string) SiteSetting::get('admin_sidebar_item_height', '40px'),
            'admin_sidebar_item_gap'         => (string) SiteSetting::get('admin_sidebar_item_gap', '4px'),
            'admin_sidebar_width'            => (string) SiteSetting::get('admin_sidebar_width', '280px'),
        ];
    }

    /** Allowed option values per admin sidebar key (guards bad input). */
    protected const SIDEBAR_OPTIONS = [
        'admin_sidebar_brand_size'       => ['18px', '20px', '22px', '24px', '28px', '32px'],
        'admin_sidebar_font_size'        => ['12px', '13px', '14px', '15px', '16px'],
        'admin_sidebar_group_label_size' => ['11px', '12px', '13px', '14px', '15px'],
        'admin_sidebar_chevron_size'     => ['10px', '12px', '14px', '16px'],
        'admin_sidebar_item_height'      => ['34px', '38px', '40px', '44px', '48px'],
        'admin_sidebar_item_gap'         => ['2px', '4px', '6px', '8px', '10px'],
        'admin_sidebar_width'            => ['240px', '260px', '280px', '300px', '320px', '340px'],
    ];

    /**
     * Persist the whole studio state. Called from the Blade "Save" button via
     * $wire.persist({...}).
     *
     * @param  array<string,mixed>  $state
     */
    public function persist(array $state): void
    {
        $keys      = ThemeManager::presetKeys();
        $valid     = fn ($k, $default) => in_array(ThemeManager::normalize($k), $keys, true) ? ThemeManager::normalize($k) : $default;
        $default   = ThemeManager::DEFAULT_THEME;

        SiteSetting::set('default_theme_public', $valid($state['default_theme_public'] ?? null, $default));
        SiteSetting::set('default_theme_user', $valid($state['default_theme_user'] ?? null, $default));
        SiteSetting::set('default_theme_admin', $valid($state['default_theme_admin'] ?? ($state['activeTheme'] ?? null), $default));

        SiteSetting::set('default_appearance', in_array($state['appearance'] ?? null, ['light', 'dark', 'system'], true) ? $state['appearance'] : 'dark');

        $enabled = [];
        foreach ((array) ($state['enabled_themes'] ?? []) as $k) {
            $norm = ThemeManager::normalize($k);
            if ($norm !== null) {
                $enabled[] = $norm;
            }
        }
        $enabled = array_values(array_unique($enabled));
        // Never lock everyone out, and always keep the required defaults enabled.
        foreach ([$state['default_theme_admin'] ?? null, $state['default_theme_user'] ?? null, $state['default_theme_public'] ?? null] as $req) {
            $req = ThemeManager::normalize($req);
            if ($req && ! in_array($req, $enabled, true)) {
                $enabled[] = $req;
            }
        }
        if (empty($enabled)) {
            $enabled = $keys;
        }
        SiteSetting::set('enabled_themes', implode(',', $enabled));

        SiteSetting::set('allow_user_theme_switch', ! empty($state['allow_user_theme_switch']) ? 'true' : 'false');
        SiteSetting::set('allow_user_appearance_switch', ! empty($state['allow_user_appearance_switch']) ? 'true' : 'false');
        SiteSetting::set('force_global_theme', ! empty($state['force_global_theme']) ? 'true' : 'false');

        SiteSetting::set('animation_intensity', in_array($state['animation_intensity'] ?? null, ['off', 'low', 'medium', 'high'], true) ? $state['animation_intensity'] : 'medium');
        SiteSetting::set('font_scale', (int) max(80, min(130, (int) ($state['font_scale'] ?? 100))));
        SiteSetting::set('card_radius', (string) ($state['card_radius'] ?? '0.9rem'));
        SiteSetting::set('button_radius', (string) ($state['button_radius'] ?? '0.6rem'));
        SiteSetting::set('icon_size', (string) ($state['icon_size'] ?? '1.25rem'));
        SiteSetting::set('sidebar_icon_size', (string) ($state['sidebar_icon_size'] ?? '1.25rem'));
        SiteSetting::set('logo_size', (string) ($state['logo_size'] ?? '1.15rem'));
        SiteSetting::set('image_size', (string) ($state['image_size'] ?? '2.5rem'));
        SiteSetting::set('table_density', in_array($state['table_density'] ?? null, ['compact', 'normal', 'comfortable'], true) ? $state['table_density'] : 'comfortable');
        SiteSetting::set('card_density', in_array($state['card_density'] ?? null, ['compact', 'normal', 'comfortable'], true) ? $state['card_density'] : 'comfortable');

        // Admin sidebar controls — only persist values from the allowed option
        // set; AdminAppearanceResolver clamps anyway as a final safety net.
        foreach (self::SIDEBAR_OPTIONS as $key => $allowed) {
            $val = $state[$key] ?? null;
            if (is_string($val) && in_array($val, $allowed, true)) {
                SiteSetting::set($key, $val);
            }
        }

        Notification::make()->title('تغییرات ظاهر با موفقیت ذخیره و اعمال شد.')->success()->send();
    }

    /** Reset every theme setting to its shipped default. */
    public function resetDefaults(): void
    {
        SiteSetting::set('default_theme_public', ThemeManager::DEFAULT_THEME);
        SiteSetting::set('default_theme_user', ThemeManager::DEFAULT_THEME);
        SiteSetting::set('default_theme_admin', ThemeManager::DEFAULT_THEME);
        SiteSetting::set('default_appearance', 'dark');
        SiteSetting::set('enabled_themes', implode(',', ThemeManager::presetKeys()));
        SiteSetting::set('allow_user_theme_switch', 'true');
        SiteSetting::set('allow_user_appearance_switch', 'true');
        SiteSetting::set('force_global_theme', 'false');
        SiteSetting::set('animation_intensity', 'medium');
        SiteSetting::set('card_radius', '0.9rem');
        SiteSetting::set('button_radius', '0.6rem');
        SiteSetting::set('icon_size', '1.25rem');
        SiteSetting::set('sidebar_icon_size', '1.25rem');
        SiteSetting::set('logo_size', '1.15rem');
        SiteSetting::set('image_size', '2.5rem');
        SiteSetting::set('font_scale', '100');
        SiteSetting::set('table_density', 'comfortable');
        SiteSetting::set('card_density', 'comfortable');
        SiteSetting::set('admin_sidebar_brand_size', '24px');
        SiteSetting::set('admin_sidebar_font_size', '14px');
        SiteSetting::set('admin_sidebar_group_label_size', '13px');
        SiteSetting::set('admin_sidebar_chevron_size', '12px');
        SiteSetting::set('admin_sidebar_item_height', '40px');
        SiteSetting::set('admin_sidebar_item_gap', '4px');
        SiteSetting::set('admin_sidebar_width', '280px');

        Notification::make()->title('تنظیمات به حالت پیش‌فرض بازنشانی شد.')->success()->send();
        $this->redirect(static::getUrl());
    }
}
