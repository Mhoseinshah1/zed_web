<?php

use Illuminate\Http\Request;

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of IP addresses or CIDR ranges that are allowed to
    | set the X-Forwarded-* headers. The application only trusts forwarding
    | headers when the immediate remote peer (REMOTE_ADDR) matches one of
    | these entries — a request arriving directly from an untrusted address
    | can NOT spoof X-Forwarded-For to bypass IP-based rate limiting.
    |
    | Pick the value that matches your deployment topology:
    |   - Nginx on the same host:      TRUSTED_PROXIES=127.0.0.1,::1
    |   - A known external LB:         TRUSTED_PROXIES=10.0.0.0/8
    |   - Direct access, no proxy:     TRUSTED_PROXIES=            (leave empty)
    |
    | The special value "*" is intentionally NOT supported here — trusting
    | every proxy is exactly the vulnerability this configuration removes.
    |
    */

    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ), fn (string $p) => $p !== '' && $p !== '*')),

    /*
    |--------------------------------------------------------------------------
    | Trust Cloudflare
    |--------------------------------------------------------------------------
    |
    | When true, Cloudflare's published edge IP ranges are added to the trusted
    | proxy list and the real visitor IP is taken from the CF-Connecting-IP
    | header — but ONLY when the request's immediate remote peer is itself a
    | valid Cloudflare address. A forged CF-Connecting-IP from a non-Cloudflare
    | source is ignored.
    |
    | Keep this false unless the site is actually served through Cloudflare.
    |
    */

    'trust_cloudflare' => filter_var(
        env('TRUST_CLOUDFLARE_PROXIES', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Forwarded Headers To Trust
    |--------------------------------------------------------------------------
    |
    | Only the headers required to recover the original client IP, host, port
    | and scheme. X-Forwarded-Proto is what keeps HTTPS URL generation (and
    | therefore Livewire / Filament / signed URLs) working behind TLS-terminating
    | proxies. We deliberately do NOT trust X-Forwarded-Prefix or the legacy
    | "Forwarded" header.
    |
    */

    'headers' => Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,

    /*
    |--------------------------------------------------------------------------
    | Log Proxy Resolution
    |--------------------------------------------------------------------------
    |
    | When true, each resolved request logs the real client IP and the
    | immediate proxy IP separately at debug level. Only IP addresses are
    | logged — never headers, cookies, or credentials.
    |
    */

    'log_resolution' => filter_var(
        env('PROXY_LOG_RESOLUTION', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
