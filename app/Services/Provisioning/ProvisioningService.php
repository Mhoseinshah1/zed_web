<?php

namespace App\Services\Provisioning;

use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\Marzban\MarzbanClient;
use App\Services\Marzban\MarzbanException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProvisioningService
{
    /**
     * Provision a Marzban VPN account for a paid order.
     *
     * Safe to call multiple times — returns existing active service without
     * creating duplicates.  Pass $forceRetry = true from admin retry actions.
     *
     * @throws \RuntimeException with a safe Persian message on failure
     */
    public function provisionOrder(Order $order, bool $forceRetry = false): UserService
    {
        $order = $order->fresh(['service']);

        if ($order->payment_status !== Order::PAYMENT_PAID) {
            throw new \RuntimeException('سفارش پرداخت نشده است.');
        }

        $service = $order->service;

        // Already fully provisioned — idempotent return
        if ($service && $service->status === UserService::STATUS_ACTIVE) {
            return $service;
        }

        // Get or create the UserService placeholder
        if (! $service) {
            $service = UserService::create([
                'user_id'          => $order->user_id,
                'order_id'         => $order->id,
                'plan_id'          => $order->plan_id,
                'plan_name'        => $order->plan_name,
                'traffic_total_gb' => $order->traffic_gb,
                'traffic_used_gb'  => 0,
                'duration_days'    => $order->duration_days,
                'status'           => UserService::STATUS_PENDING_PROVISION,
                'provision_status' => UserService::PROVISION_PENDING,
            ]);
        }

        // Resolve panel: prefer one already linked to the service, then the
        // default Marzban panel, then any active default panel (e.g. 3X-UI).
        $panel = ($service->vpn_panel_id ? VpnPanel::find($service->vpn_panel_id) : null)
            ?? VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                   ->where('is_active', true)
                   ->where('is_default', true)
                   ->first()
            ?? VpnPanel::where('is_active', true)->where('is_default', true)->first();

        if (! $panel) {
            throw new \RuntimeException('هیچ پنل فعال پیش‌فرضی پیدا نشد.');
        }

        // Transition order to provisioning
        $order->update(['status' => Order::STATUS_PROVISIONING]);

        // Record this attempt
        $attemptNumber = ProvisioningAttempt::where('order_id', $order->id)->count() + 1;
        $attempt = ProvisioningAttempt::create([
            'order_id'        => $order->id,
            'user_id'         => $order->user_id,
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $panel->id,
            'status'          => ProvisioningAttempt::STATUS_PROCESSING,
            'attempt_number'  => $attemptNumber,
            'started_at'      => now(),
        ]);

        try {
            // ── Sanaei / 3X-UI panels: provision via the 3X-UI provider ──
            if ($panel->isSanaei()) {
                $service->vpn_panel_id = $panel->id;
                $service->save();

                $result = (new \App\Services\VpnPanels\Sanaei3xUiProvider())->provision($service->fresh());
                if (! $result->ok) {
                    throw new \RuntimeException($result->message);
                }

                $service->refresh();
                $startsAt  = $service->starts_at ?? now();
                $expiresAt = $service->expires_at
                    ?? ($service->duration_days ? $startsAt->copy()->addDays($service->duration_days) : null);
                $service->update([
                    'status'           => UserService::STATUS_ACTIVE,
                    'provision_status' => UserService::PROVISION_PROVISIONED,
                    'starts_at'        => $startsAt,
                    'activated_at'     => $service->activated_at ?? now(),
                    'expires_at'       => $expiresAt,
                ]);

                $attempt->update([
                    'status'           => ProvisioningAttempt::STATUS_SUCCESS,
                    'response_payload' => ['username' => $service->remote_username, 'panel_type' => $panel->type],
                    'finished_at'      => now(),
                ]);
                $order->update(['status' => Order::STATUS_COMPLETED, 'completed_at' => now()]);

                if ($order->user) {
                    app(\App\Services\Notifications\NotificationService::class)->notify(
                        \App\Models\Notification::TYPE_NEW_SERVICE_CREATED,
                        $order->user,
                        [
                            'user_name'    => $order->user->name ?? $order->user->username,
                            'service_name' => $service->plan_name ?? $service->service_number,
                            'order_id'     => $order->order_number,
                        ],
                        'new_service_created:service:' . $service->id,
                    );
                }

                app(\App\Services\Telegram\TelegramAdminNotifier::class)->event('service_provisioned', [
                    'user'    => $order->user?->name ?? $order->user?->username ?? '—',
                    'service' => $service->plan_name ?? $service->service_number,
                    'order'   => $order->order_number,
                ], $service);

                return $service->fresh();
            }

            $client   = new MarzbanClient($panel);
            $username = $service->remote_username ?? $this->generateUsername($service);
            $payload  = array_merge(['username' => $username], $this->buildPayload($service));

            // Sanitized payload stored in attempt (no tokens/passwords)
            $attempt->update(['request_payload' => $this->sanitizePayload($payload)]);

            ['user' => $marzbanUser, 'logs' => $provisionLogs] = $this->fetchOrCreateUser($client, $username, $service);

            $normalized       = $client->normalizeUserResponse($marzbanUser);
            $subscriptionLink = $client->extractSubscriptionLink($marzbanUser);
            $configLink       = $marzbanUser['links'][0] ?? null;
            $startsAt         = $service->starts_at ?? now();
            $expiresAt        = $service->expires_at
                ?? ($service->duration_days ? $startsAt->copy()->addDays($service->duration_days) : null);

            $service->update([
                'status'            => UserService::STATUS_ACTIVE,
                'provision_status'  => UserService::PROVISION_PROVISIONED,
                'vpn_panel_id'      => $panel->id,
                'remote_username'   => $username,
                'subscription_link' => $subscriptionLink,
                'config_link'       => $configLink,
                'traffic_used_gb'   => $normalized['used_traffic_gb'] ?? 0,
                'starts_at'         => $startsAt,
                'activated_at'      => $service->activated_at ?? now(),
                'expires_at'        => $expiresAt,
                'last_synced_at'    => now(),
                'sync_status'       => UserService::SYNC_SYNCED,
            ]);

            $attempt->update([
                'status'           => ProvisioningAttempt::STATUS_SUCCESS,
                'response_payload' => [
                    'status'   => $normalized['status'] ?? 'active',
                    'username' => $username,
                ],
                'finished_at' => now(),
            ]);

            foreach ($provisionLogs as $logEntry) {
                VpnServiceProvisionLog::create([
                    'user_service_id'  => $service->id,
                    'vpn_panel_id'     => $panel->id,
                    'action'           => $logEntry['action'],
                    'status'           => $logEntry['status'],
                ]);
            }

            $order->update([
                'status'       => Order::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            Log::info('ProvisioningService: service provisioned', [
                'order_id'   => $order->id,
                'service_id' => $service->id,
                'username'   => $username,
                'panel'      => $panel->name,
            ]);

            // Notify the user their service is now active. Idempotent per service.
            if ($order->user) {
                app(\App\Services\Notifications\NotificationService::class)->notify(
                    \App\Models\Notification::TYPE_NEW_SERVICE_CREATED,
                    $order->user,
                    [
                        'user_name'    => $order->user->name ?? $order->user->username,
                        'service_name' => $service->plan_name ?? $service->service_number,
                        'order_id'     => $order->order_number,
                    ],
                    'new_service_created:service:' . $service->id,
                );
            }

            return $service->fresh();

        } catch (\Throwable $e) {
            $safeMessage = $this->sanitizeError($e->getMessage());

            $attempt->update([
                'status'           => ProvisioningAttempt::STATUS_FAILED,
                'error_message'    => $safeMessage,
                'response_payload' => $this->extractSafeResponsePayload($e),
                'finished_at'      => now(),
            ]);

            $service->update(['provision_status' => UserService::PROVISION_FAILED]);

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'vpn_panel_id'    => $panel->id,
                'action'          => 'marzban_create_user',
                'status'          => 'failed',
                'message'         => $safeMessage,
            ]);

            $order->update(['status' => Order::STATUS_PROVISIONING_FAILED]);

            Log::error('ProvisioningService: provisioning failed', [
                'order_id'   => $order->id,
                'service_id' => $service->id,
                'attempt'    => $attemptNumber,
                'error'      => $safeMessage,
            ]);

            // System/admin warning — order paid but provisioning failed.
            app(\App\Services\Notifications\NotificationService::class)->notifyAdmins(
                \App\Models\Notification::TYPE_PROVISIONING_FAILED,
                [
                    'user_name'  => $order->user?->name ?? $order->user?->username ?? '—',
                    'order_id'   => $order->order_number,
                    'service_id' => $service->id,
                    'error'      => $safeMessage,
                ],
                'provisioning_failed:order:' . $order->id,
            );

            app(\App\Services\Telegram\TelegramAdminNotifier::class)->event('service_failed', [
                'user'  => $order->user?->name ?? $order->user?->username ?? '—',
                'order' => $order->order_number,
                'error' => $safeMessage,
            ], $order);

            throw new \RuntimeException('ساخت سرویس در Marzban با خطا مواجه شد: ' . $safeMessage);
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Returns ['user' => array, 'logs' => [['action' => string, 'status' => string], ...]]
     */
    private function fetchOrCreateUser(MarzbanClient $client, string $username, UserService $service): array
    {
        // If username was already assigned, try GET first (idempotent retry)
        if ($service->remote_username) {
            try {
                $client->getUser($username);
                $user = $client->updateUser($username, $this->buildPayload($service));
                return ['user' => $user, 'logs' => [['action' => 'marzban_update_user', 'status' => 'success']]];
            } catch (MarzbanException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        try {
            $user = $client->createUser(array_merge(['username' => $username], $this->buildPayload($service)));
            return ['user' => $user, 'logs' => [['action' => 'marzban_create_user', 'status' => 'success']]];
        } catch (MarzbanException $e) {
            // 409 Conflict — username exists on panel but wasn't linked locally
            if ($e->getCode() === 409) {
                $client->getUser($username);
                $user = $client->updateUser($username, $this->buildPayload($service));
                return ['user' => $user, 'logs' => [
                    ['action' => 'marzban_create_user', 'status' => 'skipped'],
                    ['action' => 'marzban_update_user', 'status' => 'success'],
                ]];
            }
            throw $e;
        }
    }

    private function generateUsername(UserService $service): string
    {
        $candidate = 'zpx_' . $service->user_id . '_' . $service->id . '_' . strtolower(Str::random(5));
        return substr($candidate, 0, 32);
    }

    private function buildPayload(UserService $service): array
    {
        $dataLimitBytes = ($service->traffic_total_gb && $service->traffic_total_gb > 0)
            ? (int) ($service->traffic_total_gb * 1_073_741_824)
            : 0;

        $startsAt  = $service->starts_at ?? now();
        $expiresAt = $service->expires_at
            ?? ($service->duration_days ? $startsAt->copy()->addDays($service->duration_days) : null);

        $payload = [
            'proxies'                   => ['vless' => new \stdClass()],
            'data_limit'                => $dataLimitBytes,
            'data_limit_reset_strategy' => 'no_reset',
            'status'                    => 'active',
            'note'                      => "ZedProxy {$service->service_number}",
        ];

        if ($expiresAt) {
            $payload['expire'] = $expiresAt->timestamp;
        }

        return $payload;
    }

    private function sanitizePayload(array $payload): array
    {
        return collect($payload)->except(['api_key', 'password', 'token', 'secret', 'ipn_secret'])->all();
    }

    private function sanitizeError(string $message): string
    {
        // Strip any credential-looking patterns
        $patterns = [
            '/Bearer\s+\S+/i',
            '/api[_-]?key[=:]\s*\S+/i',
            '/password[=:]\s*\S+/i',
            '/token[=:]\s*\S+/i',
        ];
        return preg_replace($patterns, '[REDACTED]', $message) ?? $message;
    }

    private function extractSafeResponsePayload(\Throwable $e): array
    {
        if ($e instanceof MarzbanException) {
            return [
                'http_status'   => $e->getCode(),
                'error_type'    => 'marzban_api_error',
                'error_message' => $this->sanitizeError($e->getMessage()),
            ];
        }

        return [
            'error_type'    => get_class($e),
            'error_message' => $this->sanitizeError($e->getMessage()),
        ];
    }
}
