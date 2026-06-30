<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Theme\ThemeManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * پنل تم — a modern, standard theme panel for ZedProxy.
 *
 * A two-column interface (controls + a fully sandboxed live preview) over the
 * existing theme infrastructure ({@see ThemeManager}, inlineStyle, the
 * SiteSetting keys). The preview only restyles its own container; nothing is
 * applied to the real admin/user/public surfaces until «Save» calls
 * {@see persist()}, which writes the same SiteSetting keys the platform already
 * reads. Settings that have no control here are left untouched (never wiped).
 */
class ThemeStudio extends Page
{
    protected static string $view = 'filament.pages.theme-studio';

    protected static ?string $navigationIcon  = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = 'ظاهر سایت';
    protected static ?string $navigationLabel = 'پنل تم';
    protected static ?string $title           = 'پنل تم';
    protected static ?string $slug            = 'theme-studio';
    protected static ?int    $navigationSort  = 10;

    public function getSubheading(): ?string
    {
        return 'ظاهر کل پلتفرم را کنترل کن — تغییرات لحظه‌ای فقط در پیش‌نمایش دیده می‌شوند و پس از ذخیره اعمال می‌گردند';
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        // Preset cards (from ThemeManager) reduced to title + three swatches.
        $presets = [];
        foreach (ThemeManager::presets() as $slug => $p) {
            $dots = $p['dots'] ?? [];
            $presets[$slug] = [
                'title' => $p['title'] ?? $slug,
                'a'     => $dots[0] ?? ($p['colors']['primary'] ?? '#3b82f6'),
                'b'     => $dots[1] ?? ($p['colors']['accent'] ?? '#22d3ee'),
                'c'     => $p['colors']['surface'] ?? '#1c2438',
                'group' => $p['group'] ?? 'dark',
            ];
        }

        $adminTheme = ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN);
        $adminDots  = ThemeManager::presets()[$adminTheme]['dots'] ?? ['#3b82f6', '#22d3ee'];

