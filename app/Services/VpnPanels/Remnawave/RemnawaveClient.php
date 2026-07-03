<?php

namespace App\Services\VpnPanels\Remnawave;

use App\Models\VpnPanel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Remnawave (v2.8.0) REST API client.
 *
 * AUTH: Authorization: Bearer {JWT}. The token is created in the panel under
 * Remnawave Settings → API Tokens and stored encrypted in VpnPanel::api_token.
 *
 * Remnawave is UUID-centric: POST /api/users returns a `uuid` that every later
 * operation (update / delete / enable / disable / reset) requires. Responses
 * are wrapped in a {"response": {...}} envelope.
 *
 * Endpoints (verified against api-1.json, OpenAPI 3.0.0, "Remnawave API v2.8.0"):
 *   GET    /api/users/tags                            (auth probe)
 *   POST   /api/users                                 create   → response.uuid, subscriptionUrl
 *   PATCH  /api/users                                 update   (body has uuid)
 *   GET    /api/users/{uuid}                          fetch
 *   DELETE /api/users/{uuid}                          delete   (response.isDeleted)
 *   POST   /api/users/{uuid}/actions/enable
 *   POST   /api/users/{uuid}/actions/disable
 *   POST   /api/users/{uuid}/actions/reset-traffic
 *   POST   /api/users/{uuid}/actions/revoke           regenerate subscription
 *
 * UNITS (per spec): trafficLimitBytes = bytes; expireAt = ISO-8601 date-time.
 *
 * SECURITY: never logs the token, headers, or subscription secrets.
 */
class RemnawaveClient
{
    public function __construct(private VpnPanel $panel) {}

    // ── URL building ──────────────────────────────────────────────────────────

    /** base_url + endpoint with safe slash normalisation. */
    public function url(string $endpoint): string
    {
        return rtrim(trim((string) $this->panel->base_url), '/') . '/' . ltrim($endpoint, '/');
    }

    // ── HTTP plumbing ─────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        $request = Http::timeout(max(1, (int) ($this->panel->timeout_seconds ?: 15)))
            ->acceptJson()
            ->withOptions(['verify' => (bool) ($this->panel->verify_ssl ?? true)]);

        $token = (string) $this->panel->api_token;
        if ($token !== '') {
            $request->withToken($token); // Authorization: Bearer {token}
        }

        return $request;
    }

    /**
     * Perform a request and return the decoded JSON array. Remnawave wraps
     * payloads in {"response": {...}}.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = ($method === 'get' || $method === 'delete')
                ? $this->http()->{$method}($this->url($endpoint))
                : $this->http()->{$method}($this->url($endpoint), $data);
        } catch (\Throwable $e) {
            throw new RemnawaveException('ارتباط با پنل Remnawave برقرار نشد.');
        }

        return $this->parse($response);
    }

    /** @return array<string,mixed> */
    private function parse(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RemnawaveException('احراز هویت با پنل Remnawave ناموفق بود.');
        }
        if ($response->status() === 404) {
            throw new RemnawaveException('کاربر موردنظر در پنل Remnawave یافت نشد.');
        }
        if ($response->failed()) {
            // 400 BadRequestError / 500 InternalServerError shapes — surface a
            // safe message only (never echo field-level internals to the user).
            throw new RemnawaveException('عملیات در پنل Remnawave ناموفق بود.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RemnawaveException('پاسخ پنل Remnawave نامعتبر بود.');
        }

        return $json;
    }

    /**
     * Unwrap the {"response": {...}} envelope.
     *
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function unwrap(array $json): array
    {
        $obj = $json['response'] ?? $json;
        return is_array($obj) ? $obj : [];
    }

    // ── Connection test ───────────────────────────────────────────────────────

    public function testConnection(): bool
    {
        $this->request('get', '/api/users/tags');
        return true;
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    /**
     * Create a user. Returns the user object (uuid, shortUuid, subscriptionUrl, …).
     *
     * @param  array<string,mixed>  $payload  exact CreateUserRequestDto fields
     * @return array<string,mixed>
     */
    public function createUser(array $payload): array
    {
        return $this->unwrap($this->request('post', '/api/users', $payload));
    }

    /**
     * Update a user. The body must carry the target uuid.
     *
     * @param  array<string,mixed>  $payload  uuid + changed fields
     * @return array<string,mixed>
     */
    public function updateUser(array $payload): array
    {
        return $this->unwrap($this->request('patch', '/api/users', $payload));
    }

    /** @return array<string,mixed> */
    public function getUser(string $uuid): array
    {
        return $this->unwrap($this->request('get', '/api/users/' . rawurlencode($uuid)));
    }

    /** @return array<string,mixed> */
    public function getUserByUsername(string $username): array
    {
        return $this->unwrap($this->request('get', '/api/users/by-username/' . rawurlencode($username)));
    }

    /**
     * Delete a user. Idempotent: a "not found" is treated as already-deleted.
     */
    public function deleteUser(string $uuid): bool
    {
        try {
            $this->request('delete', '/api/users/' . rawurlencode($uuid));
        } catch (RemnawaveException $e) {
            // Already gone → idempotent success.
        }
        return true;
    }

    public function enableUser(string $uuid): bool
    {
        $this->request('post', '/api/users/' . rawurlencode($uuid) . '/actions/enable');
        return true;
    }

    public function disableUser(string $uuid): bool
    {
        $this->request('post', '/api/users/' . rawurlencode($uuid) . '/actions/disable');
        return true;
    }

    public function resetUserTraffic(string $uuid): bool
    {
        $this->request('post', '/api/users/' . rawurlencode($uuid) . '/actions/reset-traffic');
        return true;
    }

    /** Revoke (regenerate) the subscription; returns the refreshed user object. */
    public function revokeSubscription(string $uuid): array
    {
        return $this->unwrap($this->request('post', '/api/users/' . rawurlencode($uuid) . '/actions/revoke'));
    }
}
