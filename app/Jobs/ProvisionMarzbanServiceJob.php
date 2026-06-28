<?php

namespace App\Jobs;

use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\Marzban\MarzbanClient;
use App\Services\Marzban\MarzbanException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProvisionMarzbanServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries  = 3;
    public array $backoff = [30, 60, 120]; // seconds between retries

    private string $resolvedAction = 'marzban_create_user';

    public function __construct(
        private int $serviceId,
        private int $panelId,
    ) {}

    public function handle(): void
    {
        $service = UserService::find($this->serviceId);
        $panel   = VpnPanel::find($this->panelId);

        if (! $service || ! $panel) {
            return; // deleted before job ran — safe to discard
        }

        $client   = new MarzbanClient($panel);
        $username = $service->remote_username ?? $this->generateUsername($service);

        try {
            $marzbanUser = $this->fetchOrCreateUser($client, $username, $service, $panel);

            $normalized       = $client->normalizeUserResponse($marzbanUser);
            $subscriptionLink = $client->extractSubscriptionLink($marzbanUser);
            $configLink       = $marzbanUser['links'][0] ?? null;

            $startsAt  = $service->starts_at ?? now();
            $expiresAt = $service->expires_at
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
            ]);

            VpnServiceProvisionLog::create([
                'user_service_id'  => $service->id,
                'vpn_panel_id'     => $panel->id,
                'action'           => $this->resolvedAction,
                'status'           => 'success',
                'message'          => "Marzban user '{$username}' provisioned on panel '{$panel->name}'.",
                'request_payload'  => ['username' => $username, 'data_limit' => $this->buildPayload($service)['data_limit'] ?? null],
                'response_payload' => [
                    'status'           => $normalized['status'],
                    'subscription_url' => $subscriptionLink ? 'set' : 'not returned',
                ],
            ]);

        } catch (\Throwable $e) {
            $service->update([
                'provision_status' => UserService::PROVISION_FAILED,
                'admin_notes'      => 'Marzban provisioning failed: ' . $e->getMessage(),
            ]);

            VpnServiceProvisionLog::create([
                'user_service_id' => $service->id,
                'vpn_panel_id'    => $panel->id,
                'action'          => 'marzban_create_user',
                'status'          => 'failed',
                'message'         => $e->getMessage(),
            ]);

            throw $e; // let queue retry
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function fetchOrCreateUser(MarzbanClient $client, string $username, UserService $service, VpnPanel $panel): array
    {
        // If remote_username was already set, try GET first (idempotent retry)
        if ($service->remote_username) {
            try {
                $existing = $client->getUser($username);
                VpnServiceProvisionLog::create([
                    'user_service_id' => $service->id,
                    'vpn_panel_id'    => $panel->id,
                    'action'          => 'marzban_get_user',
                    'status'          => 'success',
                    'message'         => "Found existing Marzban user '{$username}'. Updating.",
                ]);
                $this->resolvedAction = 'marzban_update_user';
                return $client->updateUser($username, $this->buildPayload($service));
            } catch (MarzbanException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        // Attempt to create the user
        try {
            return $client->createUser(array_merge(['username' => $username], $this->buildPayload($service)));
        } catch (MarzbanException $e) {
            // 409 Conflict — user already exists on panel but wasn't linked locally
            if ($e->getCode() === 409) {
                VpnServiceProvisionLog::create([
                    'user_service_id' => $service->id,
                    'vpn_panel_id'    => $panel->id,
                    'action'          => 'marzban_create_user',
                    'status'          => 'skipped',
                    'message'         => "Username '{$username}' already exists on Marzban (409). Fetching existing user.",
                ]);
                $client->getUser($username);
                $this->resolvedAction = 'marzban_update_user';
                return $client->updateUser($username, $this->buildPayload($service));
            }
            throw $e;
        }
    }

    private function generateUsername(UserService $service): string
    {
        $random    = strtolower(Str::random(5));
        $candidate = "zpx_{$service->user_id}_{$service->id}_{$random}";
        return substr($candidate, 0, 32);
    }

    private function buildPayload(UserService $service): array
    {
        // Always use vless with auto-generated credentials.
        // Do NOT send inbounds — Marzban auto-assigns all available inbounds.
        $proxies = ['vless' => new \stdClass()];

        // data_limit: 0 = unlimited in Marzban; otherwise convert GB → bytes
        $dataLimitBytes = ($service->traffic_total_gb && $service->traffic_total_gb > 0)
            ? (int) ($service->traffic_total_gb * 1_073_741_824)
            : 0;

        $startsAt  = $service->starts_at ?? now();
        $expiresAt = $service->expires_at
            ?? ($service->duration_days ? $startsAt->copy()->addDays($service->duration_days) : null);

        $payload = [
            'proxies'                   => $proxies,
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
}
