<?php

namespace App\Services;

use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\Order;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use Illuminate\Support\Facades\DB;

class ServiceProvisioner
{
    public function createFromOrder(Order $order): UserService
    {
        return DB::transaction(function () use ($order) {
            $existing = UserService::where('order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }

            $service = UserService::create([
                'user_id'          => $order->user_id,
                'order_id'         => $order->id,
                'plan_id'          => $order->plan_id,
                'plan_name'        => $order->plan_name,
                'traffic_total_gb' => $order->traffic_gb,
                'traffic_used_gb'  => 0,
                'duration_days'    => $order->duration_days,
                'status'           => UserService::STATUS_PENDING_PROVISION,
                'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
            ]);

            // Dispatch Marzban provisioning if a default active panel is configured
            $panel = VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();

            if ($panel) {
                VpnServiceProvisionLog::create([
                    'user_service_id' => $service->id,
                    'vpn_panel_id'    => $panel->id,
                    'action'          => 'create_placeholder_service',
                    'status'          => 'success',
                    'message'         => "Dispatching Marzban provisioning via panel: {$panel->name}",
                ]);

                ProvisionMarzbanServiceJob::dispatch($service->id, $panel->id)->afterCommit();
            } else {
                VpnServiceProvisionLog::create([
                    'user_service_id' => $service->id,
                    'action'          => 'create_placeholder_service',
                    'status'          => 'skipped',
                    'message'         => 'No active default Marzban panel found. Manual provisioning required.',
                ]);
            }

            return $service;
        });
    }

    public function activateManually(UserService $service): UserService
    {
        return DB::transaction(function () use ($service) {
            $service->markActive();

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'action'          => 'manual_activate',
                'status'          => 'success',
                'message'         => 'Service manually activated by admin.',
            ]);

            return $service->fresh();
        });
    }

    public function disableManually(UserService $service): UserService
    {
        return DB::transaction(function () use ($service) {
            $service->markDisabled();

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'action'          => 'manual_disable',
                'status'          => 'success',
                'message'         => 'Service manually disabled by admin.',
            ]);

            return $service->fresh();
        });
    }

    public function cancelManually(UserService $service): UserService
    {
        return DB::transaction(function () use ($service) {
            $service->markCancelled();

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'action'          => 'manual_cancel',
                'status'          => 'success',
                'message'         => 'Service manually cancelled by admin.',
            ]);

            return $service->fresh();
        });
    }

    public function expireManually(UserService $service): UserService
    {
        return DB::transaction(function () use ($service) {
            $service->markExpired();

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'action'          => 'manual_expire',
                'status'          => 'success',
                'message'         => 'Service manually marked as expired by admin.',
            ]);

            return $service->fresh();
        });
    }
}
