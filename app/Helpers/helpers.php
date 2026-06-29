<?php

use App\Models\SiteText;
use Illuminate\Support\Facades\Storage;

if (! function_exists('site_setting')) {
    function site_setting(string $key, string $default = ''): string
    {
        return SiteText::get($key, $default);
    }
}

if (! function_exists('cms_image')) {
    /**
     * Resolve a stored content image path (from the public disk) to a URL.
     * Accepts already-absolute URLs and returns the fallback when unset.
     */
    function cms_image(string $key, ?string $fallback = null): ?string
    {
        $path = trim(SiteText::get($key, ''));
        if ($path === '') {
            return $fallback;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }
        return Storage::disk('public')->url($path);
    }
}

if (! function_exists('cms_asset_url')) {
    /** Resolve a raw stored path (public disk) to a URL; null-safe. */
    function cms_asset_url(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }
        return Storage::disk('public')->url($path);
    }
}
