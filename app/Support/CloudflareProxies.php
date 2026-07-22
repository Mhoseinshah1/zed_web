<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Source of truth for Cloudflare's edge IP ranges.
 *
 * Ranges are read from a cached file (refreshed by `zedproxy:update-cloudflare-ips`)
 * when present and valid, otherwise the bundled defaults below are used. The
 * bundled list is a safe fallback so the application never trusts an empty set
 * of "Cloudflare" proxies — which would silently break CF-Connecting-IP handling.
 *
 * Update instructions live in docs/trusted-proxies.md.
 */
class CloudflareProxies
{
    /** Relative path (on the local disk) of the cached range file. */
    public const CACHE_PATH = 'cloudflare/ip-ranges.json';

    /**
     * Bundled Cloudflare IPv4 ranges — https://www.cloudflare.com/ips-v4
     *
     * @var array<int, string>
     */
    public const DEFAULT_V4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Bundled Cloudflare IPv6 ranges — https://www.cloudflare.com/ips-v6
     *
     * @var array<int, string>
     */
    public const DEFAULT_V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * All Cloudflare ranges (cached file if valid, else bundled defaults).
     *
     * @return array<int, string>
     */
    public static function ranges(): array
    {
        $cached = self::cachedRanges();

        return $cached !== [] ? $cached : self::defaultRanges();
    }

    /**
     * The bundled fallback ranges.
     *
     * @return array<int, string>
     */
    public static function defaultRanges(): array
    {
        return array_merge(self::DEFAULT_V4, self::DEFAULT_V6);
    }

    /**
     * Whether the given IP belongs to a Cloudflare range.
     */
    public static function contains(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        return IpUtils::checkIp($ip, self::ranges());
    }

    /**
     * Read + validate the cached range file. Returns [] when it is missing,
     * unreadable, malformed, or contains no valid CIDR entries.
     *
     * @return array<int, string>
     */
    public static function cachedRanges(): array
    {
        try {
            $disk = Storage::disk('local');
            if (! $disk->exists(self::CACHE_PATH)) {
                return [];
            }
            $decoded = json_decode((string) $disk->get(self::CACHE_PATH), true);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return self::sanitize($decoded);
    }

    /**
     * Keep only syntactically valid CIDR / IP entries.
     *
     * @param  array<mixed>  $entries
     * @return array<int, string>
     */
    public static function sanitize(array $entries): array
    {
        $valid = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            [$address] = array_pad(explode('/', $entry, 2), 1, null);
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $valid[] = $entry;
            }
        }

        return array_values(array_unique($valid));
    }
}
