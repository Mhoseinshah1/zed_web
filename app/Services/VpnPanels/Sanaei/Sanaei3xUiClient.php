<?php

namespace App\Services\VpnPanels\Sanaei;

use App\Models\UserService;
use App\Models\VpnPanel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sanaei / 3X-UI panel REST API client.
 *
 * AUTH (official API only — no browser automation, no HTML scraping):
 *   • api_token  → Authorization: Bearer {token}  (preferred)
 *   • api_login  → POST {panel}/login (username/password) → session cookie
 *
 * Endpoints (verified from the panel's API docs):
 *   POST   /login
 *   GET    /panel/api/inbounds/list
 *   GET    /panel/api/inbounds/get/{id}
 *   POST   /panel/api/clients/add
 *   GET    /panel/api/clients/get/{email}
 *   POST   /panel/api/clients/update/{email}
 *   POST   /panel/api/clients/del/{email}
 *   GET    /panel/api/clients/traffic/{email}
 *   GET    /panel/api/clients/links/{email}
 *   POST   /panel/api/clients/resetTraffic/{email}
 *   POST   /panel/api/clients/bulkAdjust
 *
 * SECURITY: never logs tokens, passwords, cookies, sessions or links.
 */
class Sanaei3xUiClient
{
    private const COOKIE_TTL = 3000; // ~50 min

    public function __construct(private VpnPanel $panel) {}

    // ── URL building ──────────────────────────────────────────────────────────

    /** base_url + panel_path + endpoint, with safe slash normalisation. */
    public function url(string $endpoint): string
    {
        return $this->panel->apiBaseUrl() . '/' . ltrim($endpoint, '/');
    }

    // ── HTTP plumbing ─────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        $request = Http::timeout(max(1, (int) ($this->panel->timeout_seconds ?: 15)))
            ->acceptJson()
            ->withOptions(['verify' => (bool) ($this->panel->verify_ssl ?? true)]);

        if ($this->panel->effectiveAuthMethod() === VpnPanel::AUTH_API_TOKEN) {
            $token = (string) $this->panel->api_token;
            if ($token !== '') {
                $request->withToken($token); // Authorization: Bearer {token}
            }
        } else {
            $cookie = $this->cookie();
            if ($cookie !== null) {
                $request->withHeaders(['Cookie' => $cookie]);
            }
        }

        return $request;
    }

    /**
     * Perform a request, transparently re-authenticating once on 401 when using
     * API-login. Returns the decoded JSON array (3X-UI wraps as
     * {success, msg, obj}).
     *
     * @return array<string,mixed>
     */
    private function request(string $method, string $endpoint, array $data = [], bool $retried = false): array
    {
        try {
            $response = $this->http()->{$method}($this->url($endpoint), $data);
        } catch (\Throwable $e) {
            throw new Sanaei3xUiException('ارتباط با پنل سنایی برقرار نشد.');
        }

        if ($response->status() === 401 && $this->panel->effectiveAuthMethod() === VpnPanel::AUTH_API_LOGIN && ! $retried) {
            $this->forgetCookie();
            $this->login();
            return $this->request($method, $endpoint, $data, true);
        }

        return $this->parse($response, $endpoint);
    }

    /** @return array<string,mixed> */
    private function parse(Response $response, string $endpoint): array
    {
        if ($response->status() === 401) {
            throw new Sanaei3xUiException('احراز هویت با پنل سنایی ناموفق بود.');
        }
        if ($response->status() === 403) {
            throw new Sanaei3xUiException('دسترسی به API پنل سنایی مجاز نیست.');
        }
        if ($response->status() === 404) {
            throw new Sanaei3xUiException('منبع موردنظر در پنل سنایی یافت نشد.');
        }
        if ($response->failed()) {
            throw new Sanaei3xUiException('اتصال به API پنل سنایی ناموفق بود.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new Sanaei3xUiException('پاسخ پنل سنایی نامعتبر بود.');
        }

        // 3X-UI envelope: {success: bool, msg: string, obj: mixed}
        if (array_key_exists('success', $json) && $json['success'] === false) {
            throw new Sanaei3xUiException('عملیات در پنل سنایی ناموفق بود.');
        }

        return $json;
    }

    // ── Authentication ────────────────────────────────────────────────────────

    private function cookieKey(): string
    {
        return 'sanaei_cookie:' . $this->panel->id;
    }

    private function cookie(): ?string
    {
        return Cache::get($this->cookieKey());
    }

    private function forgetCookie(): void
    {
        Cache::forget($this->cookieKey());
    }

    /**
     * API login (fallback method). Stores the session cookie in cache only for
     * the request flow. Never logs the cookie.
     *
     * @return array{ok:bool}
     */
    public function login(): array
    {
        try {
            $response = Http::timeout(max(1, (int) ($this->panel->timeout_seconds ?: 15)))
                ->withOptions(['verify' => (bool) ($this->panel->verify_ssl ?? true)])
                ->asForm()
                ->post($this->url('/login'), [
                    'username' => (string) $this->panel->username,
                    'password' => (string) $this->panel->password,
                ]);
        } catch (\Throwable $e) {
            throw new Sanaei3xUiException('ارتباط با پنل سنایی برقرار نشد.');
        }

        if ($response->failed() || $response->json('success') === false) {
            throw new Sanaei3xUiException('احراز هویت با پنل سنایی ناموفق بود.');
        }

        // Capture the session cookie (3X-UI sets `3x-ui` / session cookie).
        $cookies = collect($response->cookies()->toArray())
            ->map(fn ($c) => $c['Name'] . '=' . $c['Value'])
            ->implode('; ');

        if ($cookies !== '') {
            Cache::put($this->cookieKey(), $cookies, self::COOKIE_TTL);
        }

        return ['ok' => true];
    }

    /** Ensure we have a usable session when using API-login. */
    public function authenticate(): array
    {
        if ($this->panel->effectiveAuthMethod() === VpnPanel::AUTH_API_LOGIN && $this->cookie() === null) {
            return $this->login();
        }
        return ['ok' => true];
    }

    // ── Connection test / inbounds ────────────────────────────────────────────

    public function testConnection(): bool
    {
        $this->authenticate();
        $this->getInbounds();
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function getInbounds(): array
    {
        $this->authenticate();
        $json = $this->request('get', '/panel/api/inbounds/list');
        $obj = $json['obj'] ?? $json;
        return is_array($obj) ? array_values($obj) : [];
    }

    /** @return array<string,mixed> */
    public function getInbound(int $inboundId): array
    {
        $this->authenticate();
        $json = $this->request('get', '/panel/api/inbounds/get/' . $inboundId);
        return is_array($json['obj'] ?? null) ? $json['obj'] : $json;
    }

    // ── Clients ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function getClient(string $email): array
    {
        $this->authenticate();
        $json = $this->request('get', '/panel/api/clients/get/' . rawurlencode($email));
        return is_array($json['obj'] ?? null) ? $json['obj'] : $json;
    }

    public function clientExists(string $email): bool
    {
        try {
            $client = $this->getClient($email);
            return ! empty($client);
        } catch (Sanaei3xUiException $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function getClientTraffic(string $email): array
    {
        $this->authenticate();
        $json = $this->request('get', '/panel/api/clients/traffic/' . rawurlencode($email));
        return is_array($json['obj'] ?? null) ? $json['obj'] : $json;
    }

    /** @return array<string,mixed> */
    public function getClientLinks(string $email): array
    {
        $this->authenticate();
        $json = $this->request('get', '/panel/api/clients/links/' . rawurlencode($email));
        return is_array($json['obj'] ?? null) ? ['links' => $json['obj']] : $json;
    }

    /**
     * Create a client inside the given inbound. Returns the client payload that
     * was sent plus whatever the API returns.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function createClient(UserService $service, array $options = []): array
    {
        $this->authenticate();

        $email    = $options['email']    ?? $service->remote_username ?? $this->makeEmail($service);
        $uuid     = $options['uuid']     ?? $service->remote_uuid     ?? (string) Str::uuid();
        $subId    = $options['subId']    ?? $service->remote_sub_id   ?? Str::lower(Str::random(16));
        $inbound  = (int) ($options['inboundId'] ?? $service->remote_inbound_id ?? $this->panel->default_inbound_id ?? 0);
        $totalGB  = (int) ($options['totalGB'] ?? 0);              // 0 = unlimited
        $expiryMs = (int) ($options['expiryTime'] ?? 0);          // 0 = no expiry, ms epoch

        $client = [
            'id'         => $uuid,
            'email'      => $email,
            'enable'     => $options['enable'] ?? true,
            'totalGB'    => $totalGB,
            'expiryTime' => $expiryMs,
            'limitIp'    => (int) ($options['limitIp'] ?? 0),
            'flow'       => $options['flow'] ?? '',
            'subId'      => $subId,
            'tgId'       => '',
            'reset'      => 0,
        ];

        // 3X-UI expects the client list serialised under "settings" with the
        // target inbound id.
        $payload = [
            'id'       => $inbound,
            'settings' => json_encode(['clients' => [$client]]),
        ];

        $json = $this->request('post', '/panel/api/clients/add', $payload);

        return array_merge($client, ['inboundId' => $inbound, '_response' => $json['obj'] ?? null]);
    }

    /**
     * Update an existing client by email.
     *
     * @param  array<string,mixed>  $payload  raw client fields to merge
     * @return array<string,mixed>
     */
    public function updateClient(string $email, int $inboundId, array $payload): array
    {
        $this->authenticate();

        $body = [
            'id'       => $inboundId,
            'settings' => json_encode(['clients' => [array_merge(['email' => $email], $payload)]]),
        ];

        return $this->request('post', '/panel/api/clients/update/' . rawurlencode($email), $body);
    }

    public function deleteClient(string $email, int $inboundId): bool
    {
        $this->authenticate();
        $this->request('post', '/panel/api/clients/del/' . rawurlencode($email), ['id' => $inboundId]);
        return true;
    }

    public function resetClientTraffic(string $email, int $inboundId): bool
    {
        $this->authenticate();
        $this->request('post', '/panel/api/clients/resetTraffic/' . rawurlencode($email), ['id' => $inboundId]);
        return true;
    }

    public function setClientEnabled(string $email, int $inboundId, bool $enabled): bool
    {
        $this->updateClient($email, $inboundId, ['enable' => $enabled]);
        return true;
    }

    /**
     * Adjust an individual client's quota/expiry safely (extra traffic / time).
     *
     * @return array<string,mixed>
     */
    public function adjustClient(string $email, int $inboundId, array $changes): array
    {
        return $this->updateClient($email, $inboundId, $changes);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Stable, unique client email derived from the service. */
    public function makeEmail(UserService $service): string
    {
        if (filled($service->remote_username)) {
            return $service->remote_username;
        }
        $base = 'zed-' . ($service->id ?: Str::lower(Str::random(6)));
        return $base;
    }
}
