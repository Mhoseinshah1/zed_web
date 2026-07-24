<?php

namespace Tests\Feature;

use App\Support\CloudflareProxies;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Trusted-proxy resolution: forwarding headers (and CF-Connecting-IP) are only
 * honoured when the immediate remote peer is a configured trusted proxy, so
 * Request::ip() — the key for auth/OTP rate limiting — can't be spoofed.
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A tiny surface for asserting how the request was resolved.
        Route::middleware('web')->group(function () {
            Route::get('/_test/ip', fn () => request()->ip());
            Route::get('/_test/scheme', fn () => request()->isSecure() ? 'https' : 'http');
            Route::get('/_test/url', fn () => url('/generated'));
            Route::get('/_test/throttled', fn () => 'ok')->middleware('throttle:2,1');
            Route::get('/_test/signed', fn () => request()->hasValidSignature() ? 'valid' : 'invalid')
                ->name('test.signed');
        });

        // Make the ad-hoc named routes resolvable by the URL generator before
        // the first request refreshes the name lookup table.
        Route::getRoutes()->refreshNameLookups();
    }

    /** GET with explicit REMOTE_ADDR + arbitrary headers/server vars. */
    private function hit(string $uri, array $server = [])
    {
        return $this->call('GET', $uri, [], [], [], $this->server($server));
    }

    /** Normalise a REMOTE_ADDR/HTTP_* server array. */
    private function server(array $server): array
    {
        return array_merge(['REMOTE_ADDR' => '203.0.113.9'], $server);
    }

    // ── Direct access ─────────────────────────────────────────────────────────

    public function test_direct_request_with_forged_x_forwarded_for_is_ignored(): void
    {
        config(['proxies.proxies' => [], 'proxies.trust_cloudflare' => false]);

        $this->hit('/_test/ip', [
            'REMOTE_ADDR' => '198.51.100.50',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ])->assertOk()->assertSee('198.51.100.50');
    }

    // ── Trusted proxy ─────────────────────────────────────────────────────────

    public function test_request_from_trusted_proxy_uses_forwarded_client_ip(): void
    {
        config(['proxies.proxies' => ['127.0.0.1', '::1'], 'proxies.trust_cloudflare' => false]);

        $this->hit('/_test/ip', [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ])->assertOk()->assertSee('9.9.9.9');
    }

    // ── Untrusted proxy ───────────────────────────────────────────────────────

    public function test_request_from_untrusted_proxy_falls_back_to_remote_addr(): void
    {
        config(['proxies.proxies' => ['127.0.0.1'], 'proxies.trust_cloudflare' => false]);

        // Peer is NOT in the trusted list → its X-Forwarded-For is disregarded.
        $this->hit('/_test/ip', [
            'REMOTE_ADDR' => '45.33.22.11',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ])->assertOk()->assertSee('45.33.22.11');
    }

    // ── Multi-proxy chain ─────────────────────────────────────────────────────

    public function test_multiple_proxy_chain_resolves_leftmost_untrusted_client(): void
    {
        config(['proxies.proxies' => ['10.0.0.0/8', '127.0.0.1'], 'proxies.trust_cloudflare' => false]);

        // client → 10.0.0.5 → 10.0.0.6 → (peer) 127.0.0.1
        $this->hit('/_test/ip', [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.5, 10.0.0.6',
        ])->assertOk()->assertSee('203.0.113.7');
    }

    // ── Cloudflare ────────────────────────────────────────────────────────────

    public function test_valid_cloudflare_request_uses_cf_connecting_ip(): void
    {
        config(['proxies.proxies' => [], 'proxies.trust_cloudflare' => true]);

        // 173.245.48.1 is inside Cloudflare's 173.245.48.0/20 range.
        $this->hit('/_test/ip', [
            'REMOTE_ADDR' => '173.245.48.1',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.77',
            'HTTP_X_FORWARDED_FOR' => 'garbage-should-be-overwritten',
        ])->assertOk()->assertSee('203.0.113.77');
    }

    public function test_fake_cloudflare_header_from_non_cloudflare_ip_is_ignored(): void
    {
        config(['proxies.proxies' => [], 'proxies.trust_cloudflare' => true]);

        // Peer is not a Cloudflare edge → CF-Connecting-IP must be ignored.
        $this->hit('/_test/ip', [
            'REMOTE_ADDR' => '45.33.22.11',
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
        ])->assertOk()->assertSee('45.33.22.11');
    }

    // ── Rate limiting keys off the real client IP ─────────────────────────────

    public function test_rate_limiting_uses_real_client_ip_not_forged_header(): void
    {
        config(['proxies.proxies' => [], 'proxies.trust_cloudflare' => false]);

        // Same untrusted peer, rotating forged XFF each call. If the limiter keyed
        // off the spoofable header the attacker would dodge the limit; instead all
        // three hits share the REMOTE_ADDR key and the third is blocked.
        $this->hit('/_test/throttled', ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'])->assertOk();
        $this->hit('/_test/throttled', ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '2.2.2.2'])->assertOk();
        $this->hit('/_test/throttled', ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '3.3.3.3'])->assertStatus(429);
    }

    // ── HTTPS URL generation behind a trusted proxy ───────────────────────────

    public function test_https_url_generation_behind_trusted_proxy(): void
    {
        config(['proxies.proxies' => ['127.0.0.1'], 'proxies.trust_cloudflare' => false]);

        $this->hit('/_test/scheme', [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertOk()->assertSee('https');

        $this->hit('/_test/url', [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertOk()->assertSee('https://', false);
    }

    public function test_forwarded_proto_from_untrusted_peer_is_ignored(): void
    {
        config(['proxies.proxies' => ['127.0.0.1'], 'proxies.trust_cloudflare' => false]);

        // Untrusted peer claiming https must not flip the scheme.
        $this->hit('/_test/scheme', [
            'REMOTE_ADDR' => '45.33.22.11',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertOk()->assertSee('http');
    }

    // ── Signed URL validation behind a trusted proxy ──────────────────────────

    public function test_signed_url_validates_behind_trusted_proxy(): void
    {
        config([
            'app.url' => 'https://localhost',
            'proxies.proxies' => ['127.0.0.1'],
            'proxies.trust_cloudflare' => false,
        ]);
        URL::forceRootUrl('https://localhost');

        $signed = URL::signedRoute('test.signed', ['x' => '1']);
        $path = '/'.ltrim(parse_url($signed, PHP_URL_PATH), '/').'?'.parse_url($signed, PHP_URL_QUERY);

        $this->hit($path, [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertOk()->assertSee('valid');
    }

    // ── Cloudflare range source ───────────────────────────────────────────────

    public function test_cloudflare_ranges_fall_back_to_bundled_defaults(): void
    {
        Storage::fake('local');

        $ranges = CloudflareProxies::ranges();

        $this->assertContains('173.245.48.0/20', $ranges);
        $this->assertContains('2400:cb00::/32', $ranges);
        $this->assertTrue(CloudflareProxies::contains('104.16.0.5'));
        $this->assertFalse(CloudflareProxies::contains('8.8.8.8'));
    }

    public function test_cloudflare_ranges_prefer_valid_cached_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            CloudflareProxies::CACHE_PATH,
            json_encode(['192.0.2.0/24', 'not-an-ip', '198.51.100.0/24']),
        );

        $ranges = CloudflareProxies::ranges();

        $this->assertSame(['192.0.2.0/24', '198.51.100.0/24'], $ranges);
    }

    public function test_cloudflare_ranges_ignore_malformed_cache(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(CloudflareProxies::CACHE_PATH, 'not-json');

        // Falls back to bundled defaults rather than trusting an empty set.
        $this->assertContains('104.16.0.0/13', CloudflareProxies::ranges());
    }
}
