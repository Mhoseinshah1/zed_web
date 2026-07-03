<?php

namespace App\Services\VpnPanels;

use App\Contracts\VpnPanelProviderInterface;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\VpnPanels\Remnawave\RemnawaveClient;
use App\Services\VpnPanels\Remnawave\RemnawaveException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Remnawave (v2.8.0) panel provider. Wraps RemnawaveClient and maps results onto
 * the existing UserService fields. Remnawave is UUID-centric: the uuid returned
 * by create is stored in UserService::remote_uuid and every later operation
 * references it. Units follow the API spec exactly — trafficLimitBytes is bytes
 * and expireAt is an ISO-8601 date-time string.
 */
class RemnawaveProvider implements VpnPanelProviderInterface
{
    /** Far-future expiry used when a plan has no duration (expireAt is required). */
    private const NO_EXPIRY_YEARS = 10;

    private function client(VpnPanel $panel): RemnawaveClient
    {
        return new RemnawaveClient($panel);
    }

    private function panelOf(UserService $service): ?VpnPanel
    {
        return $service->vpn_panel_id ? VpnPanel::find($service->vpn_panel_id) : null;
    }

    public function testConnection(VpnPanel $panel): ProviderResult
    {
        try {
            $this->client($panel)->testConnection();
            return ProviderResult::success('اتصال به پنل Remnawave با موفقیت برقرار شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    // ── Provisioning ──────────────────────────────────────────────────────────

    public function provision(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        if (! $panel) {
            return ProviderResult::failure('پنل سرویس یافت نشد.');
        }

        $client   = $this->client($panel);
        $username = $this->makeUsername($service);

        try {
            // Idempotency: if a user with this username already exists, sync it
            // instead of creating a duplicate.
            $existing = $this->findExisting($client, $username);
            if ($existing !== null) {
                $this->fillFromRemote($service, $panel, $existing);
                $service->save();
                return ProviderResult::success('سرویس موجود همگام‌سازی شد.', ['username' => $username, 'existed' => true]);
            }

            $plan     = $service->plan ?? null;
            $totalGB  = (int) (($plan->traffic_gb ?? 0) * 1024 * 1024 * 1024); // bytes (0 = unlimited)
            $days     = (int) ($plan->duration_days ?? 0);
            $expiry   = $days > 0 ? now()->addDays($days) : now()->addYears(self::NO_EXPIRY_YEARS);

            $payload = [
                'username'             => $username,
                'expireAt'            => $this->toIso($expiry),          // ISO-8601 date-time
                'trafficLimitBytes'   => $totalGB,                       // bytes
                'trafficLimitStrategy' => 'NO_RESET',
                'status'              => 'ACTIVE',
            ];
            if (filled($panel->default_squad_uuid)) {
                $payload['activeInternalSquads'] = [(string) $panel->default_squad_uuid];
            }

            $created = $client->createUser($payload);

            $service->vpn_panel_id         = $panel->id;
            $service->remote_username      = $created['username'] ?? $username;
            $service->remote_uuid          = $created['uuid'] ?? null;
            $service->remote_client_id     = $created['uuid'] ?? null;
            $service->remote_sub_id        = $created['shortUuid'] ?? null;
            $service->marzban_data_limit   = $totalGB ?: null;
            $service->marzban_used_traffic = 0;
            $service->expires_at           = $days > 0 ? $expiry : null;
            $service->remote_status        = $created['status'] ?? 'ACTIVE';
            $service->sync_status          = UserService::SYNC_SYNCED;
            $service->last_synced_at       = now();
            $service->sync_error           = null;

            $this->applyLinks($service, $created);
            $service->save();

            return ProviderResult::success('سرویس روی پنل Remnawave ساخته شد.', ['username' => $username]);
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    // ── Sync ──────────────────────────────────────────────────────────────────

    public function sync(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس برای همگام‌سازی کافی نیست.');
        }

        try {
            $user = $this->client($panel)->getUser($uuid);
            if (empty($user)) {
                $service->sync_status = UserService::SYNC_NOT_FOUND;
                $service->save();
                return ProviderResult::success('کاربر در پنل یافت نشد؛ اطلاعات محلی نمایش داده می‌شود.', ['status' => 'not_found']);
            }

            $this->fillFromRemote($service, $panel, $user);
            $service->save();

            return ProviderResult::success('همگام‌سازی انجام شد.');
        } catch (RemnawaveException $e) {
            $service->sync_status = UserService::SYNC_FAILED;
            $service->sync_error  = $e->getMessage();
            $service->save();
            return ProviderResult::failure('اطلاعات سرویس در حال حاضر از آخرین بروزرسانی نمایش داده می‌شود.');
        }
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public function update(UserService $service, array $changes): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->updateUser(array_merge(['uuid' => $uuid], $changes));
            return ProviderResult::success('سرویس بروزرسانی شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function enable(UserService $service): ProviderResult
    {
        return $this->action($service, 'enable', 'active', 'سرویس فعال شد.');
    }

    public function disable(UserService $service): ProviderResult
    {
        return $this->action($service, 'disable', 'disabled', 'سرویس غیرفعال شد.');
    }

    private function action(UserService $service, string $verb, string $status, string $ok): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $client = $this->client($panel);
            $verb === 'enable' ? $client->enableUser($uuid) : $client->disableUser($uuid);
            $service->remote_status = $status;
            $service->save();
            return ProviderResult::success($ok);
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function resetTraffic(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->resetUserTraffic($uuid);
            $service->marzban_used_traffic = 0;
            $service->save();
            return ProviderResult::success('ترافیک سرویس صفر شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function addTraffic(UserService $service, int $bytes): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $client       = $this->client($panel);
            $current      = (int) ($client->getUser($uuid)['trafficLimitBytes'] ?? $service->marzban_data_limit ?? 0);
            $newTotal     = $current + max(0, $bytes); // add to quota, do NOT reset usage
            $client->updateUser(['uuid' => $uuid, 'trafficLimitBytes' => $newTotal]);
            $service->marzban_data_limit = $newTotal;
            $service->save();
            return ProviderResult::success('حجم اضافه اعمال شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function addTime(UserService $service, int $days): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $base = ($service->expires_at && $service->expires_at->isFuture())
                ? $service->expires_at->copy()
                : now();
            $newExpiry = $base->addDays(max(0, $days));

            $this->client($panel)->updateUser([
                'uuid'     => $uuid,
                'expireAt' => $this->toIso($newExpiry),
            ]);
            $service->expires_at = $newExpiry;
            $service->save();
            return ProviderResult::success('زمان اضافه اعمال شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function revokeSubscription(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $user = $this->client($panel)->revokeSubscription($uuid);
            $service->remote_sub_id = $user['shortUuid'] ?? $service->remote_sub_id;
            $this->applyLinks($service, $user);
            $service->save();
            return ProviderResult::success('لینک اشتراک بازتولید شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function delete(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $uuid  = $service->remote_uuid;
        if (! $panel || ! $uuid) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->deleteUser($uuid); // idempotent (404 = success)
            return ProviderResult::success('کاربر حذف شد.');
        } catch (RemnawaveException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function findExisting(RemnawaveClient $client, string $username): ?array
    {
        try {
            $user = $client->getUserByUsername($username);
            return empty($user) ? null : $user;
        } catch (RemnawaveException $e) {
            return null; // not found → create
        }
    }

    /** @param array<string,mixed> $user */
    private function fillFromRemote(UserService $service, VpnPanel $panel, array $user): void
    {
        $service->vpn_panel_id    = $panel->id;
        $service->remote_username = $user['username'] ?? $service->remote_username;
        $service->remote_uuid     = $user['uuid'] ?? $service->remote_uuid;
        $service->remote_sub_id   = $user['shortUuid'] ?? $service->remote_sub_id;

        $used = (int) ($user['userTraffic']['usedTrafficBytes'] ?? 0);
        $service->marzban_used_traffic = $used;

        $limit = (int) ($user['trafficLimitBytes'] ?? 0);
        if ($limit > 0) {
            $service->marzban_data_limit = $limit;
        }
        if (! empty($user['expireAt'])) {
            $service->expires_at = Carbon::parse($user['expireAt']);
        }
        if (! empty($user['status'])) {
            $service->remote_status = $user['status'];
        }
        $service->remote_raw     = $user;
        $service->sync_status    = UserService::SYNC_SYNCED;
        $service->sync_error     = null;
        $service->last_synced_at = now();

        $this->applyLinks($service, $user);
    }

    /** @param array<string,mixed> $user */
    private function applyLinks(UserService $service, array $user): void
    {
        $sub = $user['subscriptionUrl'] ?? null;
        if (filled($sub)) {
            $service->subscription_link = $sub;
            $service->config_link       = $sub;
            $service->links_json        = [$sub];
        }
    }

    /** Stable, unique Remnawave username derived from the service (min 3 chars). */
    public function makeUsername(UserService $service): string
    {
        if (filled($service->remote_username)) {
            return $service->remote_username;
        }
        return 'zed-' . ($service->id ?: Str::lower(Str::random(8)));
    }

    /** ISO-8601 date-time with milliseconds + Z, as the spec's expireAt expects. */
    private function toIso(Carbon $when): string
    {
        return $when->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
