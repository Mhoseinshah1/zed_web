<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\UserService;
use App\Services\Marzban\UserServiceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMarzbanServices extends Command
{
    protected $signature = 'zedproxy:sync-marzban-services
        {--service-id= : Sync a single service by id}
        {--username= : Sync a single service by Marzban username}
        {--limit= : Maximum number of services to sync}
        {--failed-only : Only services with sync_status = failed}
        {--pending-only : Only services with sync_status = pending}
        {--near-expiry : Only active services near expiry}
        {--active-only : Only active services}';

    protected $description = 'Lightweight, batched Marzban → local sync. Skips services without a Marzban username; never creates Marzban users.';

    public function handle(UserServiceSyncService $sync): int
    {
        $limit = (int) ($this->option('limit') ?: SiteSetting::get('marzban_background_sync_batch_size', 50));
        $limit = max(1, $limit);

        // Single service shortcuts.
        if ($id = $this->option('service-id')) {
            $service = UserService::find($id);
            if (! $service) {
                $this->error("Service {$id} not found.");
                return self::FAILURE;
            }
            $sync->syncService($service);
            $this->info("Synced service {$id} (status: {$service->fresh()->sync_status}).");
            return self::SUCCESS;
        }

        if ($username = $this->option('username')) {
            $service = $sync->syncUserByUsername($username);
            $this->info($service ? "Synced '{$username}'." : "No service with username '{$username}'.");
            return self::SUCCESS;
        }

        // Batched modes.
        $count = match (true) {
            (bool) $this->option('failed-only')  => $sync->syncFailedServices($limit),
            (bool) $this->option('pending-only') => $sync->syncPendingServices($limit),
            (bool) $this->option('near-expiry')  => $sync->syncNearExpiryServices(
                (int) SiteSetting::get('marzban_near_expiry_sync_days', 3),
                $limit,
            ),
            (bool) $this->option('active-only')  => $sync->syncBatch(
                UserService::whereNotNull('remote_username')
                    ->where('status', UserService::STATUS_ACTIVE)
                    ->limit($limit)->get()
            ),
            // Default background scope: failed + pending only (never all services).
            default => $sync->syncFailedServices($limit) + $sync->syncPendingServices($limit),
        };

        $this->info("Synced {$count} service(s).");
        Log::info('zedproxy:sync-marzban-services completed', ['synced' => $count, 'limit' => $limit]);

        return self::SUCCESS;
    }
}
