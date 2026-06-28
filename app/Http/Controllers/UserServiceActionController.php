<?php

namespace App\Http\Controllers;

use App\Models\SiteText;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\Marzban\MarzbanClient;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class UserServiceActionController extends Controller
{
    // ── Ownership guard ───────────────────────────────────────────────────────

    private function authorizeService(UserService $service): void
    {
        abort_if($service->user_id !== auth()->id(), 403);
    }

    // ── Panel resolver ────────────────────────────────────────────────────────

    private function resolvePanel(UserService $service): VpnPanel
    {
        return $service->vpnPanel
            ?? VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                ->where('is_active', true)
                ->where('is_default', true)
                ->firstOrFail();
    }

    // ── Setting helper ────────────────────────────────────────────────────────

    private function settingEnabled(string $key, bool $default = false): bool
    {
        $val = SiteText::get($key, $default ? 'true' : 'false');
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }

    // ── Guard: service must be active with a remote_username ─────────────────

    private function guardRemote(UserService $service): ?RedirectResponse
    {
        if (! filled($service->remote_username) || $service->status !== UserService::STATUS_ACTIVE) {
            return back()->with('error', 'این عملیات فقط برای سرویس‌های فعال قابل انجام است.');
        }
        return null;
    }

    // ── Provision log helper ─────────────────────────────────────────────────

    private function log(UserService $service, ?int $panelId, string $action, string $status, string $message, array $payload = []): void
    {
        VpnServiceProvisionLog::create([
            'user_service_id'  => $service->id,
            'vpn_panel_id'     => $panelId,
            'action'           => $action,
            'status'           => $status,
            'message'          => $message,
            'response_payload' => $payload ?: null,
        ]);
    }

    // ── Apply normalised Marzban response to local service ───────────────────

    private function applyNormalized(UserService $service, array $marzbanUser, MarzbanClient $client): void
    {
        $normalized = $client->normalizeUserResponse($marzbanUser);
        $subLink    = $client->extractSubscriptionLink($marzbanUser);

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

        // Mirror Marzban status to local status (active ↔ disabled only)
        if ($normalized['status'] === 'active' && $service->status === UserService::STATUS_DISABLED) {
            $updates['status'] = UserService::STATUS_ACTIVE;
        } elseif ($normalized['status'] === 'disabled' && $service->status === UserService::STATUS_ACTIVE) {
            $updates['status'] = UserService::STATUS_DISABLED;
        }

        $service->update($updates);
    }

    // ── Sync ─────────────────────────────────────────────────────────────────

    public function sync(UserService $service): RedirectResponse
    {
        $this->authorizeService($service);

        if (! $this->settingEnabled('services.allow_user_sync_service', true)) {
            return back()->with('error', 'این عملیات در حال حاضر غیرفعال است.');
        }

        if ($guard = $this->guardRemote($service)) {
            return $guard;
        }

        try {
            $panel       = $this->resolvePanel($service);
            $client      = new MarzbanClient($panel);
            $marzbanUser = $client->getUser($service->remote_username);
            $normalized  = $client->normalizeUserResponse($marzbanUser);

            $this->applyNormalized($service, $marzbanUser, $client);

            $this->log($service, $panel->id, 'user_marzban_sync', 'success',
                "User synced. Status: {$normalized['status']}. Used: {$normalized['used_traffic_gb']} GB.",
                ['status' => $normalized['status'], 'used_traffic_gb' => $normalized['used_traffic_gb']]);

            return back()->with('success', 'وضعیت سرویس بروزرسانی شد.');

        } catch (\Throwable $e) {
            $this->log($service, $service->vpn_panel_id, 'user_marzban_sync', 'failed', $e->getMessage());
            return back()->with('error', 'بروزرسانی سرویس انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
        }
    }

    // ── Revoke subscription ───────────────────────────────────────────────────

    public function revokeSubscription(Request $request, UserService $service): RedirectResponse
    {
        $this->authorizeService($service);

        if (! $this->settingEnabled('services.allow_user_revoke_subscription', true)) {
            return back()->with('error', 'این عملیات در حال حاضر غیرفعال است.');
        }

        if ($guard = $this->guardRemote($service)) {
            return $guard;
        }

        // Rate limit: once per 10 minutes per service
        $rateLimitKey = 'revoke-sub:' . $service->id . ':' . auth()->id();
        $revokeIntervalSeconds = (int) SiteText::get('services.revoke_subscription_cooldown_seconds', '600');

        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = max(1, (int) ceil($seconds / 60));
            return back()->with('error', "برای تغییر مجدد لینک اشتراک کمی بعد دوباره تلاش کنید. ({$minutes} دقیقه دیگر)");
        }

        try {
            $panel       = $this->resolvePanel($service);
            $client      = new MarzbanClient($panel);
            $marzbanUser = $client->revokeSubscription($service->remote_username);
            $newSubLink  = $client->extractSubscriptionLink($marzbanUser);

            $service->update([
                'subscription_link' => $newSubLink,
                'config_link'       => $marzbanUser['links'][0] ?? $service->config_link,
                'last_synced_at'    => now(),
            ]);

            RateLimiter::hit($rateLimitKey, $revokeIntervalSeconds);

            $this->log($service, $panel->id, 'user_marzban_revoke_subscription', 'success',
                "Subscription revoked for '{$service->remote_username}'. New link saved.",
                ['new_sub_link_present' => filled($newSubLink)]);

            return back()->with('success', 'لینک اشتراک شما با موفقیت تغییر کرد.');

        } catch (\Throwable $e) {
            $this->log($service, $service->vpn_panel_id, 'user_marzban_revoke_subscription', 'failed', $e->getMessage());
            return back()->with('error', 'تغییر لینک اشتراک انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
        }
    }

    // ── Reset traffic ─────────────────────────────────────────────────────────

    public function resetTraffic(UserService $service): RedirectResponse
    {
        $this->authorizeService($service);

        if (! $this->settingEnabled('services.allow_user_reset_traffic', false)) {
            return back()->with('error', 'این عملیات در حال حاضر غیرفعال است.');
        }

        if ($guard = $this->guardRemote($service)) {
            return $guard;
        }

        try {
            $panel  = $this->resolvePanel($service);
            $client = new MarzbanClient($panel);
            $client->resetTraffic($service->remote_username);

            $service->update([
                'traffic_used_gb' => 0,
                'last_synced_at'  => now(),
            ]);

            $this->log($service, $panel->id, 'user_marzban_reset_traffic', 'success',
                "Traffic reset for '{$service->remote_username}' by user.");

            return back()->with('success', 'مصرف ترافیک سرویس ریست شد.');

        } catch (\Throwable $e) {
            $this->log($service, $service->vpn_panel_id, 'user_marzban_reset_traffic', 'failed', $e->getMessage());
            return back()->with('error', 'ریست ترافیک انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
        }
    }

    // ── Disable service ───────────────────────────────────────────────────────

    public function disable(UserService $service): RedirectResponse
    {
        $this->authorizeService($service);

        if (! $this->settingEnabled('services.allow_user_disable_service', false)) {
            return back()->with('error', 'این عملیات در حال حاضر غیرفعال است.');
        }

        if (! filled($service->remote_username) || $service->status !== UserService::STATUS_ACTIVE) {
            return back()->with('error', 'این عملیات فقط برای سرویس‌های فعال قابل انجام است.');
        }

        try {
            $panel  = $this->resolvePanel($service);
            $client = new MarzbanClient($panel);
            $client->updateUser($service->remote_username, ['status' => 'disabled']);

            $service->update([
                'status'         => UserService::STATUS_DISABLED,
                'last_synced_at' => now(),
            ]);

            $this->log($service, $panel->id, 'user_marzban_disable', 'success',
                "Service disabled by user. Remote: '{$service->remote_username}'.");

            return back()->with('success', 'سرویس غیرفعال شد.');

        } catch (\Throwable $e) {
            $this->log($service, $service->vpn_panel_id, 'user_marzban_disable', 'failed', $e->getMessage());
            return back()->with('error', 'غیرفعال کردن سرویس انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
        }
    }

    // ── Enable service ────────────────────────────────────────────────────────

    public function enable(UserService $service): RedirectResponse
    {
        $this->authorizeService($service);

        if (! $this->settingEnabled('services.allow_user_enable_service', false)) {
            return back()->with('error', 'این عملیات در حال حاضر غیرفعال است.');
        }

        if (! filled($service->remote_username) || $service->status !== UserService::STATUS_DISABLED) {
            return back()->with('error', 'این عملیات فقط برای سرویس‌های غیرفعال قابل انجام است.');
        }

        try {
            $panel  = $this->resolvePanel($service);
            $client = new MarzbanClient($panel);
            $client->updateUser($service->remote_username, ['status' => 'active']);

            $service->update([
                'status'         => UserService::STATUS_ACTIVE,
                'last_synced_at' => now(),
            ]);

            $this->log($service, $panel->id, 'user_marzban_enable', 'success',
                "Service enabled by user. Remote: '{$service->remote_username}'.");

            return back()->with('success', 'سرویس فعال شد.');

        } catch (\Throwable $e) {
            $this->log($service, $service->vpn_panel_id, 'user_marzban_enable', 'failed', $e->getMessage());
            return back()->with('error', 'فعال کردن سرویس انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
        }
    }
}
