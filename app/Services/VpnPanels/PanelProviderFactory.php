<?php

namespace App\Services\VpnPanels;

use App\Contracts\VpnPanelProviderInterface;
use App\Models\UserService;
use App\Models\VpnPanel;

/**
 * Resolves the correct VpnPanelProvider for a panel/service by its type, so
 * business logic never hardcodes a specific panel implementation.
 */
class PanelProviderFactory
{
    public static function forType(string $type): VpnPanelProviderInterface
    {
        return match ($type) {
            VpnPanel::TYPE_SANAEI_XUI => new Sanaei3xUiProvider(),
            default                   => new MarzbanProvider(),
        };
    }

    public static function forPanel(VpnPanel $panel): VpnPanelProviderInterface
    {
        return self::forType($panel->type);
    }

    public static function forService(UserService $service): ?VpnPanelProviderInterface
    {
        $panel = $service->vpn_panel_id ? VpnPanel::find($service->vpn_panel_id) : null;
        return $panel ? self::forType($panel->type) : null;
    }

    public static function isSupported(string $type): bool
    {
        return in_array($type, [VpnPanel::TYPE_MARZBAN, VpnPanel::TYPE_SANAEI_XUI], true);
    }
}
