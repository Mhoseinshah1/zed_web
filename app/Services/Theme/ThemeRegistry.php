<?php

namespace App\Services\Theme;

/**
 * Single source of truth for theme presets and their metadata.
 *
 * The colour catalog itself lives in {@see ThemeManager::presets()} (kept there
 * for backwards compatibility with everything already consuming it). This
 * registry enriches every preset with the additional design metadata the Theme
 * Studio needs — semantic colours, card shadow, button/badge styles — so views
 * and resolvers have one consistent, complete shape to read from.
 */
class ThemeRegistry
{
    /** Shared semantic colours (consistent across presets unless overridden). */
    private const SEMANTIC = [
        'success' => '#34d399',
        'warning' => '#fbbf24',
        'danger'  => '#f43f5e',
        'info'    => '#38bdf8',
    ];

    /**
     * All presets, enriched.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (ThemeManager::presets() as $slug => $preset) {
            $out[$slug] = self::enrich($slug, $preset);
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(ThemeManager::presets());
    }

    public static function has(string $slug): bool
    {
        return array_key_exists($slug, ThemeManager::presets());
    }

    /** @return array<string,mixed>|null */
    public static function get(string $slug): ?array
    {
        $preset = ThemeManager::presets()[$slug] ?? null;
        return $preset ? self::enrich($slug, $preset) : null;
    }

    /**
     * Presets grouped by gallery group (dark / light / special).
     *
     * @return array<string,array<int,string>>
     */
    public static function groups(): array
    {
        return ThemeManager::groups();
    }

    /** @return array<string,string> */
    public static function groupLabels(): array
    {
        return ThemeManager::groupLabels();
    }

    /**
     * @param  array<string,mixed>  $preset
     * @return array<string,mixed>
     */
    private static function enrich(string $slug, array $preset): array
    {
        $colors  = $preset['colors'] ?? [];
        $primary = $colors['primary'] ?? '#3b82f6';

        return [
            'slug'        => $slug,
            'title'       => $preset['title'] ?? $slug,
            'name'        => $preset['name'] ?? $slug,
            'group'       => $preset['group'] ?? 'dark',
            'appearance'  => $preset['appearance'] ?? 'dark',
            'description' => $preset['description'] ?? '',
            'dots'        => $preset['dots'] ?? [$primary],
            'colors'      => array_merge([
                'primary'      => $primary,
                'secondary'    => $colors['secondary'] ?? $primary,
                'accent'       => $colors['accent'] ?? $primary,
                'bg'           => $colors['bg'] ?? '#0a0e1a',
                'surface'      => $colors['surface'] ?? '#141a2b',
                'surface_soft' => $colors['surface_soft'] ?? '#1c2438',
                'text'         => $colors['text'] ?? '#e8ebf5',
                'muted'        => $colors['muted'] ?? '#9aa3bd',
                'border'       => $colors['border'] ?? '#283047',
                'gradient'     => $colors['gradient'] ?? "linear-gradient(135deg,{$primary},{$primary})",
            ], self::SEMANTIC, $colors),
            // Design metadata used by the studio preview / chrome.
            'card_shadow'  => $preset['card_shadow'] ?? '0 10px 30px -12px rgb(0 0 0 / .5)',
            'button_style' => $preset['button_style'] ?? 'gradient',
            'badge_style'  => $preset['badge_style'] ?? 'soft',
        ];
    }
}
