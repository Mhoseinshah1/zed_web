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

    public int $tries   = 3;
    public array $backoff = [30, 60, 120]; // seconds between retries

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

        $client = new MarzbanClient($panel);

        // Generate or reuse the remote username
        $username = $service->remote_username ?? $this->generateUsername($service);

        try {
            // Idempotent: if user already exists on Marzban, update instead of create
            $exists = false;
            try {
                $client->getUser($username);
                $exists = true;
            } catch (MarzbanException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }

            $payload = $this->buildPayload($service, $panel);

            if ($exists) {
                $marzbanUser = $client->updateUser($username, $payload);
                $action      = 'marzban_update_user';
            } else {
                $marzbanUser = $client->createUser(array_merge(['username' => $username], $payload));
                $action      = 'marzban_create_user';
            }

            $normalized       = $client->normalizeUserResponse($marzbanUser);
            $subscriptionLink = $client->extractSubscriptionLink($marzbanUser);

            $startsAt  = $service->starts_at ?? now();
            $expiresAt = $service->expires_at
                ?? ($service->duration_days ? $startsAt->copy()->addDays($service->duration_days) : null);

            $service->update([
                'status'            => UserService::STATUS_ACTIVE,
                'provision_status'  => UserService::PROVISION_PROVISIONED,
                'vpn_panel_id'      => $panel->id,
                'remote_username'   => $username,
                'subscription_link' => $subscriptionLink,
                'starts_at'         => $startsAt,
                'activated_at'      => $service->activated_at ?? now(),
                'expires_at'        => $expiresAt,
                'last_synced_at'    => now(),
            ]);

            VpnServiceProvisionLog::create([
                'user_service_id'  => $service->id,
                'vpn_panel_id'     => $panel->id,
                'action'           => $action,
                'status'           => 'success',
                'message'          => "Marzban user '{$username}' " . ($exists ? 'updated' : 'created') . " on panel '{$panel->name}'.",
                'request_payload'  => ['username' => $username, 'data_limit' => $payload['data_limit'] ?? null],
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

    private function generateUsername(UserService $service): string
    {
        // zpx_{user_id}_{service_id}_{random5} — max 32 chars, safe for Marzban username validation
        $random = strtolower(Str::random(5));
        $candidate = "zpx_{$service->user_id}_{$service->id}_{$random}";

        // Marzban allows: ^[a-zA-Z0-9@_.+-]+$ with max 32 chars
        return substr($candidate, 0, 32);
    }

    private function buildPayload(UserService $service, VpnPanel $panel): array
    {
        // Derive proxies + inbound tags from VpnInbound records on this panel
        $inbounds = $panel->inbounds()->where('is_active', true)->get();

        $proxies        = [];
        $inboundMapping = [];

        if ($inbounds->isNotEmpty()) {
            foreach ($inbounds as $inbound) {
                $proxies[$inbound->protocol]          = new \stdClass(); // {} = auto-generate settings
                $inboundMapping[$inbound->protocol][] = $inbound->name; // name = Marzban inbound tag
            }
        } else {
            // No inbounds configured — default to VLESS (Marzban will use all available VLESS inbounds)
            $proxies = ['vless' => new \stdClass()];
        }

        // data_limit: 0 = unlimited in Marzban; otherwise convert GB → bytes
        $dataLimitBytes = ($service->traffic_total_gb && $service->traffic_total_gb > 0)
            ? (int) ($service->traffic_total_gb * 1_073_741_824)
            : 0;

        // expire: Marzban expects a Unix timestamp integer; null/omitted = no expiry
        $startsAt  = $service->starts_at ?? now();
        $expiresAt = $service->expires_at
            ?? ($service->duration_days ? $startsAt->copy()->addDays($service->duration_days) : null);

        $payload = [
            'proxies'                    => $proxies,
            'data_limit'                 => $dataLimitBytes,
            'data_limit_reset_strategy'  => 'no_reset',
            'status'                     => 'active',
            'note'                       => "ZedProxy {$service->service_number}",
        ];

        if ($expiresAt) {
            $payload['expire'] = $expiresAt->timestamp;
        }

        if ($inboundMapping) {
            $payload['inbounds'] = $inboundMapping;
        }

        return $payload;
    }
}
