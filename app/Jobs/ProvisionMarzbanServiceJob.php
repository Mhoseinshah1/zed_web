<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionMarzbanServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries  = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private int $serviceId,
        private int $panelId,
    ) {}

    public function handle(ProvisioningService $provisioner): void
    {
        $service = UserService::find($this->serviceId);
        if (! $service) {
            Log::warning("ProvisionMarzbanServiceJob: service {$this->serviceId} not found — skipping");
            return;
        }

        $order = $service->order_id ? Order::find($service->order_id) : null;
        if (! $order) {
            Log::warning("ProvisionMarzbanServiceJob: no order for service {$this->serviceId} — skipping");
            return;
        }

        try {
            $provisioner->provisionOrder($order);
        } catch (\RuntimeException $e) {
            Log::error('ProvisionMarzbanServiceJob: provisioning failed', [
                'order_id'   => $order->id,
                'service_id' => $this->serviceId,
                'attempt'    => $this->attempts(),
                'error'      => $e->getMessage(),
            ]);
            throw $e; // allow queue retry
        }
    }

    public function failed(\Throwable $e): void
    {
        // All retries exhausted — order is already marked provisioning_failed by ProvisioningService
        Log::error('ProvisionMarzbanServiceJob: all retries exhausted', [
            'service_id' => $this->serviceId,
            'error'      => $e->getMessage(),
        ]);
    }
}
