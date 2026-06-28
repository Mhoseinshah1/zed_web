<?php

namespace App\Services\Marzban;

use App\Models\VpnPanel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

/**
 * Marzban REST API client.
 *
 * Endpoints discovered from https://github.com/Gozargah/Marzban source:
 *   POST /api/admin/token            — OAuth2 form login → {access_token, token_type}
 *   GET  /api/system                 — system stats (used for connection test)
 *   GET  /api/inbounds               — available inbounds grouped by protocol
 *   POST /api/user                   — create user
 *   GET  /api/user/{username}        — get user
 *   PUT  /api/user/{username}        — modify user
 *   DELETE /api/user/{username}      — delete user
 *   POST /api/user/{username}/reset  — reset traffic usage
 *   POST /api/user/{username}/revoke_sub — revoke subscription (new token)
 *
 * Auth: Bearer token in Authorization header.
 * Token retry: on 401 response, refresh token once then retry.
 */
class MarzbanClient
{
    private const TOKEN_TTL = 3500; // cache ~58 min; Marzban tokens last 24 h
    private const TIMEOUT   = 20;   // seconds

    public function __construct(private VpnPanel $panel) {}

    // ── Authentication ────────────────────────────────────────────────────────

    public function login(): string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->asForm()
            ->post($this->url('/api/admin/token'), [
                'username' => $this->panel->username,
                'password' => $this->panel->password, // auto-decrypted by encrypted cast
            ]);

        $this->assertOk($response, 'POST /api/admin/token');

        $token = $response->json('access_token');

        if (! $token) {
            throw new MarzbanException('Marzban login succeeded but access_token was missing from response.');
        }

        Cache::put($this->tokenKey(), $token, self::TOKEN_TTL);

        return $token;
    }

    // ── Token management (public API) ────────────────────────────────────────

    public function getToken(): string
    {
        return $this->token();
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    // ── Panel-level operations ────────────────────────────────────────────────

    public function testConnection(): array
    {
        $this->login(); // always force a fresh login to validate credentials
        return $this->request('GET', '/api/system');
    }

    public function getSystem(): array
    {
        return $this->request('GET', '/api/system');
    }

    public function getInbounds(): array
    {
        return $this->request('GET', '/api/inbounds');
    }

    // ── User CRUD ─────────────────────────────────────────────────────────────

    public function createUser(array $payload): array
    {
        return $this->request('POST', '/api/user', $payload);
    }

    public function getUser(string $username): array
    {
        return $this->request('GET', '/api/user/' . rawurlencode($username));
    }

    public function updateUser(string $username, array $payload): array
    {
        return $this->request('PUT', '/api/user/' . rawurlencode($username), $payload);
    }

    public function deleteUser(string $username): void
    {
        $this->request('DELETE', '/api/user/' . rawurlencode($username));
    }

    // ── User actions ──────────────────────────────────────────────────────────

    public function resetTraffic(string $username): array
    {
        return $this->request('POST', '/api/user/' . rawurlencode($username) . '/reset');
    }

    public function revokeSubscription(string $username): array
    {
        return $this->request('POST', '/api/user/' . rawurlencode($username) . '/revoke_sub');
    }

    public function getUsage(string $username): array
    {
        return $this->request('GET', '/api/user/' . rawurlencode($username) . '/usage');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Check if a Marzban user exists without throwing on 404.
     */
    public function userExists(string $username): bool
    {
        try {
            $this->getUser($username);
            return true;
        } catch (MarzbanException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Extract the subscription URL from a UserResponse array.
     * The Marzban API returns subscription_url in the user response.
     */
    public function extractSubscriptionLink(array $userResponse): ?string
    {
        return $userResponse['subscription_url'] ?? null;
    }

    /**
     * Normalise a Marzban UserResponse into a flat array with GB-based traffic.
     */
    public function normalizeUserResponse(array $response): array
    {
        $usedBytes  = (int) ($response['used_traffic'] ?? 0);
        $limitBytes = (int) ($response['data_limit'] ?? 0);

        return [
            'username'         => $response['username'] ?? null,
            'status'           => $response['status'] ?? null,
            'used_traffic_gb'  => $usedBytes > 0 ? round($usedBytes / 1_073_741_824, 2) : 0,
            'data_limit_gb'    => $limitBytes > 0 ? round($limitBytes / 1_073_741_824, 2) : 0,
            'expire'           => $response['expire'] ?? null,
            'subscription_url' => $response['subscription_url'] ?? null,
            'links'            => $response['links'] ?? [],
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $data = [], bool $retried = false): array
    {
        $http = Http::timeout(self::TIMEOUT)
            ->withToken($this->token($retried))
            ->asJson()
            ->acceptJson();

        $response = match(strtoupper($method)) {
            'GET'    => $http->get($this->url($path)),
            'POST'   => $http->post($this->url($path), $data ?: (object)[]),
            'PUT'    => $http->put($this->url($path), $data),
            'DELETE' => $http->delete($this->url($path)),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        // Token expired → refresh once and retry
        if ($response->status() === 401 && ! $retried) {
            Cache::forget($this->tokenKey());
            return $this->request($method, $path, $data, true);
        }

        $this->assertOk($response, "{$method} {$path}");

        return $response->json() ?? [];
    }

    private function token(bool $fresh = false): string
    {
        if (! $fresh) {
            $cached = Cache::get($this->tokenKey());
            if ($cached) {
                return $cached;
            }
        }
        return $this->login();
    }

    private function url(string $path): string
    {
        return rtrim($this->panel->base_url, '/') . $path;
    }

    private function tokenKey(): string
    {
        return "marzban_token_panel_{$this->panel->id}";
    }

    private function assertOk(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body   = substr($response->body(), 0, 500);

        throw new MarzbanException(
            "Marzban API error [{$context}]: HTTP {$status} — {$body}",
            $status
        );
    }
}
