<?php

namespace App\Services;

use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\Order;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\Provisioning\CreatesUserServiceForOrder;
use Illuminate\Support\Facades\DB;

class ServiceProvisioner
{
    use CreatesUserServiceForOrder;

    public function createFromOrder(Order $order): UserService
    {
        // Atomic: lock the order, re-check, create, and fall back to the DB
        // unique index. On a race the loser gets the existing service and does
        // NOT re-dispatch the job or write a duplicate log.
        [$service, $created] = $this->firstOrCreateServiceForOrder($order, [
            'user_id' => $order->user_id,
            'plan_id' => $order->plan_id,
            'plan_name' => $order->plan_name,
            // Pin the plan's chosen panel (null = default fallback below).
            'vpn_panel_id' => $order->plan?->vpn_panel_id,
            'traffic_total_gb' => $order->traffic_gb,
            'traffic_used_gb' => 0,
            'duration_days' => $order->duration_days,
            'status' => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_MANUAL_REQUIRED,
        ]);

        if (! $created) {
            // Another concurrent process already created + dispatched for this
            // order. Return it as-is (idempotent) — never provision twice.
            return $service;
        }

        // Resolve the target panel: the plan's chosen panel first, then the
        // default Marzban panel, then any active default panel (mirrors
        // ProvisioningService so all panel types are honoured).
        $panel = ($service->vpn_panel_id ? VpnPanel::find($service->vpn_panel_id) : null)
            ?? VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                ->where('is_active', true)
                ->where('is_default', true)
                ->first()
            ?? VpnPanel::where('is_active', true)->where('is_default', true)->first();

        if ($panel) {
            // Transition order to "provisioning" — job will update to completed/provisioning_failed
            $order->update(['status' => Order::STATUS_PROVISIONING]);

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'vpn_panel_id' => $panel->id,
                'action' => 'create_placeholder_service',
                'status' => 'success',
                'message' => "Dispatching provisioning via panel: {$panel->name} ({$panel->type})",
            ]);

            // Idempotent dispatch — ShouldBeUnique keyed on the service id means a
            // second dispatch for the same service is a no-op while one is queued.
            ProvisionMarzbanServiceJob::dispatch($service->id, $panel->id)->afterCommit();
        } else {
            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'action' => 'create_placeholder_service',
                'status' => 'skipped',
                'message' => 'No active default panel found. Manual provisioning required.',
            ]);
        }

        return $service;
    }

    public function activateManually(UserService $service): UserService
    {
        return DB::transaction(function () use ($service) {
            $service->markActive();

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'action' => 'manual_activate',
                'status' => 'success',
                'message' => 'Service manually activated by admin.',
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
                'action' => 'manual_disable',
                'status' => 'success',
                'message' => 'Service manually disabled by admin.',
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
                'action' => 'manual_cancel',
                'status' => 'success',
                'message' => 'Service manually cancelled by admin.',
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
                'action' => 'manual_expire',
                'status' => 'success',
                'message' => 'Service manually marked as expired by admin.',
            ]);

            return $service->fresh();
        });
    }
}
