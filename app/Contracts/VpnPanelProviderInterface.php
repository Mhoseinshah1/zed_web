<?php

namespace App\Contracts;

use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\VpnPanels\ProviderResult;

/**
 * A VPN panel provider abstracts a panel type (Marzban, Sanaei/3X-UI, …) so
 * business logic can act on a UserService by its panel type without hardcoding
 * a specific panel implementation.
 */
interface VpnPanelProviderInterface
{
    public function testConnection(VpnPanel $panel): ProviderResult;

    public function provision(UserService $service): ProviderResult;

    public function sync(UserService $service): ProviderResult;

    /** @param array<string,mixed> $changes */
    public function update(UserService $service, array $changes): ProviderResult;

    public function revokeSubscription(UserService $service): ProviderResult;

    public function enable(UserService $service): ProviderResult;

    public function disable(UserService $service): ProviderResult;

    public function resetTraffic(UserService $service): ProviderResult;

    public function addTraffic(UserService $service, int $bytes): ProviderResult;

    public function addTime(UserService $service, int $days): ProviderResult;

    public function delete(UserService $service): ProviderResult;
}
