<?php

namespace App\Services\VpnPanels;

use App\Contracts\VpnPanelProviderInterface;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\VpnPanels\Sanaei\Sanaei3xUiClient;
use App\Services\VpnPanels\Sanaei\Sanaei3xUiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sanaei / 3X-UI panel provider. Wraps Sanaei3xUiClient and maps results onto
 * the existing UserService fields. Identifies clients by email throughout, and
 * is idempotent (never creates a duplicate client when one already exists).
 */
class Sanaei3xUiProvider implements VpnPanelProviderInterface
{
    private function client(VpnPanel $panel): Sanaei3xUiClient
    {
        return new Sanaei3xUiClient($panel);
    }

    private function panelOf(UserService $service): ?VpnPanel
    {
        return $service->vpn_panel_id ? VpnPanel::find($service->vpn_panel_id) : null;
    }

    public function testConnection(VpnPanel $panel): ProviderResult
    {
        try {
            $this->client($panel)->testConnection();
            return ProviderResult::success('اتصال به پنل سنایی با موفقیت برقرار شد.');
        } catch (Sanaei3xUiException $e) {
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

        $client = $this->client($panel);
        $email  = $client->makeEmail($service);

        try {
            // Idempotency: if a client with this email already exists, sync it
            // instead of creating a duplicate.
            if ($client->clientExists($email)) {
                $this->fillFromRemote($service, $panel, $email);
                $service->save();
                return ProviderResult::success('سرویس موجود همگام‌سازی شد.', ['email' => $email, 'existed' => true]);
            }

            $plan    = $service->plan ?? null;
            $totalGB = (int) (($plan->traffic_gb ?? 0) * 1024 * 1024 * 1024); // bytes (0 = unlimited)
            $days    = (int) ($plan->duration_days ?? 0);
            $expiry  = $days > 0 ? now()->addDays($days) : null;

            $created = $client->createClient($service, [
                'email'      => $email,
                'totalGB'    => $totalGB,
                'expiryTime' => $expiry ? $expiry->getTimestampMs() : 0,
                'enable'     => true,
            ]);

            $service->vpn_panel_id     = $panel->id;
            $service->remote_username  = $email;
            $service->remote_uuid      = $created['id'] ?? null;
            $service->remote_client_id = $created['id'] ?? null;
            $service->remote_sub_id    = $created['subId'] ?? null;
            $service->remote_inbound_id = $created['inboundId'] ?? $panel->default_inbound_id;
            $service->marzban_data_limit = $totalGB ?: null;
            $service->marzban_used_traffic = 0;
            $service->expires_at       = $expiry;
            $service->sync_status      = UserService::SYNC_SYNCED;
            $service->last_synced_at   = now();
            $service->sync_error       = null;

            $this->fillLinks($service, $panel, $email, $created['subId'] ?? null);
            $service->save();

            return ProviderResult::success('سرویس روی پنل سنایی ساخته شد.', ['email' => $email]);
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    // ── Sync ──────────────────────────────────────────────────────────────────

    public function sync(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس برای همگام‌سازی کافی نیست.');
        }

        try {
            $traffic = $this->client($panel)->getClientTraffic($email);
            if (empty($traffic)) {
                $service->sync_status = UserService::SYNC_NOT_FOUND;
                $service->save();
                return ProviderResult::success('کلاینت در پنل یافت نشد؛ اطلاعات محلی نمایش داده می‌شود.', ['status' => 'not_found']);
            }

            $up    = (int) ($traffic['up'] ?? 0);
            $down  = (int) ($traffic['down'] ?? 0);
            $total = (int) ($traffic['total'] ?? 0);
            $exp   = (int) ($traffic['expiryTime'] ?? 0);

            $service->marzban_used_traffic = $up + $down;
            if ($total > 0) {
                $service->marzban_data_limit = $total;
            }
            if ($exp > 0) {
                $service->expires_at = Carbon::createFromTimestampMs($exp);
            }
            $service->remote_status   = array_key_exists('enable', $traffic)
                ? ($traffic['enable'] ? 'active' : 'disabled')
                : $service->remote_status;
            $service->remote_raw      = $traffic;
            $service->sync_status     = UserService::SYNC_SYNCED;
            $service->sync_error      = null;
            $service->last_synced_at  = now();
            $service->save();

            return ProviderResult::success('همگام‌سازی انجام شد.');
        } catch (Sanaei3xUiException $e) {
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
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->updateClient($email, $inbound, $changes);
            return ProviderResult::success('سرویس بروزرسانی شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function enable(UserService $service): ProviderResult
    {
        return $this->setEnabled($service, true);
    }

    public function disable(UserService $service): ProviderResult
    {
        return $this->setEnabled($service, false);
    }

    private function setEnabled(UserService $service, bool $enabled): ProviderResult
    {
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->setClientEnabled($email, $inbound, $enabled);
            $service->remote_status = $enabled ? 'active' : 'disabled';
            $service->save();
            return ProviderResult::success($enabled ? 'سرویس فعال شد.' : 'سرویس غیرفعال شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function resetTraffic(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->resetClientTraffic($email, $inbound);
            $service->marzban_used_traffic = 0;
            $service->save();
            return ProviderResult::success('ترافیک سرویس صفر شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function addTraffic(UserService $service, int $bytes): ProviderResult
    {
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $client  = $this->client($panel);
            $traffic = $client->getClientTraffic($email);
            $currentTotal = (int) ($traffic['total'] ?? $service->marzban_data_limit ?? 0);
            $newTotal = $currentTotal + max(0, $bytes); // add to quota, do NOT reset usage
            $client->updateClient($email, $inbound, ['id' => $service->remote_uuid, 'totalGB' => $newTotal]);
            $service->marzban_data_limit = $newTotal;
            $service->save();
            return ProviderResult::success('حجم اضافه اعمال شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function addTime(UserService $service, int $days): ProviderResult
    {
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            // From current expiry if in the future, else from now.
            $base = ($service->expires_at && $service->expires_at->isFuture())
                ? $service->expires_at->copy()
                : now();
            $newExpiry = $base->addDays(max(0, $days));

            $this->client($panel)->updateClient($email, $inbound, [
                'id'         => $service->remote_uuid,
                'expiryTime' => $newExpiry->getTimestampMs(),
            ]);
            $service->expires_at = $newExpiry;
            $service->save();
            return ProviderResult::success('زمان اضافه اعمال شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function revokeSubscription(UserService $service): ProviderResult
    {
        // Regenerate the subId (new subscription link) without touching traffic
        // or expiry; update the client and refresh the stored link.
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $newSub = Str::lower(Str::random(16));
            $this->client($panel)->updateClient($email, $inbound, ['id' => $service->remote_uuid, 'subId' => $newSub]);
            $service->remote_sub_id = $newSub;
            $this->fillLinks($service, $panel, $email, $newSub);
            $service->save();
            return ProviderResult::success('لینک اشتراک بازتولید شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    public function delete(UserService $service): ProviderResult
    {
        $panel = $this->panelOf($service);
        $email = $service->remote_username;
        $inbound = (int) ($service->remote_inbound_id ?? $panel?->default_inbound_id ?? 0);
        if (! $panel || ! $email) {
            return ProviderResult::failure('اطلاعات سرویس کافی نیست.');
        }
        try {
            $this->client($panel)->deleteClient($email, $inbound);
            return ProviderResult::success('کلاینت حذف شد.');
        } catch (Sanaei3xUiException $e) {
            return ProviderResult::failure($e->getMessage());
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function fillFromRemote(UserService $service, VpnPanel $panel, string $email): void
    {
        try {
            $traffic = $this->client($panel)->getClientTraffic($email);
            $service->remote_username = $email;
            $service->vpn_panel_id    = $panel->id;
            $service->marzban_used_traffic = (int) (($traffic['up'] ?? 0) + ($traffic['down'] ?? 0));
            if (($traffic['total'] ?? 0) > 0) {
                $service->marzban_data_limit = (int) $traffic['total'];
            }
            if (($traffic['expiryTime'] ?? 0) > 0) {
                $service->expires_at = Carbon::createFromTimestampMs((int) $traffic['expiryTime']);
            }
            $service->remote_raw     = $traffic;
            $service->sync_status    = UserService::SYNC_SYNCED;
            $service->last_synced_at = now();
        } catch (Sanaei3xUiException $e) {
            // Keep local data; mark failed.
            $service->sync_status = UserService::SYNC_FAILED;
            $service->sync_error  = $e->getMessage();
        }
    }

    private function fillLinks(UserService $service, VpnPanel $panel, string $email, ?string $subId): void
    {
        try {
            $links = $this->client($panel)->getClientLinks($email);
            $list  = $links['links'] ?? $links['obj'] ?? null;
            if (is_array($list) && $list !== []) {
                $service->links_json = $list;
                $first = is_array($list[0] ?? null) ? ($list[0]['link'] ?? null) : ($list[0] ?? null);
                if ($first) {
                    $service->config_link = $first;
                }
            }
        } catch (Sanaei3xUiException $e) {
            // links are optional
        }

        // Subscription link: prefer explicit base+path+subId when configured.
        if ($subId && filled($panel->subscription_base_url)) {
            $base = rtrim((string) $panel->subscription_base_url, '/');
            $path = trim((string) $panel->subscription_path, '/');
            $service->subscription_link = $base . ($path ? '/' . $path : '') . '/' . $subId;
        }
    }
}
