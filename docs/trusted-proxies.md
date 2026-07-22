# Trusted Proxies & Real Client IP

ZedProxy relies on the **real client IP** (`Request::ip()`) for security-critical
rate limiting — login brute-force protection, OTP sending, payment/wallet
throttling. If the application trusted every proxy, a client able to reach the
origin server directly could forge an `X-Forwarded-For` header and defeat those
limits.

The app therefore trusts **only the proxies you configure** and honours
forwarding headers (including `X-Forwarded-Proto`, which keeps HTTPS URL
generation, Livewire, Filament and signed URLs working) **only when the
immediate remote peer is one of those trusted proxies**.

## Configuration

All settings live in `config/proxies.php` and are driven by environment
variables:

| Variable | Meaning | Example |
| --- | --- | --- |
| `TRUSTED_PROXIES` | Comma-separated IPs/CIDRs allowed to set `X-Forwarded-*`. Empty = direct access. Never `*`. | `127.0.0.1,::1` |
| `TRUST_CLOUDFLARE_PROXIES` | Trust Cloudflare edge ranges and use `CF-Connecting-IP`. | `false` |
| `PROXY_LOG_RESOLUTION` | Log resolved client IP vs proxy IP at debug level. | `false` |

### Topologies

- **Nginx on the same host** (TLS terminated by Nginx, PHP-FPM behind it):
  ```env
  TRUSTED_PROXIES=127.0.0.1,::1
  TRUST_CLOUDFLARE_PROXIES=false
  ```
- **Cloudflare in front** (origin reachable only from Cloudflare + localhost):
  ```env
  TRUSTED_PROXIES=127.0.0.1,::1
  TRUST_CLOUDFLARE_PROXIES=true
  ```
- **Known external load balancer** (e.g. a private-subnet LB):
  ```env
  TRUSTED_PROXIES=10.0.0.0/8
  TRUST_CLOUDFLARE_PROXIES=false
  ```
- **Direct access, no proxy**:
  ```env
  TRUSTED_PROXIES=
  TRUST_CLOUDFLARE_PROXIES=false
  ```

After changing these values run `php artisan config:cache` (or clear the cache)
if config caching is enabled.

## How resolution works

`App\Http\Middleware\TrustProxies` replaces Laravel's default middleware:

1. Trusted proxies = `TRUSTED_PROXIES` + (Cloudflare ranges when enabled).
2. Only `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port` and
   `X-Forwarded-Proto` are trusted — never `X-Forwarded-Prefix` or the legacy
   `Forwarded` header.
3. `CF-Connecting-IP` is used **only** when the TCP peer (`REMOTE_ADDR`) is a
   valid Cloudflare address. A forged `CF-Connecting-IP` from any other source
   is ignored.
4. From an untrusted peer, all forwarding headers are ignored and
   `Request::ip()` falls back to the real `REMOTE_ADDR`.

## Cloudflare IP ranges

Bundled defaults live in `App\Support\CloudflareProxies` (from
<https://www.cloudflare.com/ips-v4> and <https://www.cloudflare.com/ips-v6>).

Refresh them from Cloudflare at any time:

```bash
php artisan zedproxy:update-cloudflare-ips
```

This fetches both lists and writes them to
`storage/app/cloudflare/ip-ranges.json`, which is preferred over the bundled
defaults when present and valid. A failed or suspiciously small response leaves
the existing ranges untouched — the trusted list is never emptied. Schedule a
monthly refresh, e.g. in `routes/console.php`:

```php
Schedule::command('zedproxy:update-cloudflare-ips')->monthly();
```

## Firewall: block direct access to the origin

Trusting Cloudflare is only safe if attackers **cannot** reach the origin
directly (bypassing Cloudflare and spoofing `CF-Connecting-IP` from a Cloudflare
range they don't control is impossible, but reaching a non-Cloudflare origin
directly would still expose you to `X-Forwarded-For` games if misconfigured).
Restrict inbound 80/443 to Cloudflare's ranges only. Example with `ufw`:

```bash
# Allow SSH, then only Cloudflare to 80/443.
for cidr in $(curl -s https://www.cloudflare.com/ips-v4) $(curl -s https://www.cloudflare.com/ips-v6); do
  ufw allow from "$cidr" to any port 443 proto tcp
  ufw allow from "$cidr" to any port 80  proto tcp
done
ufw default deny incoming
ufw enable
```

For a Cloudflare-less deployment, restrict the origin to your load balancer's
subnet the same way.

## Recommended Nginx configuration

TLS terminated at Nginx, proxying to PHP-FPM/the app on localhost:

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    root /var/www/zedproxy/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;

        # Tell the app the original scheme/host so HTTPS URLs are generated.
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
        fastcgi_param HTTP_X_FORWARDED_HOST  $host;
        fastcgi_param HTTP_X_FORWARDED_FOR   $remote_addr;
    }
}

# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}
```

With this setup the peer is always `127.0.0.1`, so set
`TRUSTED_PROXIES=127.0.0.1,::1`.

### Behind Cloudflare + Nginx

When Cloudflare sits in front, keep the Nginx config above and additionally set
`TRUST_CLOUDFLARE_PROXIES=true`. Cloudflare terminates TLS at its edge and
forwards over HTTPS to your origin; it sends `CF-Connecting-IP` with the real
visitor IP, which the app uses because the peer chain resolves to a Cloudflare
address. To restore the visitor IP in Nginx logs too, optionally restore it with
Cloudflare's real-IP module:

```nginx
# /etc/nginx/conf.d/cloudflare-realip.conf  (regenerate from the published lists)
# for ip in $(curl -s https://www.cloudflare.com/ips-v4); do echo "set_real_ip_from $ip;"; done
set_real_ip_from 173.245.48.0/20;
# ... remaining Cloudflare ranges ...
real_ip_header CF-Connecting-IP;
```

## Recommended Cloudflare configuration

- **SSL/TLS mode:** *Full (strict)* — Cloudflare validates the origin
  certificate.
- **Always Use HTTPS:** on.
- Ensure the origin firewall only accepts connections from Cloudflare ranges
  (see the firewall section above) so the origin can't be reached directly.
- Keep the app's `TRUST_CLOUDFLARE_PROXIES=true` and refresh ranges monthly with
  `zedproxy:update-cloudflare-ips`.

## Logging

Set `PROXY_LOG_RESOLUTION=true` to emit, per request, a `proxy.resolved` debug
log with `client_ip` and `proxy_ip` separately. Only IP addresses are logged —
never headers, cookies, or credentials.
