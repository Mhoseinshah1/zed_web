<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\UserService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Provisions the remote VPN user for one UserService.
 *
 * ShouldBeUnique keyed on the service id: while a job for a given service is
 * queued/running, a second dispatch for the SAME service is dropped — so a
 * duplicate payment webhook/callback/retry can never spawn two provisioning
 * jobs (and thus never two remote VPN users). Combined with the idempotent
 * provider create + the unique order_id index, provisioning runs at most once.
 */
class ProvisionMarzbanServiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    /** Release the uniqueness lock after 10 minutes as a safety net. */
    public int $uniqueFor = 600;

    public function __construct(
        private int $serviceId,
        private int $panelId,
    ) {}

    /** One in-flight provisioning job per service. */
    public function uniqueId(): string
    {
        return 'provision-service:'.$this->serviceId;
    }

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
                'order_id' => $order->id,
                'service_id' => $this->serviceId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e; // allow queue retry
        }
    }

    public function failed(\Throwable $e): void
    {
        // All retries exhausted — order is already marked provisioning_failed by ProvisioningService
        Log::error('ProvisionMarzbanServiceJob: all retries exhausted', [
            'service_id' => $this->serviceId,
            'error' => $e->getMessage(),
        ]);
    }
}
