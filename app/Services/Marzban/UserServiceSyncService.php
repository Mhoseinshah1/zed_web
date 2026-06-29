<?php

namespace App\Services\Marzban;

use App\Models\Notification;
use App\Models\SiteSetting;
use App\Models\UserService;
use App\Services\Notifications\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight, on-demand Marzban → local sync.
 *
 * Reuses the existing MarzbanClient (token caching, 401-retry, timeouts).
 * Never creates a Marzban user; only GET /api/user/{username} (+ /usage) are
 * used. Good local data is never overwritten with null/bad API responses.
 */
class UserServiceSyncService
{
    private const BYTES_PER_GB = 1_073_741_824;

    /**
     * Sync a single service from Marzban. Always returns the (fresh) service —
     * on failure the cached data is preserved and sync_error is recorded.
     */
    public function syncService(UserService $service): UserService
    {
        // No remote user yet → nothing to pull; mark pending.
        if (blank($service->remote_username)) {
            $service->update(['sync_status' => UserService::SYNC_PENDING]);
            return $service->fresh();
        }

        $panel = $service->marzbanPanel();
        if (! $panel) {
            $service->update([
                'sync_status' => UserService::SYNC_FAILED,
                'sync_error'  => 'هیچ پنل Marzban فعالی یافت نشد.',
            ]);
            return $service->fresh();
        }

        try {
            $client      = new MarzbanClient($panel);
            $marzbanUser = $client->getUser($service->remote_username);
        } catch (MarzbanException $e) {
            if ($e->getCode() === 404) {
                $service->update([
                    'sync_status'    => UserService::SYNC_NOT_FOUND,
                    'sync_error'     => null,
                    'last_synced_at' => now(),
                ]);
                return $service->fresh();
            }
            return $this->markFailed($service, $e);
        } catch (\Throwable $e) {
            return $this->markFailed($service, $e);
        }

        return $this->applyMarzbanData($service, $client, $marzbanUser);
    }

    /**
     * Should this service be synced now (cache window from settings)?
     */
    public function shouldSync(UserService $service): bool
    {
        if (blank($service->remote_username)) {
            return false;
        }
        $cacheMinutes = (int) SiteSetting::get('marzban_user_sync_cache_minutes', 1);
        return $service->isSyncStale($cacheMinutes);
    }

    public function syncUserByUsername(string $username): ?UserService
    {
        $service = UserService::where('remote_username', $username)->first();
        return $service ? $this->syncService($service) : null;
    }

    public function syncFailedServices(int $limit = 50): int
    {
        return $this->syncBatch(
            UserService::whereNotNull('remote_username')
                ->where('sync_status', UserService::SYNC_FAILED)
                ->limit($limit)->get()
        );
    }

    public function syncPendingServices(int $limit = 50): int
    {
        return $this->syncBatch(
            UserService::whereNotNull('remote_username')
                ->where(fn ($q) => $q->where('sync_status', UserService::SYNC_PENDING)->orWhereNull('sync_status'))
                ->limit($limit)->get()
        );
    }

    public function syncNearExpiryServices(int $days = 3, int $limit = 50): int
    {
        return $this->syncBatch(
            UserService::whereNotNull('remote_username')
                ->where('status', UserService::STATUS_ACTIVE)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays($days)])
                ->limit($limit)->get()
        );
    }

    /**
     * @param \Illuminate\Support\Collection<int,UserService> $services
     */
    public function syncBatch($services): int
    {
        $count = 0;
        foreach ($services as $service) {
            try {
                $this->syncService($service);
                $count++;
            } catch (\Throwable $e) {
                // One failure must not abort the whole batch.
                Log::warning('UserServiceSyncService: batch item failed', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function applyMarzbanData(UserService $service, MarzbanClient $client, array $marzbanUser): UserService
    {
        $normalized = $client->normalizeUserResponse($marzbanUser);
        $subLink    = $client->extractSubscriptionLink($marzbanUser);

        $usedBytes  = (int) ($marzbanUser['used_traffic'] ?? 0);
        $limitBytes = (int) ($marzbanUser['data_limit'] ?? 0);

        // Build updates, never overwriting good local values with null/zero/bad data.
        $updates = [
            'sync_status'    => UserService::SYNC_SYNCED,
            'sync_error'     => null,
            'last_synced_at' => now(),
            'marzban_raw'    => $this->safeRaw($marzbanUser),
        ];

        if (! empty($normalized['status'])) {
            $updates['marzban_status'] = $normalized['status'];
        }
        $updates['marzban_used_traffic'] = $usedBytes;
        if ($limitBytes > 0) {
            $updates['marzban_data_limit'] = $limitBytes;
        }
        if (! empty($marzbanUser['expire'])) {
            $updates['marzban_expire_at'] = Carbon::createFromTimestamp((int) $marzbanUser['expire']);
        }
        if (! empty($marzbanUser['online_at'])) {
            try {
                $updates['marzban_online_at'] = Carbon::parse($marzbanUser['online_at']);
            } catch (\Throwable) {
                // ignore unparsable timestamp
            }
        }

        // Local mirror — only when the API gives meaningful values.
        $updates['traffic_used_gb'] = round($usedBytes / self::BYTES_PER_GB, 2);
        if ($limitBytes > 0) {
            $updates['traffic_total_gb'] = (int) round($limitBytes / self::BYTES_PER_GB);
        }
        if (! empty($normalized['expire'])) {
            $updates['expires_at'] = Carbon::createFromTimestamp((int) $normalized['expire']);
        }
        if (filled($subLink)) {
            $updates['subscription_link'] = $subLink;
        }
        if (! empty($marzbanUser['links'][0])) {
            $updates['config_link'] = $marzbanUser['links'][0];
        }

        // Reflect Marzban active/disabled into local status (do not touch expired/cancelled).
        if (($normalized['status'] ?? null) === 'active' && $service->status === UserService::STATUS_DISABLED) {
            $updates['status'] = UserService::STATUS_ACTIVE;
        } elseif (($normalized['status'] ?? null) === 'disabled' && $service->status === UserService::STATUS_ACTIVE) {
            $updates['status'] = UserService::STATUS_DISABLED;
        }

        $service->update($updates);
        return $service->fresh();
    }

    private function markFailed(UserService $service, \Throwable $e): UserService
    {
        $service->update([
            'sync_status' => UserService::SYNC_FAILED,
            'sync_error'  => $this->sanitize($e->getMessage()),
        ]);

        Log::warning('UserServiceSyncService: sync failed', [
            'service_id' => $service->id,
            'error'      => $this->sanitize($e->getMessage()),
        ]);

        // Notify admins on repeated failures (idempotent within the hour).
        app(NotificationService::class)->notifyAdmins(
            Notification::TYPE_ADMIN_WARNING,
            ['message' => "سینک سرویس #{$service->id} با Marzban ناموفق بود."],
            'sync_failed:' . $service->id . ':' . now()->format('YmdH'),
        );

        return $service->fresh();
    }

    /**
     * Keep a compact, credential-free snapshot of the raw response.
     */
    private function safeRaw(array $response): array
    {
        return collect($response)
            ->only(['username', 'status', 'used_traffic', 'data_limit', 'expire', 'online_at', 'subscription_url'])
            ->all();
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        return mb_substr($message, 0, 1000);
    }
}
