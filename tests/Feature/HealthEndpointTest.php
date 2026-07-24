<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The public health probes must be STATELESS: hit every few seconds by the
 * deployer's loopback readiness check (and any uptime monitor), they must never
 * start a session or set session / XSRF cookies, while keeping the non-indexable
 * header. See routes/web.php (withoutMiddleware) and HealthController.
 */
class HealthEndpointTest extends TestCase
{
    private function sessionCookieName(): string
    {
        return (string) config('session.cookie', 'laravel_session');
    }

    /** @return array<int,string> lowercased cookie names on the response */
    private function responseCookieNames($response): array
    {
        return array_map(
            static fn ($c) => strtolower($c->getName()),
            $response->baseResponse->headers->getCookies()
        );
    }

    public function test_health_does_not_set_session_or_csrf_cookies(): void
    {
        $response = $this->get('/health');

        // A valid health response is 200 (healthy) or 503 (a dependency down in
        // this test env) — either way it must be cookie-free.
        $this->assertContains($response->getStatusCode(), [200, 503]);

        $names = $this->responseCookieNames($response);
        $this->assertNotContains(strtolower($this->sessionCookieName()), $names, 'health must not set a session cookie');
        $this->assertNotContains('xsrf-token', $names, 'health must not set an XSRF-TOKEN cookie');
        $this->assertSame([], $names, 'health must not set any cookies');
    }

    public function test_liveness_is_200_stateless_and_noindex(): void
    {
        $response = $this->get('/health/live');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok', 'app' => true]);
        $this->assertSame([], $this->responseCookieNames($response), 'liveness must not set any cookies');
        // X-Robots-Tag (noindex) is preserved even without the session stack.
        $this->assertStringContainsString('noindex', strtolower((string) $response->headers->get('X-Robots-Tag')));
    }

    public function test_health_readiness_returns_boolean_only_payload(): void
    {
        $response = $this->get('/health');

        // Only safe boolean fields — never exception text / paths / secrets.
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        foreach (['app', 'database', 'redis', 'migrations', 'storage'] as $k) {
            $this->assertArrayHasKey($k, $data);
            $this->assertIsBool($data[$k]);
        }
    }
}
