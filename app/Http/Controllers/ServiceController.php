<?php

namespace App\Http\Controllers;

use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\Marzban\MarzbanClient;
use Carbon\Carbon;

class ServiceController extends Controller
{
    public function index()
    {
        $user     = auth()->user();
        $services = $user->services()->latest()->paginate(15);

        return view('dashboard.services.index', compact('user', 'services'));
    }

    public function show(UserService $service)
    {
        abort_if($service->user_id !== auth()->id(), 403);

        $panel       = $service->vpnPanel
            ?? VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();

        $syncWarning = null;

        // Auto-sync if service is active with a remote username and last sync > 30s ago
        if (
            $panel &&
            filled($service->remote_username) &&
            $service->status === UserService::STATUS_ACTIVE &&
            ($service->last_synced_at === null || $service->last_synced_at->lt(now()->subSeconds(30)))
        ) {
            try {
                $client      = new MarzbanClient($panel);
                $marzbanUser = $client->getUser($service->remote_username);
                $normalized  = $client->normalizeUserResponse($marzbanUser);
                $subLink     = $client->extractSubscriptionLink($marzbanUser);

                $updates = [
                    'traffic_used_gb'   => $normalized['used_traffic_gb'],
                    'subscription_link' => $subLink ?? $service->subscription_link,
                    'config_link'       => $marzbanUser['links'][0] ?? $service->config_link,
                    'last_synced_at'    => now(),
                ];

                if (! empty($normalized['expire'])) {
                    $updates['expires_at'] = Carbon::createFromTimestamp((int) $normalized['expire']);
                }

                if ($normalized['data_limit_gb'] > 0) {
                    $updates['traffic_total_gb'] = $normalized['data_limit_gb'];
                }

                if ($normalized['status'] === 'active' && $service->status === UserService::STATUS_DISABLED) {
                    $updates['status'] = UserService::STATUS_ACTIVE;
                } elseif ($normalized['status'] === 'disabled' && $service->status === UserService::STATUS_ACTIVE) {
                    $updates['status'] = UserService::STATUS_DISABLED;
                }

                $service->update($updates);
                $service->refresh();

                VpnServiceProvisionLog::create([
                    'user_service_id'  => $service->id,
                    'vpn_panel_id'     => $panel->id,
                    'action'           => 'user_auto_sync_on_view',
                    'status'           => 'success',
                    'message'          => "Auto-synced on service detail view. Status: {$normalized['status']}.",
                    'response_payload' => ['status' => $normalized['status'], 'used_traffic_gb' => $normalized['used_traffic_gb']],
                ]);

            } catch (\Throwable $e) {
                $syncWarning = 'بروزرسانی خودکار اطلاعات سرویس در این لحظه امکان‌پذیر نبود.';
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
}
