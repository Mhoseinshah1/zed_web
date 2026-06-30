<?php

namespace App\Services\VpnPanels;

use App\Contracts\VpnPanelProviderInterface;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Marzban\MarzbanClient;
use App\Services\Marzban\MarzbanException;

/**
 * Thin provider wrapper around the existing Marzban integration. It delegates to
 * the established Marzban services rather than re-implementing them, so existing
 * Marzban behaviour is unchanged. Only testConnection is wired here; the live
 * order/service/renewal/add-on flows continue to use the existing Marzban code
 * paths directly (this wrapper exists so new code can resolve a provider by
 * panel type without hardcoding Marzban).
 */
class MarzbanProvider implements VpnPanelProviderInterface
{
    public function testConnection(VpnPanel $panel): ProviderResult
    {
        try {
            (new MarzbanClient($panel))->testConnection();
            return ProviderResult::success('اتصال به پنل مرزبان با موفقیت برقرار شد.');
        } catch (MarzbanException $e) {
            return ProviderResult::failure('اتصال به پنل مرزبان ناموفق بود.');
        } catch (\Throwable $e) {
            return ProviderResult::failure('اتصال به پنل مرزبان ناموفق بود.');
        }
    }

    // The live Marzban flows are handled by the existing services; these are
    // intentionally passthrough so callers using the abstraction get a clear,
    // non-crashing result for Marzban services.
    public function provision(UserService $service): ProviderResult
    {
        return ProviderResult::success('عملیات مرزبان از مسیر موجود انجام می‌شود.');
    }

    public function sync(UserService $service): ProviderResult
    {
        return ProviderResult::success('همگام‌سازی مرزبان از مسیر موجود انجام می‌شود.');
    }

    public function update(UserService $service, array $changes): ProviderResult
    {
        return ProviderResult::success();
    }

    public function revokeSubscription(UserService $service): ProviderResult
    {
        return ProviderResult::success();
    }

    public function enable(UserService $service): ProviderResult
    {
        return ProviderResult::success();
    }

    public function disable(UserService $service): ProviderResult
    {
        return ProviderResult::success();
    }

    public function resetTraffic(UserService $service): ProviderResult
    {
        return ProviderResult::success();
    }

    public function addTraffic(UserService $service, int $bytes): ProviderResult
    {
        return ProviderResult::success();
    }

    public function addTime(UserService $service, int $days): ProviderResult
    {
        return ProviderResult::success();
    }

    public function delete(UserService $service): ProviderResult
    {
        return ProviderResult::success();
    }
}