        return [
            'presets'       => $presets,
            'groupLabels'   => ThemeManager::groupLabels(),
            'accentSwatches' => ['#3b82f6', '#22d3ee', '#10b981', '#a855f7', '#f59e0b', '#f43f5e', '#ec4899', '#64748b'],
            'state'         => [
                'scope'                        => 'public',
                'default_theme_public'         => ThemeManager::defaultTheme(ThemeManager::SURFACE_PUBLIC),
                'default_theme_user'           => ThemeManager::defaultTheme(ThemeManager::SURFACE_USER),
                'default_theme_admin'          => $adminTheme,
                'enabled_themes'               => ThemeManager::enabledThemes(),
                'accent'                       => $this->hexOr((string) SiteSetting::get('primary_color', ''), $adminDots[0] ?? '#3b82f6'),
                'accent2'                      => $this->hexOr((string) SiteSetting::get('accent_color', ''), $adminDots[1] ?? '#22d3ee'),
                'appearance'                   => (string) SiteSetting::get('default_appearance', 'dark'),
                'radius'                       => $this->radiusPx((string) SiteSetting::get('card_radius', '0.9rem')),
                'font_scale'                   => (int) max(85, min(120, (int) SiteSetting::get('font_scale', 100))),
                'allow_user_theme_switch'      => (bool) SiteSetting::get('allow_user_theme_switch', true),
                'allow_user_appearance_switch' => (bool) SiteSetting::get('allow_user_appearance_switch', true),
                'force_global_theme'           => (bool) SiteSetting::get('force_global_theme', false),
                // Advanced (rarely touched; kept because inlineStyle uses them).
                'animation_intensity'          => ThemeManager::animationIntensity(),
                'icon_size'                    => (string) SiteSetting::get('icon_size', '1.25rem'),
                'sidebar_icon_size'            => (string) SiteSetting::get('sidebar_icon_size', '1.25rem'),
                'logo_size'                    => (string) SiteSetting::get('logo_size', '1.15rem'),
                'image_size'                   => (string) SiteSetting::get('image_size', '2.5rem'),
                'table_density'                => (string) SiteSetting::get('table_density', 'comfortable'),
                'card_density'                 => (string) SiteSetting::get('card_density', 'comfortable'),
            ],
        ];
    }

    /**
     * Persist the panel state into the existing SiteSetting keys. Every key the
     * platform reads is written (preserving the value when a control is absent),
     * so nothing is wiped. Keeps the enabled-themes guard intact.
     *
     * @param  array<string,mixed>  $state
     */
    public function persist(array $state): void
    {
        $keys    = ThemeManager::presetKeys();
        $default = ThemeManager::DEFAULT_THEME;
        $valid   = fn ($k, $fallback) => in_array(ThemeManager::normalize($k), $keys, true) ? ThemeManager::normalize($k) : $fallback;

        SiteSetting::set('default_theme_public', $valid($state['default_theme_public'] ?? null, ThemeManager::defaultTheme(ThemeManager::SURFACE_PUBLIC)));
        SiteSetting::set('default_theme_user',   $valid($state['default_theme_user'] ?? null, ThemeManager::defaultTheme(ThemeManager::SURFACE_USER)));
        SiteSetting::set('default_theme_admin',  $valid($state['default_theme_admin'] ?? null, ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN)));

        // enabled_themes guard — never lock everyone out; keep defaults enabled.
        $enabled = [];
        foreach ((array) ($state['enabled_themes'] ?? []) as $k) {
            if (($norm = ThemeManager::normalize($k)) !== null) {
                $enabled[] = $norm;
            }
        }
        $enabled = array_values(array_unique($enabled));
        foreach ([$state['default_theme_admin'] ?? null, $state['default_theme_user'] ?? null, $state['default_theme_public'] ?? null] as $req) {
            if (($req = ThemeManager::normalize($req)) && ! in_array($req, $enabled, true)) {
                $enabled[] = $req;
            }
        }
        if (empty($enabled)) {
            $enabled = $keys;
        }
        SiteSetting::set('enabled_themes', implode(',', $enabled));

        // Appearance (sync the legacy + new keys so every resolver agrees).
        $appearance = in_array($state['appearance'] ?? null, ['light', 'dark', 'system'], true) ? $state['appearance'] : 'dark';
        SiteSetting::set('default_appearance', $appearance);
        SiteSetting::set('appearance_mode', $appearance);

        // Accent / brand colours — override the preset primary/accent everywhere.
        SiteSetting::set('primary_color', $this->hexOr((string) ($state['accent'] ?? ''), (string) SiteSetting::get('primary_color', '')));
        SiteSetting::set('accent_color',  $this->hexOr((string) ($state['accent2'] ?? ''), (string) SiteSetting::get('accent_color', '')));

        // Corner radius — one slider drives both card + button radius.
        $radius = (int) max(0, min(28, (int) ($state['radius'] ?? 14)));
        SiteSetting::set('card_radius', $radius . 'px');
        SiteSetting::set('button_radius', max(0, $radius - 4) . 'px');

        SiteSetting::set('font_scale', (int) max(85, min(120, (int) ($state['font_scale'] ?? 100))));

        SiteSetting::set('allow_user_theme_switch', ! empty($state['allow_user_theme_switch']) ? 'true' : 'false');
        SiteSetting::set('allow_user_appearance_switch', ! empty($state['allow_user_appearance_switch']) ? 'true' : 'false');
        SiteSetting::set('force_global_theme', ! empty($state['force_global_theme']) ? 'true' : 'false');

        // Advanced — preserve existing values when the field is absent/invalid.
        SiteSetting::set('animation_intensity', in_array($state['animation_intensity'] ?? null, ['off', 'low', 'medium', 'high'], true) ? $state['animation_intensity'] : (string) SiteSetting::get('animation_intensity', 'medium'));
        SiteSetting::set('icon_size', (string) ($state['icon_size'] ?? SiteSetting::get('icon_size', '1.25rem')));
        SiteSetting::set('sidebar_icon_size', (string) ($state['sidebar_icon_size'] ?? SiteSetting::get('sidebar_icon_size', '1.25rem')));
        SiteSetting::set('logo_size', (string) ($state['logo_size'] ?? SiteSetting::get('logo_size', '1.15rem')));
        SiteSetting::set('image_size', (string) ($state['image_size'] ?? SiteSetting::get('image_size', '2.5rem')));
        SiteSetting::set('table_density', in_array($state['table_density'] ?? null, ['compact', 'normal', 'comfortable'], true) ? $state['table_density'] : (string) SiteSetting::get('table_density', 'comfortable'));
        SiteSetting::set('card_density', in_array($state['card_density'] ?? null, ['compact', 'normal', 'comfortable'], true) ? $state['card_density'] : (string) SiteSetting::get('card_density', 'comfortable'));

        Notification::make()->title('تنظیمات ظاهر با موفقیت ذخیره و اعمال شد.')->success()->send();
    }

    /** Reset every theme setting this panel manages to its shipped default. */
    public function resetDefaults(): void
    {
        SiteSetting::set('default_theme_public', ThemeManager::DEFAULT_THEME);
        SiteSetting::set('default_theme_user', ThemeManager::DEFAULT_THEME);
        SiteSetting::set('default_theme_admin', ThemeManager::DEFAULT_THEME);
        SiteSetting::set('enabled_themes', implode(',', ThemeManager::presetKeys()));
        SiteSetting::set('default_appearance', 'dark');
        SiteSetting::set('appearance_mode', 'dark');
        SiteSetting::set('primary_color', '');
        SiteSetting::set('accent_color', '');
        SiteSetting::set('card_radius', '0.9rem');
        SiteSetting::set('button_radius', '0.6rem');
        SiteSetting::set('font_scale', '100');
        SiteSetting::set('allow_user_theme_switch', 'true');
        SiteSetting::set('allow_user_appearance_switch', 'true');
        SiteSetting::set('force_global_theme', 'false');
        SiteSetting::set('animation_intensity', 'medium');
        SiteSetting::set('icon_size', '1.25rem');
        SiteSetting::set('sidebar_icon_size', '1.25rem');
        SiteSetting::set('logo_size', '1.15rem');
        SiteSetting::set('image_size', '2.5rem');
        SiteSetting::set('table_density', 'comfortable');
        SiteSetting::set('card_density', 'comfortable');

        Notification::make()->title('تنظیمات به حالت پیش‌فرض بازنشانی شد.')->success()->send();
        $this->redirect(static::getUrl());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Return $value if it is a valid hex colour, otherwise $fallback. */
    protected function hexOr(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $value) === 1) {
            return str_starts_with($value, '#') ? $value : '#' . $value;
        }
        return $fallback;
    }

    /** Convert a stored radius ("0.9rem" | "14px") to an integer px for the slider. */
    protected function radiusPx(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/-?\d*\.?\d+/', $value, $m)) {
            return 14;
        }
        $n = (float) $m[0];
        $px = (str_contains($value, 'rem') || str_contains($value, 'em')) ? $n * 16 : $n;
        return (int) max(0, min(28, round($px)));
    }
}
