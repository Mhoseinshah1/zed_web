<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Marzban\UserServiceSyncService;
use Illuminate\Http\RedirectResponse;

class ServiceController extends Controller
{
    public function __construct(
        private readonly UserServiceSyncService $sync,
    ) {}

    /**
     * Service list — cached local data only. Never calls the Marzban API so
     * opening the list does not trigger a flood of requests.
     */
    public function index()
    {
        $user     = auth()->user();
        $services = $user->services()->latest()->paginate(15);

        return view('dashboard.services.index', compact('user', 'services'));
    }

    public function show(UserService $service)
    {
        abort_if($service->user_id !== auth()->id(), 403);

        $panel       = $service->marzbanPanel();
        $syncWarning = null;

        // On-demand sync for this single service only, gated by the cache window
        // (default 1 minute). If it's fresh, no Marzban request is made.
        $cacheMinutes = (int) SiteSetting::get('marzban_user_sync_cache_minutes', 1);

        if (
            filled($service->remote_username) &&
            $service->status === UserService::STATUS_ACTIVE &&
            $service->isSyncStale($cacheMinutes)
        ) {
            $this->sync->syncService($service);
            $service->refresh();

            if ($service->sync_status === UserService::SYNC_FAILED) {
                $syncWarning = 'اطلاعات سرویس در حال حاضر از آخرین بروزرسانی نمایش داده می‌شود.';
            }
        }

        // Resolve capabilities from panel toggles (defaults when no panel)
        $canSync              = $panel ? (bool) $panel->allow_user_sync_service          : true;
        $canRevoke            = $panel ? (bool) $panel->allow_user_revoke_subscription   : true;
        $canReset             = $panel ? (bool) $panel->allow_user_reset_traffic         : false;
        $canDisable           = $panel ? (bool) $panel->allow_user_disable_service       : false;
        $canEnable            = $panel ? (bool) $panel->allow_user_enable_service        : false;
        $canViewSubQr         = $panel ? (bool) $panel->allow_user_view_subscription_qr  : true;
        $canViewConfigQr      = $panel ? (bool) $panel->allow_user_view_config_qr        : true;
        $canCopySubLink       = $panel ? (bool) $panel->allow_user_copy_subscription_link : true;
        $canCopyConfigLink    = $panel ? (bool) $panel->allow_user_copy_config_link       : true;
        $canViewAllConfigLinks = $panel ? (bool) $panel->allow_user_view_all_config_links : true;

        return view('dashboard.services.show', compact(
            'service',
            'panel',
            'syncWarning',
            'canSync',
            'canRevoke',
            'canReset',
            'canDisable',
            'canEnable',
            'canViewSubQr',
            'canViewConfigQr',
            'canCopySubLink',
            'canCopyConfigLink',
            'canViewAllConfigLinks',
        ));
    }

    /**
     * Manual refresh for a single service. Owner-only, with a per-service
     * cooldown (default 60s). Syncs only this service.
     */
    public function refresh(UserService $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        $cooldown = (int) SiteSetting::get('marzban_manual_refresh_cooldown_seconds', 60);

        if (
            $service->last_manual_sync_at !== null &&
            $service->last_manual_sync_at->gt(now()->subSeconds($cooldown))
        ) {
            return back()->with('error', 'برای بروزرسانی مجدد 1 دقیقه صبر کنید.');
        }

        $service->update(['last_manual_sync_at' => now()]);
        $this->sync->syncService($service);
        $service->refresh();

        if ($service->sync_status === UserService::SYNC_FAILED) {
            return back()->with('error', 'اطلاعات سرویس در حال حاضر از آخرین بروزرسانی نمایش داده می‌شود.');
        }

        return back()->with('success', 'اطلاعات سرویس بروزرسانی شد.');
    }
}
