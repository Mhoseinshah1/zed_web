<?php

namespace App\Http\Controllers;

use App\Services\Theme\ThemeManager;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    /**
     * Persist the visitor's theme preset and/or appearance preference.
     * Logged-in users are saved to their row; guests get a 1-year cookie.
     * Respects the admin "allow user switch" / "force global" settings.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'theme'      => ['nullable', 'string', 'max:40'],
            'appearance' => ['nullable', 'in:light,dark,system'],
        ]);

        $user    = $request->user();
        $cookies = [];

        if (! empty($data['theme']) && ThemeManager::allowUserThemeSwitch()) {
            $theme = $data['theme'];
            if (ThemeManager::isValidPreset($theme) && in_array($theme, ThemeManager::enabledThemes(), true)) {
                if ($user) {
                    $user->forceFill(['theme_preference' => $theme])->save();
                } else {
                    $cookies[] = cookie('zed_theme', $theme, 60 * 24 * 365);
                }
            }
        }

        if (! empty($data['appearance']) && ThemeManager::allowUserAppearanceSwitch()) {
            if ($user) {
                $user->forceFill(['appearance' => $data['appearance']])->save();
            } else {
                $cookies[] = cookie('zed_appearance', $data['appearance'], 60 * 24 * 365);
            }
        }

        $response = $request->expectsJson()
            ? response()->json(['ok' => true])
            : back();

        foreach ($cookies as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
