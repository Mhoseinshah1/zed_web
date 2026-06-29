<?php

namespace App\Services\Theme;

use App\Models\SiteSetting;

/**
 * Homepage template/layout selector — INDEPENDENT of the colour ThemeManager.
 *
 * ThemeManager changes colours (the --zp-* palette); this service changes the
 * STRUCTURE/LAYOUT of the homepage. The active template is stored in the DB
 * (SiteSetting key `active_homepage_template`); both templates re-use the same
 * --zp-* CSS variables so a template stays in sync with the active colour theme.
 */
class TemplateManager
{
    public const SETTING_KEY    = 'active_homepage_template';
    public const DEFAULT_TEMPLATE = 'classic';

    /**
     * Catalog of homepage templates with Persian metadata for the admin gallery.
     *
     * @return array<string, array{title:string, description:string, preview:string, accent:string}>
     */
    public static function templates(): array
    {
        return [
            'classic' => [
                'title'       => 'قالب کلاسیک',
                'description' => 'چیدمان فعلی سایت؛ هیرو مرکزی، ویژگی‌ها، پلن‌ها، لوکیشن‌ها و سوالات متداول.',
                'preview'     => 'classic',
                'accent'      => 'linear-gradient(135deg,#6366f1,#a855f7 55%,#22d3ee)',
            ],
            'modern' => [
                'title'       => 'قالب مدرن',
                'description' => 'نوار اعتماد بالای منو، هدر شیشه‌ای، هیرو با متن گرادینتی و نوار اعتماد چهارتایی با کارت پلن ویژه.',
                'preview'     => 'modern',
                'accent'      => 'linear-gradient(135deg,#1e3a8a,#3b82f6 50%,#22d3ee)',
            ],
            'shop' => [
                'title'       => 'قالب فروشگاهی',
                'description' => 'فروش‌محور · با گالری سرورها، نظرات و آمار زنده',
                'preview'     => 'shop',
                'accent'      => 'linear-gradient(135deg,#0ea5e9,#22d3ee 55%,#34d399)',
            ],
        ];
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::templates());
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::templates());
    }

    /** Normalise an arbitrary value to a valid template slug, or null. */
    public static function normalize(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }
        $key = trim($key);
        return self::isValid($key) ? $key : null;
    }

    /** The active homepage template slug (validated, falls back to default). */
    public static function activeTemplate(): string
    {
        $value = self::normalize((string) SiteSetting::get(self::SETTING_KEY, self::DEFAULT_TEMPLATE));
        return $value ?? self::DEFAULT_TEMPLATE;
    }

    /** Persist a new active template; ignores invalid values. */
    public static function setActiveTemplate(string $key): void
    {
        $key = self::normalize($key);
        if ($key !== null) {
            SiteSetting::set(self::SETTING_KEY, $key);
        }
    }
}
