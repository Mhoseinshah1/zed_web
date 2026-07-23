<?php

namespace App\Http\Middleware;

use App\Support\CloudflareProxies;
use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trust ONLY the reverse proxies we actually deploy behind, loaded from
 * configuration (config/proxies.php ← TRUSTED_PROXIES / TRUST_CLOUDFLARE_PROXIES).
 *
 * This replaces Laravel's default TrustProxies and, critically, replaces the
 * previous `trustProxies(at: '*')` which trusted every source. Because IP-based
 * rate limiting (login, OTP) keys off Request::ip(), trusting all proxies let a
 * client that could reach the origin directly forge X-Forwarded-For and bypass
 * those limits. Here, forwarding headers are honoured only when the immediate
 * remote peer is a configured trusted proxy.
 */
class TrustProxies extends Middleware
{
    /**
     * The headers that should be used to detect proxies.
     *
     * Only client IP, host, port and scheme. X-Forwarded-Proto is what keeps
     * HTTPS URL generation working behind a TLS-terminating proxy (Livewire,
     * Filament, signed URLs all depend on the correct scheme).
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;

    public function __construct()
    {
        $this->proxies = $this->resolveTrustedProxies();
        $this->headers = (int) config('proxies.headers', $this->headers);
    }

    /**
     * Build the trusted proxy list: the configured static proxies plus, when
     * enabled, Cloudflare's published edge ranges.
     *
     * @return array<int, string>
     */
    protected function resolveTrustedProxies(): array
    {
        $proxies = (array) config('proxies.proxies', []);

        if (config('proxies.trust_cloudflare', false)) {
            $proxies = array_merge($proxies, CloudflareProxies::ranges());
        }

        return array_values(array_unique(array_filter(
            $proxies,
            fn ($p) => is_string($p) && trim($p) !== '' && $p !== '*',
        )));
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Recover the real visitor IP from CF-Connecting-IP *only* when the TCP
        // peer is genuinely Cloudflare — a forged header from elsewhere is left
        // untouched and never reaches Request::ip().
        $this->applyCloudflareClientIp($request);

        $response = parent::handle($request, $next);

        $this->logResolution($request);

        return $response;
    }

    /**
     * When Cloudflare is trusted and the immediate peer is a Cloudflare edge IP,
     * rewrite X-Forwarded-For to the CF-Connecting-IP so the trusted-proxy chain
     * resolves Request::ip() to the true client. Does nothing otherwise.
     */
    protected function applyCloudflareClientIp(Request $request): void
    {
        if (! config('proxies.trust_cloudflare', false)) {
            return;
        }

        $remoteAddr = $request->server->get('REMOTE_ADDR');
        $cfClientIp = $request->headers->get('CF-Connecting-IP');

        if ($cfClientIp === null || $cfClientIp === '') {
            return;
        }

        // The header is only meaningful when it arrives directly from Cloudflare.
        if (! IpUtils::checkIp((string) $remoteAddr, CloudflareProxies::ranges())) {
            return;
        }

        // Ignore a malformed value rather than poisoning the chain.
        if (filter_var($cfClientIp, FILTER_VALIDATE_IP) === false) {
            return;
        }

        $request->headers->set('X-Forwarded-For', $cfClientIp);
    }

    /**
     * Log the resolved client IP and the immediate proxy IP separately. Only IP
     * addresses are emitted — never headers, cookies, or credentials.
     */
    protected function logResolution(Request $request): void
    {
        if (! config('proxies.log_resolution', false)) {
            return;
        }

        Log::debug('proxy.resolved', [
            'client_ip' => $request->ip(),
            'proxy_ip'  => $request->server->get('REMOTE_ADDR'),
        ]);
    }
}
