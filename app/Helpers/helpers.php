<?php

use App\Models\SiteText;

if (! function_exists('site_setting')) {
    function site_setting(string $key, string $default = ''): string
    {
        return SiteText::get($key, $default);
    }
}
