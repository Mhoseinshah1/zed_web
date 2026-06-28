# ZedProxy

A production-ready VPN/proxy sales platform built with Laravel, PostgreSQL, Redis, Filament, and Tailwind CSS. Designed to scale to 40,000+ users with full RTL Persian support.

## Tech Stack

| Component     | Technology                              |
|---------------|-----------------------------------------|
| Backend       | Laravel 12, PHP 8.3+                    |
| Database      | PostgreSQL 16+                          |
| Cache/Queue   | Redis                                   |
| Frontend      | Blade + Tailwind CSS (RTL)              |
| Admin Panel   | Filament v3                             |
| Web Server    | Nginx + PHP-FPM                         |
| OS            | Ubuntu 22.04, 24.04, 26.04+            |

## Requirements

- Ubuntu 22.04 (jammy), 24.04 (noble), or 26.04 (resolute) — see [Supported OS](#supported-os)
- **PHP 8.3 or higher**, compatible with Ubuntu 24.04 official packages — extensions: pgsql, redis, mbstring, xml, curl, zip, bcmath, gd, intl, opcache
- PostgreSQL 14+
- Redis 6+
- Node.js 22+, npm
- Composer 2+

## Supported OS

The installer supports Ubuntu releases where official packages or a verified PPA can provide PHP 8.3 or higher.

| Ubuntu Release | Codename  | PHP Source              | Status     |
|----------------|-----------|-------------------------|------------|
| 22.04 LTS      | jammy     | ondrej/php PPA          | Supported  |
| 24.04 LTS      | noble     | Official Ubuntu packages| Supported  |
| 26.04          | resolute  | Official Ubuntu packages| Supported  |

**How PHP is installed:**

1. The installer always tries official Ubuntu packages first (`apt-get install php php-fpm ...`).
2. It detects the installed PHP version automatically (`php -r 'echo PHP_VERSION;'`).
3. If official packages are too old (below PHP 8.2), it checks whether the [ondrej/php PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php) supports the current Ubuntu codename — using a live HTTP check on the PPA Release file.
4. If the PPA supports the codename, it adds the PPA and installs PHP 8.4.
5. If neither official packages nor the PPA can provide PHP 8.2+, the installer stops with a clear error and suggests using Docker.

The installer **never blindly adds `ppa:ondrej/php`**. Before any `apt update`, it removes stale ondrej/php source files that would cause `apt update` to fail on unsupported Ubuntu releases (e.g. resolute).

### If the native installer cannot run on your Ubuntu version

If the native installer cannot satisfy the PHP version requirement on your specific Ubuntu release, use Docker-based deployment. Docker support is planned for a future release. See the [What's next](#whats-next) section.

## One-command installation

Download and run (works on all Ubuntu/VPS environments):

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh
chmod +x /tmp/zedproxy-install.sh
sudo bash /tmp/zedproxy-install.sh
```

Or as a single line:

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh && chmod +x /tmp/zedproxy-install.sh && sudo bash /tmp/zedproxy-install.sh
```

> **Note:** Do not use `sudo bash <(curl ...)` or `curl ... | sudo bash`. Both formats fail on certain Ubuntu/VPS environments. The download-and-run format above is the only supported method.

The script runs interactively and asks for the following before doing anything:

| Prompt | Default if you press Enter |
|--------|---------------------------|
| Domain (without http/https) | *(required — no default)* |
| Admin email | `admin@DOMAIN` |
| Admin name/username | `zedadmin_RANDOM` (e.g. `zedadmin_a83f21`) |
| Admin password | Strong 24-char random password |
| Install SSL with Let's Encrypt? | `Y` (yes) |
| Use STAGING mode? (if SSL=yes) | `N` (production) |

After all questions are answered, a 3-second countdown lets you cancel with Ctrl+C before anything is installed.

The admin user is created automatically. **Credentials are only shown once at the end of a successful installation.** If installation fails at any step, the admin password is not printed.

All installer output is logged to `/var/log/zedproxy-install.log` (root-only, mode 600). The log includes credentials shown in the final summary. If anything goes wrong, check the log first:

```bash
sudo tail -n 120 /var/log/zedproxy-install.log
```

**Website URL in the final summary is always accurate:** it shows `http://DOMAIN` when SSL is not active and `https://DOMAIN` only when SSL succeeded — never a false https URL.

## Manual installation

### 1. Clone and enter directory

```bash
git clone -b main https://github.com/mhoseinshah1/zed_web.git /var/www/zedproxy
cd /var/www/zedproxy
```

### 2. PostgreSQL setup

```bash
sudo -u postgres psql
```

```sql
CREATE ROLE zedproxy_user LOGIN PASSWORD 'your_strong_password';
CREATE DATABASE zedproxy OWNER zedproxy_user;
GRANT ALL PRIVILEGES ON DATABASE zedproxy TO zedproxy_user;
\q
```

### 3. Redis setup

```bash
sudo apt install redis-server
sudo systemctl enable --now redis-server
redis-cli ping  # should return PONG
```

### 4. Configure environment

```bash
cp .env.example .env
nano .env  # fill in DB_PASSWORD, APP_URL, etc.
```

Required `.env` values:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zedproxy
DB_USERNAME=zedproxy_user
DB_PASSWORD=your_strong_password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CLIENT=predis
```

### 5. Install and build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 6. Permissions

```bash
sudo chown -R www-data:www-data /var/www/zedproxy
sudo chmod -R 755 /var/www/zedproxy
sudo chmod -R 775 storage bootstrap/cache
sudo chmod 600 .env
```

### 7. Create admin user

Use the dedicated Artisan command (safe to re-run — finds user by email or username, then updates):

```bash
php artisan zedproxy:create-admin \
    --email="admin@yourdomain.com" \
    --username="myadmin" \
    --password="your_secure_password"
```

Then log in at `/zed-admin`.

**Login uses username, not email.** The email is stored for password resets and system notifications but is not used to log in to the admin panel.

Alternatively, the `install.sh` script creates the admin user automatically during installation using the credentials you enter at the prompts.

## Database backup

### Create a backup

```bash
bash scripts/backup.sh
```

Backup files are saved to `storage/app/backups/` with timestamps:

```
zedproxy_2026-06-27_03-00.dump
```

### Automate daily backups

The `install.sh` automatically sets up a cron at 3:00 AM. Backups older than 30 days are deleted automatically.

To add manually:

```bash
echo "0 3 * * * www-data bash /var/www/zedproxy/scripts/backup.sh >> /var/log/zedproxy-backup.log 2>&1" \
    | sudo tee /etc/cron.d/zedproxy-backup
```

## Restore from backup

On the new server, after completing steps 1-6 above (without running migrations):

```bash
# Restore from a .dump file
PGPASSWORD=your_db_password pg_restore \
    -h 127.0.0.1 \
    -U zedproxy_user \
    -d zedproxy \
    --clean \
    --if-exists \
    /path/to/zedproxy_2026-06-27_03-00.dump
```

Then run:

```bash
php artisan config:cache
php artisan route:cache
```

## Health check

```bash
curl https://yourdomain.com/health
```

Expected response:

```json
{
    "status": "ok",
    "app": true,
    "database": true,
    "redis": true,
    "migrations": true,
    "storage": true
}
```

## Useful commands

```bash
# Clear all caches
php artisan optimize:clear

# Rebuild caches (production)
php artisan optimize

# Run queue worker manually
php artisan queue:work redis --tries=3

# Check failed jobs
php artisan queue:failed

# View logs
tail -f storage/logs/laravel.log

# Artisan tinker (REPL)
php artisan tinker

# Run specific migration
php artisan migrate --step

# Rollback last migration
php artisan migrate:rollback
```

## Queue workers (Supervisor)

The `install.sh` configures Supervisor to run 2 queue workers. To manage:

```bash
sudo supervisorctl status
sudo supervisorctl restart zedproxy-worker:*
sudo supervisorctl stop zedproxy-worker:*
```

## Troubleshooting

### Installation log

Every installer run writes to `/var/log/zedproxy-install.log` (root-only, 600):

```bash
sudo tail -n 120 /var/log/zedproxy-install.log
```

If a command fails, the log shows the exact line number and command.

### Re-running the installer

The installer is safe to re-run:

- Existing git repository at `/var/www/zedproxy` is updated (`git fetch; git reset --hard origin/main`)
- Non-git directories are backed up to `/var/www/zedproxy_backup_YYYYMMDD_HHMMSS` before a fresh clone
- PostgreSQL user and database are created if missing; password is rotated on re-run
- Nginx config is only rewritten if no certbot-managed SSL blocks exist — existing SSL config is preserved
- A valid existing Let's Encrypt certificate is reused; certbot does not request a new one

### Let's Encrypt rate limit

```bash
# Check rate limit status in the install log
sudo grep -i "rate limit\|too many" /var/log/zedproxy-install.log

# After 168 hours (7 days), run manually:
certbot --nginx -d yourdomain.com -m admin@yourdomain.com \
    --non-interactive --agree-tos --redirect --no-eff-email
```

### Database connection refused

```bash
sudo systemctl status postgresql
sudo -u postgres psql -c "\l"
```

### Redis connection refused

```bash
sudo systemctl status redis-server
redis-cli ping
```

### 500 errors

```bash
tail -50 storage/logs/laravel.log
php artisan config:clear
php artisan cache:clear
```

### Nginx 502 Bad Gateway

```bash
# Detect the installed PHP-FPM service name first
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo systemctl status php${PHP_VERSION}-fpm
sudo nginx -t
sudo journalctl -u nginx -n 50
```

### Permission errors

```bash
sudo chown -R www-data:www-data /var/www/zedproxy
sudo chmod -R 775 storage bootstrap/cache
```

### Admin panel not loading

Re-run the admin creation command to ensure `is_admin = true` and the password is correctly hashed:

```bash
php artisan zedproxy:create-admin \
    --email="admin@example.com" \
    --username="myadmin" \
    --password="your_password"
```

The admin panel is at `/zed-admin`, not `/admin`. Login uses **username**, not email.

## Diagnostics

Run these commands to gather system state before reporting an issue:

```bash
lsb_release -a
cat /etc/os-release
php -v
nginx -v
psql --version
redis-server --version
```

## Branch convention

All production installation and deployment commands must use the **`main`** branch.

| Purpose | Command |
|---------|---------|
| Install | `curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh && chmod +x /tmp/zedproxy-install.sh && sudo bash /tmp/zedproxy-install.sh` |
| Clone | `git clone -b main https://github.com/mhoseinshah1/zed_web.git` |
| Update | `git pull origin main` |

Do not deploy from `master`, `develop`, `staging`, or any other branch without explicit testing.

## Deployment notes

### SSL (HTTPS)

The installer prompts you to install a free SSL certificate with Let's Encrypt during setup. The default answer is **yes**.

**What the installer does automatically:**

1. Asks: `Install free SSL certificate with Let's Encrypt? [Y/n]` — press Enter to accept.
2. Asks: `Use Let's Encrypt STAGING mode? [y/N]` — press Enter for production (recommended).
3. Installs `certbot` and `python3-certbot-nginx` (non-interactive).
4. **Checks for an existing valid certificate** — if one exists for the domain, reuses it and skips requesting a new one (avoids rate limits on re-runs).
5. Checks DNS before running certbot — compares the domain's A record with the server's public IP.
6. Includes `www.DOMAIN` in the certificate only if `www.DOMAIN` also resolves to this server.
7. Runs certbot after Nginx is configured and the HTTP health check passes.
8. On success: updates `APP_URL` in `.env` to `https://DOMAIN`, rebuilds config cache, verifies HTTPS health.
9. On failure: does not remove the working HTTP site. Prints a manual certbot command. The final summary shows `http://DOMAIN`.

**The final summary always shows the correct URL.** If SSL failed or was skipped, the summary shows `http://DOMAIN`. It never shows `https://` when SSL is not active.

#### Let's Encrypt rate limits

If certbot fails with "too many certificates" or "rate limit", the installer:
- Does **not** retry automatically
- Does **not** fail the website installation
- Shows a clear warning with the reason
- Keeps HTTP working

Once the rate limit window (168 hours / 7 days) has passed, run certbot manually:

```bash
certbot --nginx -d yourdomain.com -m admin@yourdomain.com \
    --non-interactive --agree-tos --redirect --no-eff-email
```

#### Staging mode (for testing)

If you need to test the SSL flow without using production rate limits, choose `y` at the staging prompt. The certificate will not be trusted by browsers but the full certbot flow runs. **Always use production for real installs.**

#### DNS must point to this server before SSL can be issued

If DNS is not ready at install time, choose `n` at the SSL prompt and run certbot manually once DNS propagates:

```bash
certbot --nginx -d yourdomain.com -m admin@yourdomain.com \
    --non-interactive --agree-tos --redirect --no-eff-email
```

To add `www` to an existing certificate:

```bash
certbot --nginx -d yourdomain.com -d www.yourdomain.com \
    -m admin@yourdomain.com --non-interactive --agree-tos --redirect --no-eff-email
```

#### Verify SSL after installation

```bash
curl -I https://yourdomain.com/health
# Expected: HTTP/2 200
```

### After code update

Use the dedicated update script — it handles backups, maintenance mode, migrations, and service restarts safely:

```bash
zedproxy-update
```

Or download and run the latest version directly:

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/update.sh -o /tmp/zedproxy-update.sh && chmod +x /tmp/zedproxy-update.sh && sudo bash /tmp/zedproxy-update.sh
```

The update script is logged to `/var/log/zedproxy-update.log`.

### Files to preserve when moving to another server

| Path | Why |
|------|-----|
| `.env` | App secrets and DB credentials |
| `storage/app/` | Uploads, backups, local files |
| `storage/logs/` | Application logs |
| PostgreSQL backup | All database data |

Do NOT copy `vendor/`, `node_modules/`, or `public/build/` - regenerate these with `composer install` and `npm run build`.

## Admin panel

Visit `/zed-admin` after creating an admin user.

**Login:** Use your **username** (not email) and password. The login URL is `/zed-admin/login`.

| Field | Value |
|-------|-------|
| Admin panel URL | `https://DOMAIN/zed-admin` |
| Login URL | `https://DOMAIN/zed-admin/login` |
| Login field | **Username** (not email) |

Email is stored on the user record for password resets and system contact but is **not** entered on the admin login form.

Current sections:

| Section | URL | Description |
|---------|-----|-------------|
| **Users** | `/zed-admin/users` | Manage users, view wallet balance, manually credit/debit wallet |
| **Plans** | `/zed-admin/plans` | Create/edit VPN plans with price, traffic, duration, features |
| **Features** | `/zed-admin/features` | Manage plan features (e.g. "بدون محدودیت سرعت") |
| **Locations** | `/zed-admin/locations` | Manage server locations with flag emoji |
| **Site Texts** | `/zed-admin/site-texts` | Edit all homepage/footer/legal texts stored in DB |
| **Orders** | `/zed-admin/orders` | View and manage orders; quick actions: mark processing, mark completed, cancel |
| **Transactions** | `/zed-admin/payment-transactions` | Approve or reject submitted manual payments with admin note |
| **Wallet Transactions** | `/zed-admin/wallet-transactions` | Read-only ledger of all wallet credits and debits |
| **Payment Methods** | `/zed-admin/payment-methods` | Manage payment methods (wallet, crypto, stars, rial) |
| **System Status** | `/zed-admin/system-status` | Live health checks for DB, Redis, storage, queue |
| **Services** | `/zed-admin/user-services` | View all user services; activate, disable, cancel, retry Marzban provisioning, sync usage |
| **VPN Panels** | `/zed-admin/vpn-panels` | Manage Marzban panels — add credentials, test connection, set default, open API docs |
| **VPN Inbounds** | `/zed-admin/vpn-inbounds` | Manage inbound tags linked to panels |

### Content that survives updates

All site content lives in the database — `update.sh` seeds only **missing** defaults and **never overwrites** admin-edited values:

| Content | Table | Admin URL |
|---------|-------|-----------|
| Homepage hero, features section, CTA | `site_texts` | `/zed-admin/site-texts` |
| VPN plan names, prices, descriptions | `plans` | `/zed-admin/plans` |
| Plan feature titles | `features` | `/zed-admin/features` |
| Server location names | `locations` | `/zed-admin/locations` |
| Footer text, legal pages | `site_texts` | `/zed-admin/site-texts` |
| Payment method titles, instructions, accounts | `payment_methods` | `/zed-admin/payment-methods` |

> **Note:** `update.sh` never resets admin passwords, site texts, plans, features, or locations.

### `site_setting()` helper

Use `site_setting('key', 'default')` anywhere in Blade or PHP to read a site text:

```php
{{ site_setting('homepage.hero.title', 'Default title') }}
```

Values are cached for 1 hour and auto-invalidated when the admin saves a change.

## Marzban integration

ZedProxy integrates with [Marzban](https://github.com/Gozargah/Marzban) to automatically create VPN users after payment.

### API docs reference

Marzban exposes Swagger UI at `/docs` and OpenAPI JSON at `/openapi.json` when `DOCS=True` is set.

Example panel used during development:
- `base_url`: `https://panel.staygreen.top`
- `api_docs_url`: `https://panel.staygreen.top/docs`

### Endpoints used (confirmed from Marzban source)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/api/admin/token` | OAuth2 login — form data `username`+`password` → `{access_token, token_type}` |
| `GET`  | `/api/system` | System stats — used to verify connection |
| `GET`  | `/api/inbounds` | List available inbounds by protocol |
| `POST` | `/api/user` | Create a new VPN user |
| `GET`  | `/api/user/{username}` | Get user details and subscription URL |
| `PUT`  | `/api/user/{username}` | Update user (traffic, expiry, status) |
| `DELETE` | `/api/user/{username}` | Delete user |
| `POST` | `/api/user/{username}/reset` | Reset traffic usage to zero |
| `POST` | `/api/user/{username}/revoke_sub` | Revoke subscription token (generates new URL) |

Auth: `Authorization: Bearer {access_token}` on all endpoints except `/api/admin/token`.

### Setting up a Marzban panel

1. Go to `/zed-admin/vpn-panels` → click **New Panel**
2. Fill in:
   - **Name**: e.g. `Main Marzban`
   - **Type**: `Marzban`
   - **Base URL**: `https://your-panel-domain:port`
   - **API Docs URL**: `https://your-panel-domain:port/docs`
   - **Username**: Marzban admin username
   - **Password**: Marzban admin password (stored encrypted)
   - **Active**: toggle on
   - **Default**: toggle on (services will auto-provision here)
3. Save, then click **تست اتصال** (Test Connection) in the table actions

### Inbounds

ZedProxy provisions users with `proxies: {vless: {}}` and does **not** send an `inbounds` key in the create/update payload. Marzban automatically assigns all available inbounds when the `inbounds` key is absent — no manual inbound configuration is required.

### How automatic provisioning works

1. User pays an order (wallet or admin-approved manual payment)
2. `PaymentService` calls `ServiceProvisioner::createFromOrder()` — idempotent
3. If a default active Marzban panel exists, `ProvisionMarzbanServiceJob` is dispatched to the queue
4. The job:
   - If `remote_username` is already set (retry scenario): GET user first; if found, update it
   - Otherwise creates the user — if Marzban returns 409 (duplicate), GET existing then update
   - Username format: `zpx_{user_id}_{service_id}_{random5}` (max 32 chars)
   - Sets `data_limit` from `traffic_total_gb` (bytes), `expire` from `expires_at` (Unix timestamp)
   - Saves `subscription_url` from the Marzban response to `user_services.subscription_link`
   - Saves `links[0]` as `config_link`, normalised traffic as `traffic_used_gb`
   - Sets service `status = active`, `provision_status = provisioned`
5. If no default panel exists, the service stays `provision_status = manual_required`

### Username format

```
zpx_{user_id}_{service_id}_{random5}
```

Examples: `zpx_1_42_ab3cd`, `zpx_7_103_xy9zw`

Rules: lowercase alphanumeric + underscores, max 32 characters, matches Marzban's `^[a-zA-Z0-9@_.+-]+$` validation.

### Subscription link

The Marzban `UserResponse` includes a `subscription_url` field (e.g. `https://panel.example.com/sub/TOKEN/`). ZedProxy saves this directly to `user_services.subscription_link`. It is shown to the user in `/dashboard/services/{service}` with a copy button and an inline SVG QR code (server-side, no CDN dependency).

The first link from `links[]` is saved as `config_link` (direct VLESS/VMess config). The user also sees this on their service detail page with its own QR code.

### QR code generation

ZedProxy uses [`simplesoftwareio/simple-qrcode`](https://www.simplesoftwareio.com/simple-qrcode) to generate inline SVG QR codes on the server. No JavaScript QR library or CDN is required. The QR code updates automatically when an admin uses **تغییر لینک اشتراک** (Revoke Subscription) — the new URL is saved to the database and the next page load shows the updated QR.

- **User-facing**: `/dashboard/services/{service}` — subscription link QR + config link QR (if set)
- **Admin-facing**: **مشاهده بارکد لینک اشتراک** action in UserServiceResource table — opens a Filament modal with subscription QR and optional config QR

### What the user sees

- **Active service with subscription link**: QR code + copy button for the subscription URL, plus config link QR if set
- **Pending/failed service**: Persian message: "سرویس شما هنوز آماده نشده است. در صورت طولانی شدن، با پشتیبانی تماس بگیرید."
- **No subscription link yet**: "لینک اشتراک هنوز آماده نشده است." message

Users can only see their **own** service pages. Accessing another user's service detail returns 403.

### User self-service Marzban actions

The service detail page at `/dashboard/services/{service}` includes a **مدیریت سرویس** (Service Management) section with user-facing actions. All actions are POST routes under `auth` middleware with global 30 req/min throttle.

| Route | Action | Persian label | Enabled by default |
|-------|--------|--------------|-------------------|
| `POST /dashboard/services/{service}/sync` | Sync from Marzban | بروزرسانی وضعیت سرویس | ✅ Yes |
| `POST /dashboard/services/{service}/revoke-subscription` | Revoke subscription | تغییر لینک اشتراک | ✅ Yes |
| `POST /dashboard/services/{service}/reset-traffic` | Reset traffic | ریست ترافیک | ❌ No (admin must enable) |
| `POST /dashboard/services/{service}/disable` | Disable service | غیرفعال‌سازی سرویس | ❌ No (admin must enable) |
| `POST /dashboard/services/{service}/enable` | Enable service | فعال‌سازی سرویس | ❌ No (admin must enable) |

**Revoke subscription** is additionally rate-limited to **once per 10 minutes per service** (configurable via `services.revoke_subscription_cooldown_seconds` setting). After the cooldown, the user sees: "برای تغییر مجدد لینک اشتراک کمی بعد دوباره تلاش کنید."

When a user revokes their subscription:
1. `POST /api/user/{username}/revoke_sub` is called on Marzban
2. Marzban generates a new subscription token
3. The new `subscription_url` is saved to `user_services.subscription_link`
4. The new `links[0]` is saved to `user_services.config_link`
5. The QR code on the page updates automatically on next load

Actions are only shown for **active services** with a `remote_username`. Inactive/pending services see: "این عملیات فقط برای سرویس‌های فعال قابل انجام است."

The enable button is shown for **disabled** services (only when the admin setting allows it).

### Admin settings for user self-service (SiteText, group: `services`)

| Key | Default | Label |
|-----|---------|-------|
| `services.allow_user_revoke_subscription` | `true` | اجازه تغییر لینک اشتراک توسط کاربر |
| `services.allow_user_sync_service` | `true` | اجازه بروزرسانی وضعیت سرویس توسط کاربر |
| `services.allow_user_reset_traffic` | `false` | اجازه ریست ترافیک توسط کاربر |
| `services.allow_user_disable_service` | `false` | اجازه غیرفعال‌سازی سرویس توسط کاربر |
| `services.allow_user_enable_service` | `false` | اجازه فعال‌سازی سرویس توسط کاربر |
| `services.revoke_subscription_cooldown_seconds` | `600` | فاصله زمانی تغییر لینک اشتراک (ثانیه) |

Settings are seeded via `ServiceSettingsSeeder` using `firstOrCreate` — admin-edited values are **never overwritten** by future deploys.

To change a setting: open `/zed-admin/site-texts` and edit the relevant key, or update the value directly in the database.

### What remains admin-only

The following actions are **never** exposed to users:

- **حذف از مرزبان** (Delete Remote User) — irreversible, admin-only
- **ساخت دوباره در مرزبان** (Recreate Remote User) — admin-only
- **پاک کردن لینک‌های محلی** (Clear Local Links) — admin-only
- Admin notes, provision logs, panel credentials, VPN panel details
- Any route modification or username change

### Admin actions on UserServiceResource

The admin panel at `/zed-admin/user-services` has the full action set (admin-only):

| Action | Description |
|--------|-------------|
| **ساخت دوباره در مرزبان** (Recreate) | Runs `ProvisionMarzbanServiceJob` synchronously; creates or updates the Marzban user; visible when provision_status is failed, manual_required, or skipped |
| **همگام‌سازی از Marzban** (Sync) | Calls `GET /api/user/{username}`, updates traffic, subscription link, config link, and expiry; visible when remote_username is set |
| **ریست ترافیک** (Reset Traffic) | Calls `POST /api/user/{username}/reset`; resets used traffic on panel and locally to 0; visible when remote_username is set |
| **تغییر لینک اشتراک** (Revoke Subscription) | Calls `POST /api/user/{username}/revoke_sub`; Marzban generates a new subscription token; saves new URL locally; QR updates on next page load |
| **غیرفعال کردن** (Disable) | Calls `PUT /api/user/{username}` with `{status: disabled}`; sets local status to disabled; visible when service is active |
| **فعال کردن** (Enable) | Calls `PUT /api/user/{username}` with `{status: active}`; sets local status to active; visible when service is disabled |
| **حذف از مرزبان** (Delete Remote) | Calls `DELETE /api/user/{username}`; removes user from Marzban panel; nulls subscription_link and config_link; sets status to cancelled; local service record is kept |
| **پاک کردن لینک‌های محلی** (Clear Local Links) | Nulls subscription_link and config_link in local DB only; no Marzban API call; useful after manual panel changes |
| **مشاهده بارکد لینک اشتراک** (View QR) | Opens a Filament modal with the subscription QR code (220px SVG) and optional config QR; visible when subscription_link is set |

### What happens if provisioning fails

- Service `provision_status` is set to `failed`
- Error message is saved to `admin_notes`
- A `VpnServiceProvisionLog` entry is created with `status = failed`
- The order is **not** affected — payment remains approved
- Admin can click **تلاش مجدد Marzban** from the service table to retry

### Queue worker

Provisioning jobs run via the queue worker (Supervisor). On production:

```bash
sudo supervisorctl status zedproxy-worker:*
sudo supervisorctl restart zedproxy-worker:*
```

### Security notes

- Marzban admin password is stored encrypted using Laravel's `encrypted` cast (`APP_KEY` is the secret)
- Access tokens are cached in Redis only — never stored in plaintext in the database
- Token is rotated on 401 response (retry-once logic)
- Users cannot see panel credentials or admin-only API data
- The `subscription_url` is the only Marzban data exposed to regular users

Upcoming sections (in future development phases):

- Payment gateway — Rial/crypto integration
- Telegram bot — admin reports and notifications
- Ticket system — support tickets
- Monitoring — live server status
- Renew / extra traffic — update Marzban user after renewal

## Updating ZedProxy

The `update.sh` script performs a safe, zero-data-loss update of a running ZedProxy installation.

### One-command update

```bash
curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/update.sh -o /tmp/zedproxy-update.sh && chmod +x /tmp/zedproxy-update.sh && sudo bash /tmp/zedproxy-update.sh
```

Or use the shortcut installed automatically by the installer:

```bash
zedproxy-update
```

### What the update script does

1. **Verifies** the project exists at `/var/www/zedproxy` before touching anything
2. **Creates a backup** in `/var/backups/zedproxy/updates/YYYYMMDD_HHMMSS/`:
   - Current commit hash
   - `.env` copy (permissions 600)
   - PostgreSQL full dump (`pg_dump -Fc`) — update is aborted if this fails
3. **Enables maintenance mode** (`php artisan down --render="errors::503"`)
4. **Pulls latest code** (`git fetch + reset --hard origin/main + clean -fd`)
5. `composer install --no-interaction --prefer-dist --optimize-autoloader`
6. `npm ci` (falls back to `npm install`), then `npm run build`
7. `php artisan migrate --force`
8. Seeds missing defaults: `SiteTextSeeder`, `FeatureSeeder`, `LocationSeeder`, `PlanSeeder`, `PaymentMethodSeeder` — all use `firstOrCreate` (never overwrites admin-edited values)
9. `php artisan storage:link`
10. `php artisan optimize:clear` + `config:cache` + `route:cache` + `view:cache`
11. **Disables maintenance mode** (`php artisan up`) — also runs on error
12. Restarts PHP-FPM, Supervisor workers, reloads Nginx
13. **Health check** (HTTP + HTTPS if SSL is active)
14. Prunes update backups older than 30 most recent

### What is preserved through updates

| What | How |
|------|-----|
| `.env` (secrets, DB credentials, APP_URL) | `git reset --hard` never touches `.env` — it is in `.gitignore` |
| `storage/` (uploads, backups, logs) | Never deleted or reset by git |
| `public/storage` symlink | Re-created with `storage:link` (idempotent) |
| PostgreSQL database | Only migrated forward — never dropped or reset |
| Admin-edited site texts | `SiteTextSeeder` uses `firstOrCreate` — never updates existing values |
| Admin-edited plans | `PlanSeeder` uses `firstOrCreate` by slug — never overwrites |
| Admin-edited features | `FeatureSeeder` uses `firstOrCreate` by slug — never overwrites |
| Admin-edited locations | `LocationSeeder` uses `firstOrCreate` by `country_code` — never overwrites |
| SSL/Nginx config | Not touched by `update.sh` |

### Rollback

If an update goes wrong, the final summary prints the rollback commands:

```bash
# Revert to previous code
cd /var/www/zedproxy
git reset --hard <previous-commit-hash>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize:clear

# Restore DB if migrations ran (replace placeholders with values from backup dir)
PGPASSWORD=<db_password> pg_restore \
    -h 127.0.0.1 -p 5432 \
    -U <db_user> -d <db_name> \
    --clean --if-exists \
    /var/backups/zedproxy/updates/<TIMESTAMP>/<db_name>_<TIMESTAMP>.dump

php artisan up
```

### Update log

```bash
sudo tail -n 120 /var/log/zedproxy-update.log
```

## User dashboard and order system

### User routes

| Route | Name | Description |
|-------|------|-------------|
| `GET /dashboard` | `dashboard.index` | User dashboard — shows wallet balance, pending payments, recent orders |
| `GET /dashboard/orders` | `dashboard.orders` | All user orders |
| `GET /dashboard/orders/{order}` | `dashboard.orders.show` | Single order detail with Pay button |
| `GET /dashboard/orders/{order}/pay` | `dashboard.orders.pay` | Choose payment method and submit payment |
| `POST /dashboard/orders/{order}/pay` | `dashboard.orders.pay.submit` | Submit payment (wallet debit or manual submission) |
| `GET /dashboard/wallet` | `dashboard.wallet` | Wallet balance and transaction ledger |
| `GET /dashboard/services` | `dashboard.services` | User services list — status, traffic, expiry |
| `GET /dashboard/services/{service}` | `dashboard.services.show` | Service detail — config link, traffic bar, expiry, related order |
| `GET /dashboard/profile` | `dashboard.profile` | User profile (read-only) |
| `POST /plans/{plan}/buy` | `plans.buy` | Create order for a plan (auth required) |

Legacy `/panel/*` routes redirect to `/dashboard/*` (301 permanent).

### Buy flow

1. User visits `/plans`
2. If not logged in: buy button says "ورود برای خرید" and links to `/login`
3. If logged in: buy button is a POST form → `POST /plans/{plan}/buy`
4. Server validates plan is active, creates an `Order` with a snapshot of plan data at purchase time
5. Redirects to `/dashboard/orders/{order}`
6. Order detail page shows a **Pay** button when the order is unpaid

### Payment flow

**Wallet payment:**
1. User selects "کیف پول" method on the pay page
2. If wallet balance ≥ order price: atomic debit + immediate approval, order becomes `paid`
3. If balance insufficient: error shown, no transaction created

**Manual payment (crypto / Telegram Stars / Rial transfer):**
1. User selects a manual method, sees instructions and account details
2. User enters TXID / transaction reference and optional note
3. Submits form → `PaymentTransaction` created with status `submitted`, order becomes `awaiting_payment`
4. Admin reviews in Filament → approve (marks order paid) or reject (reverts order to pending)

### Order data model

Orders store a **snapshot** of plan data at purchase time — plan name, slug, traffic, duration, and price. Changing the plan in the admin panel does **not** affect existing orders.

**Order statuses:** `pending` → `awaiting_payment` → `paid` → `processing` → `completed` (or `cancelled`/`failed`)

**Payment statuses:** `unpaid`, `pending`, `paid`, `failed`, `refunded`

### Wallet / ledger

Every change to a user's wallet balance creates a `WalletTransaction` record (append-only). Wallet balance is only modified via `WalletService::credit()` / `debit()`, which use `lockForUpdate()` to prevent race conditions.

**Transaction types:** `manual_credit`, `manual_debit`, `order_payment`, `refund`, `adjustment`

### Payment methods (seeded defaults)

| Slug | Type | Description |
|------|------|-------------|
| `wallet` | `wallet` | Internal wallet balance |
| `manual-crypto` | `manual_crypto` | USDT / TRC20 manual transfer |
| `manual-stars` | `manual_stars` | Telegram Stars via bot |

Admin can create additional methods and edit titles, instructions, and account values without code changes.

### Admin order management

| Admin route | Description |
|-------------|-------------|
| `/zed-admin/orders` | List all orders, filter by status/payment_status |
| `/zed-admin/orders` | Quick actions: mark processing, mark completed, cancel with note, **create service** (paid orders without a service) |
| `/zed-admin/orders/{id}/edit` | Edit order status, payment status, timestamps, admin notes |
| `/zed-admin/payment-transactions` | Approve or reject submitted payments with admin note |
| `/zed-admin/wallet-transactions` | Read-only ledger of all wallet debits and credits |
| `/zed-admin/users` | Credit or debit any user's wallet with a reason |

### What is preserved through updates

Orders, wallet transactions, payment transactions, and payment methods are never deleted by `update.sh` or seeders. The `--force` migrate only runs forward migrations. `PaymentMethodSeeder` uses `firstOrCreate` — admin-edited method titles, instructions, and account values are never overwritten.

## Service lifecycle

### Service statuses

| Status | Description |
|--------|-------------|
| `pending_provision` | Service created but not yet provisioned on a VPN panel |
| `active` | Provisioned and in use |
| `disabled` | Temporarily suspended by admin |
| `expired` | Past the `expires_at` date |
| `cancelled` | Cancelled before activation |
| `failed` | Provisioning failed |

### Provision statuses

| Status | Description |
|--------|-------------|
| `pending` | Waiting for provisioning to start |
| `manual_required` | Requires admin action (or Marzban panel not configured) |
| `provisioned` | Successfully linked to a VPN panel |
| `failed` | Provisioning error |
| `skipped` | Skipped (no API integration active) |

### Service creation flow

1. User pays an order (wallet or approved manual payment)
2. `PaymentService` calls `ServiceProvisioner::createFromOrder()` — idempotent, safe to call twice
3. A `UserService` record is created with `status=pending_provision`, `provision_status=manual_required`
4. A `VpnServiceProvisionLog` entry is written: `action=create_placeholder_service`, `status=skipped`
5. Admin activates the service manually in the admin panel → dates computed, status set to `active`

### Admin service management

| Admin route | Description |
|-------------|-------------|
| `/zed-admin/user-services` | List all services with status, traffic, expiry |
| `/zed-admin/user-services/{id}/edit` | Edit service: status, config link, subscription link, VPN panel assignment, admin notes |
| Quick action: Activate | Sets status to `active`, computes `starts_at` / `expires_at`, logs `manual_activate` |
| Quick action: Disable | Sets status to `disabled`, logs `manual_disable` |
| Quick action: Cancel | Sets status to `cancelled`, logs `manual_cancel` |

### Artisan commands

```bash
# Mark expired services (run daily via cron or Supervisor)
php artisan services:expire
```

Add to cron for daily expiry checks:

```bash
echo "0 1 * * * www-data php /var/www/zedproxy/artisan services:expire >> /var/log/zedproxy-expire.log 2>&1" \
    | sudo tee /etc/cron.d/zedproxy-expire
```

### VPN panel placeholders

`VpnPanel` and `VpnInbound` models and migrations are in place for future Marzban / 3x-ui integration. Admin resources exist at:

- `/zed-admin/vpn-panels` — add VPN panel connection details (name, type, URL, credentials)
- `/zed-admin/vpn-inbounds` — add inbounds linked to a panel (protocol, port, network, security)

> **No real VPN API connection is active.** Panels and inbounds can be configured in the admin but ZedProxy does not yet call any external VPN API. Automatic service provisioning via Marzban or 3x-ui is planned for a future phase.

### Service data preserved through updates

User services, provision logs, VPN panels, and VPN inbounds are **never deleted** by `update.sh` or seeders. Admin-edited config links, admin notes, and activation dates survive all updates.

## What's next

**Completed:**
- Plans, Features, Locations admin CRUD — fully DB-backed
- Site texts system with `site_setting()` helper
- Public plans page at `/plans` — active plans with buy buttons
- Update-safe seeders (`firstOrCreate` — never overwrites admin edits)
- User login/register with username auth
- User dashboard at `/dashboard` — active services, pending services, wallet balance, recent orders
- Order system with plan snapshot storage
- Payment methods model with seeded defaults (wallet, crypto, stars)
- Wallet system — atomic balance management via `WalletService` with pessimistic locking
- Wallet payment — immediate debit + order approval if balance sufficient
- Manual payment submission — user submits TXID/reference, admin approves/rejects in Filament
- Admin approve/reject with admin note — `PaymentService` handles idempotent approval
- Admin wallet management — credit/debit any user's wallet from UserResource
- Wallet ledger — `WalletTransaction` append-only ledger, visible to user and admin
- User wallet page at `/dashboard/wallet`
- Admin payment method management at `/zed-admin/payment-methods`
- **UserService model** — full service lifecycle (pending_provision → active → disabled/expired/cancelled)
- **Service auto-creation** — `ServiceProvisioner` called automatically on payment approval (idempotent)
- **User services pages** — `/dashboard/services` list and `/dashboard/services/{service}` detail
- **Admin UserServiceResource** — activate, disable, cancel actions; full CRUD; provision logs
- **VpnPanel** — model, migration, Filament resource with Test Connection, Refresh Token, Mark Default, Open API Docs actions
- **VpnInbound** — model, migration, Filament resource linked to panels
- **VpnServiceProvisionLog** — append-only log of all lifecycle events
- **`services:expire` command** — bulk-marks active services past their expiry date
- **Dashboard updated** — shows active service count, pending service count, services quick link
- **Order detail updated** — shows link to related service when it exists
- **Marzban API client** — `MarzbanClient` with login/testConnection/createUser/getUser/updateUser/deleteUser/resetTraffic/revokeSubscription; token cached in Redis; retry-on-401; never logs tokens or passwords
- **`ProvisionMarzbanServiceJob`** — queued job; idempotent (update if user exists, create if not); 409-conflict handled (get+update); saves subscription_url and config_link from Marzban response
- **Automatic provisioning** — `ServiceProvisioner::createFromOrder()` dispatches `ProvisionMarzbanServiceJob` when a default active Marzban panel is configured
- **Full admin action set** in UserServiceResource — Recreate, Sync, Reset Traffic, Revoke Subscription (تغییر لینک اشتراک), Disable, Enable, Delete Remote, Clear Local Links, View Subscription QR
- **Server-side QR codes** — `simplesoftwareio/simple-qrcode` generates inline SVG QR for subscription and config links; no CDN or JavaScript dependency; QR updates automatically after revoke subscription
- **Subscription + config link display** — user sees subscription URL with copy button and QR code, plus config link QR at `/dashboard/services/{service}`; admin sees QR in modal via **مشاهده بارکد لینک اشتراک**
- **User self-service Marzban actions** — `UserServiceActionController` with 5 POST routes (sync, revoke-subscription, reset-traffic, disable, enable); ownership enforced; throttled; API failures return flash errors, never crash
- **Admin-controlled feature flags** — `ServiceSettingsSeeder` seeds 6 `SiteText` settings (group: `services`); sync+revoke enabled by default; reset/disable/enable disabled by default; admin edits via `/zed-admin/site-texts`
- **Per-service revoke rate limit** — revoke subscription limited to once per 10 minutes per service (configurable); excess attempts get Persian error message
- **Provision logs for user actions** — every user action creates a `VpnServiceProvisionLog` with action prefix `user_marzban_*`; no tokens or credentials logged
- **Per-VPN-panel user self-service toggles** — 10 boolean columns on `vpn_panels` control which actions users can perform per panel (sync, revoke, reset-traffic, disable, enable, view QR, copy links); defaults match previous global settings
- **Auto-sync on service detail view** — `/dashboard/services/{service}` syncs from Marzban if never synced or last sync >30s ago; graceful failure with warning banner
- **NOWPayments crypto payment gateway** — hosted invoice mode (default, customer chooses currency on NOWPayments) and direct mode; IPN webhook at `POST /webhooks/nowpayments` with HMAC-SHA512 signature verification; IPN matching by invoice_id → provider_reference; manual status check; auto-provisioning on `finished`; currency conversion IRT→USD via admin-configured exchange rate; `api_key` and `ipn_secret` stored encrypted; QR code on payment detail page; 48 automated tests
- **CentralPay rial payment gateway** — server-to-server verify (never trusts GET alone); amount in Toman; orderId = payment_transactions.id (avoids duplicate_orderId on retries); idempotent (reuses active tx gateway_url); amount mismatch and userId mismatch detection; card number masked before storage (first6 + ****** + last4); api_key never exposed in UI/logs; admin verify action in Filament; 37 automated tests

**Next:**
1. Renew / extra traffic — extend Marzban user expiry or add data via order
2. 3x-ui / Sanaei 3x-ui integration — same flow, different panel type
3. Ticket system — support ticket model and panel
4. Telegram bot — admin reports and notifications
5. Email — order confirmations, expiry reminders
6. Docker deployment — containerized installation

## NOWPayments crypto gateway

ZedProxy integrates with [NOWPayments](https://nowpayments.io) to accept cryptocurrency payments automatically. The gateway handles invoice creation, IPN webhook verification, and auto-provisioning on confirmed payment.

### Payment modes

There are two modes, configurable per payment method:

| Mode | How it works |
|------|-------------|
| **Invoice (default)** | ZedProxy creates a hosted invoice via `POST /v1/invoice`. Customer is redirected to the NOWPayments checkout page and **chooses the crypto currency there**. ZedProxy does not need to know the currency in advance. |
| **Direct** | ZedProxy creates a payment via `POST /v1/payment` with a specific `pay_currency`. Customer pays to the wallet address displayed directly on ZedProxy. |

**Invoice mode is recommended.** It requires no currency config on ZedProxy and lets customers choose from all currencies NOWPayments supports.

### How it works (invoice mode)

1. Admin enables the NOWPayments payment method, sets mode to **Invoice** (default)
2. User selects "پرداخت کریپتو (NOWPayments)" at checkout and clicks "تایید و پرداخت"
3. ZedProxy calls `POST /v1/invoice` — **no crypto currency needed at this point**
4. User is redirected to the hosted NOWPayments checkout page (`invoice_url`)
5. User chooses currency and network on NOWPayments, completes the payment
6. NOWPayments sends an IPN webhook when the payment status changes
7. The IPN delivers both `invoice_id` and `payment_id` — ZedProxy matches by `invoice_id` and stores `payment_id` in `external_id`
8. When status is `finished`, ZedProxy marks the order paid and provisions the VPN service automatically
9. User can also click "بررسی وضعیت پرداخت" to manually poll — only works after the customer has started paying (i.e., after the first IPN with a `payment_id`)

### How it works (direct mode)

1. Admin sets mode to **Direct**, sets `default_pay_currency` (e.g. `usdttrc20`)
2. User selects the payment method; ZedProxy calls `POST /v1/payment` with the configured currency
3. User sees the wallet address, exact amount, QR code, and expiry on `/dashboard/orders/{order}/nowpayments`
4. User pays; IPN webhook updates status; `finished` provisions the service

### Setup (admin)

1. Go to `/zed-admin/payment-methods` → **New**
2. Set **Type** to `NOWPayments (کریپتو)`
3. Fill in the NOWPayments configuration section:

| Field | Description |
|-------|-------------|
| **API Key** | From [NOWPayments dashboard](https://nowpayments.io) → API Keys. Stored encrypted. |
| **IPN Secret** | From NOWPayments dashboard → API Keys → IPN Secret. Stored encrypted. |
| **Payment mode** | `Invoice` (recommended) — customer chooses currency on NOWPayments; `Direct` — currency fixed in advance |
| **Sandbox mode** | Enable for testing — uses `api-sandbox.nowpayments.io`. Disable for production. |
| **Site currency** | `IRT` (Toman) or `IRR` (Rial) — the currency your order prices are in |
| **Exchange rate (Toman/USD)** | Manual exchange rate. Example: `75000` means 75,000 Toman = 1 USD |
| **Price currency** | `usd` (default) — the currency NOWPayments converts to when creating the invoice |
| **Default pay currency** | *(Direct mode only)* Crypto to pay with, e.g. `usdttrc20`, `btc`, `eth` |
| **Allowed pay currencies** | *(Direct mode only)* Comma-separated list shown to user, e.g. `btc,eth,usdttrc20,ltc` |
| **IPN Callback URL** | Leave empty — ZedProxy auto-fills with `/webhooks/nowpayments` |
| **Success URL** | Optional redirect after successful payment (defaults to order detail page) |
| **Cancel URL** | Optional redirect on cancelled payment (defaults to payment selection page) |
| **Base URL** | Leave empty for auto-detection by sandbox toggle |

4. Set **Active** to enabled
5. Save

### Webhook URL

Tell NOWPayments your IPN callback URL:

```
https://yourdomain.com/webhooks/nowpayments
```

This is filled automatically when you submit a payment if `ipn_callback_url` is empty in the config.

### Currency conversion

ZedProxy prices are in Toman (IRT) but NOWPayments expects USD (or another supported currency).

The admin must set `exchange_rate_usd` — the number of Toman per 1 USD. Example: if the rate is 75,000 Toman/USD:

```
order.final_price_toman ÷ 75,000 = USD price sent to NOWPayments
```

Update the exchange rate regularly from the admin panel to keep prices accurate.

### IPN matching (invoice mode)

In invoice mode, NOWPayments sends an `invoice_id` alongside the `payment_id` in each IPN. ZedProxy matches transactions in this priority:

1. `invoice_id` → `provider_reference` (the invoice id stored when the invoice was created)
2. `payment_id` → `provider_reference` or `external_id` (for direct mode or subsequent IPNs)
3. `order_id` → `order_id` column (last resort)

The `payment_id` is stored in `external_id` the first time it appears in an IPN. This allows "بررسی وضعیت پرداخت" to call `GET /v1/payment/{payment_id}` for live status.

### Supported NOWPayments statuses

| NOWPayments status | ZedProxy action |
|--------------------|-----------------|
| `waiting` | Transaction status = `waiting`, order stays pending |
| `confirming` | Transaction status = `confirming`, order stays pending |
| `confirmed` | Transaction status = `confirming`, order stays pending |
| `sending` | Transaction status = `confirming`, order stays pending |
| `partially_paid` | Transaction status = `partially_paid`, order stays pending |
| `finished` | **Order marked paid, VPN service provisioned automatically** |
| `failed` | Transaction status = `failed` |
| `refunded` | Transaction status = `refunded` |
| `expired` | Transaction status = `expired` |

**Only `finished` triggers provisioning.** This is intentional — blockchain confirmations take time and partial/in-progress payments must not grant access.

### IPN signature verification

Every IPN request from NOWPayments is verified before processing:

1. Read `x-nowpayments-sig` header
2. Sort all payload keys alphabetically
3. JSON-encode with sorted keys
4. Sign with `HMAC-SHA512` using `ipn_secret`
5. Compare with `hash_equals()` (constant-time, prevents timing attacks)

Requests with missing or invalid signatures are rejected with `401` and logged (without exposing the secret).

### Manual status check

Users and admins can manually check payment status without waiting for an IPN:

- **User**: click "بررسی وضعیت پرداخت" on the payment detail page
- **Admin**: click "بررسی وضعیت NOWPayments" in `/zed-admin/payment-transactions`

Both call `GET /v1/payment/{payment_id}` on the NOWPayments API and update the transaction in real time.

> **Invoice mode note**: The manual status check requires a `payment_id` (stored in `external_id`). This is only available after the customer has chosen a currency and started paying on the NOWPayments page. Before that, the check returns "پرداخت هنوز توسط کاربر انتخاب/شروع نشده است" and the user should complete payment on NOWPayments first.

### Security

- `api_key` and `ipn_secret` are stored with Laravel's `encrypted` cast — encrypted at rest using `APP_KEY`
- Neither field appears in admin table views or JSON responses
- Credentials are never logged — not in API calls, IPN handling, or error messages
- IPN is verified before any database update
- Order ownership is verified on every user-facing action
- Duplicate IPN calls are idempotent — provisioning runs at most once per order

### Sandbox / production

| Mode | Base URL |
|------|----------|
| Sandbox (testing) | `https://api-sandbox.nowpayments.io/v1` |
| Production | `https://api.nowpayments.io/v1` |

Enable sandbox in the admin config field. Disable it when going live.

### Troubleshooting

**"نرخ تبدیل دلار تنظیم نشده است"**
→ Set `exchange_rate_usd` in the payment method config to a positive value.

**"ساخت فاکتور NOWPayments انجام نشد"**
→ The NOWPayments API returned a response without an `invoice_url`. Check your API key, ensure the method is not in Direct mode when you expect a hosted invoice, and verify the NOWPayments account is active.

**"مبلغ سفارش برای پرداخت با NOWPayments کمتر از حداقل مجاز"**
→ The order total converted to USD is below NOWPayments' minimum allowed amount. Increase the order price or switch to a crypto with a lower minimum amount.

**"پرداخت هنوز توسط کاربر انتخاب/شروع نشده است"**
→ In invoice mode, the manual status check only works after the customer has chosen a currency on the NOWPayments page. Tell the customer to complete the payment first, then check status.

**IPN not received**
→ Ensure the webhook URL `https://yourdomain.com/webhooks/nowpayments` is reachable from the internet. Check that your firewall or Cloudflare does not block `POST` requests to that path.

**Payment shows "waiting" indefinitely**
→ Click "بررسی وضعیت پرداخت" to manually poll. Or check the NOWPayments dashboard for the payment status. If the payment expired, the user must start a new payment.

**Sandbox payments not completing**
→ Use the NOWPayments sandbox dashboard to simulate status changes, or manually change the transaction status in the admin panel.

**Admin transactions page**
→ Go to `/zed-admin/payment-transactions`, find the transaction (provider = nowpayments), and use:
- "بررسی وضعیت NOWPayments" — polls live status from API
- "پاسخ درگاه" — shows the raw (sanitized) API response JSON

## CentralPay rial payment gateway

ZedProxy integrates with [CentralPay](https://centralapi.org) to accept rial (Toman) payments from Iranian users.

### How it works

1. User selects "پرداخت ریالی" at checkout and clicks "تایید و پرداخت"
2. ZedProxy calls `POST .../getLink.php` to create a payment link
3. User is redirected to the CentralPay payment page
4. After payment, CentralPay redirects back to ZedProxy's callback URL
5. ZedProxy immediately calls `POST .../verify.php` server-to-server — the GET callback alone is never trusted
6. On successful verify, the order is marked paid and the VPN service is provisioned automatically

### Setup (admin)

Add these to your `.env`:

```env
CENTRALPAY_ENABLED=true
CENTRALPAY_API_KEY=your_centralpay_api_key
CENTRALPAY_BASE_URL=https://centralapi.org/webservice/basic
CENTRALPAY_TYPE=deposit
CENTRALPAY_AMOUNT_UNIT=TOMAN
CENTRALPAY_CALLBACK_PATH=/payments/centralpay/callback
```

Then enable the payment method in the admin panel:

1. Go to `/zed-admin/payment-methods`
2. Find "پرداخت ریالی" (slug: `centralpay`)
3. Toggle **Active** on
4. Save

The CentralPay payment method is seeded as **inactive by default**. It will not appear in checkout until you activate it and set `CENTRALPAY_ENABLED=true` in `.env`.

### Callback URL

Register this URL in your CentralPay merchant panel:

```
https://yourdomain.com/payments/centralpay/callback
```

ZedProxy uses a GET callback for redirect only — all verification is done server-to-server via POST to `/verify.php`.

### Amount

ZedProxy stores prices in Toman. The amount is sent to CentralPay as an integer in Toman with no conversion. Example: an order with `final_price_toman = 200000` sends `amount = 200000` to CentralPay.

### orderId and idempotency

ZedProxy uses `payment_transactions.id` (not `orders.id`) as the CentralPay `orderId`. This avoids the `duplicate_orderId` error from CentralPay when a user retries payment for the same order — each retry creates a new `PaymentTransaction` record with a new `id`.

If a CentralPay payment is already pending (status `pending` or `waiting`, `gateway_url` set), the user is redirected to the existing payment URL without calling `getLink.php` again. This prevents duplicate payment sessions.

### Verify behavior

| Condition | Result |
|-----------|--------|
| Verify API call fails (HTTP error) | Error shown to user; tx stays pending |
| `verify.data.status` ≠ success | Error shown; tx marked failed; `failure_reason` saved |
| Amount in verify ≠ `gateway_amount` | `gateway_status = amount_mismatch`; **NOT marked paid**; user sees error |
| userId in verify ≠ `order.user_id` | `gateway_status = user_mismatch`; **NOT marked paid** |
| Already paid (idempotency guard) | Redirected to order page; verify NOT called again |
| All checks pass | Order marked paid; VPN service provisioned; `gateway_status = verified` |

### Card number masking

CentralPay returns the user's card number in the verify response. ZedProxy masks it before storage:

```
1111222233334444  →  111122******4444
```

The masked number is stored in `response_payload` JSON. The raw card number is never saved to the database or logged.

### Security

- `CENTRALPAY_API_KEY` is read from `config('services.centralpay.api_key')` only — never stored in the payment methods table
- The api_key is stripped from all stored payloads (`request_payload`, `response_payload`)
- The api_key is never logged or exposed in UI, tables, or API responses
- Card numbers are masked before storage (first 6 + `******` + last 4)
- The GET callback is never trusted without a server-to-server POST verify
- Duplicate provisioning is prevented — verify is skipped for already-paid orders

### Admin actions

Go to `/zed-admin/payment-transactions`, filter by provider `centralpay`:

| Action | Description |
|--------|-------------|
| **بررسی وضعیت CentralPay** | Calls `/verify.php` server-to-server and processes the result |

The admin verify action is visible for CentralPay transactions that are not yet in a terminal state (`verified`, `amount_mismatch`, `user_mismatch`) and whose order is not already paid.

### Troubleshooting

**"درگاه CentralPay فعال نیست"**
→ Set `CENTRALPAY_ENABLED=true` in `.env` and activate the payment method in admin.

**"خطا در ایجاد لینک پرداخت CentralPay"**
→ Check your `CENTRALPAY_API_KEY` and `CENTRALPAY_BASE_URL`. Inspect `storage/logs/laravel.log` for the sanitized error response.

**"مبلغ تاییدشده با مبلغ سفارش مطابقت ندارد"**
→ The verified amount from CentralPay does not match the stored gateway amount. The transaction is marked `amount_mismatch` and the order is NOT paid. Contact the user and check the CentralPay merchant dashboard.

**"خطا در تایید پرداخت CentralPay"** / **"پرداخت CentralPay انجام نشد"**
→ CentralPay returned a non-success status. Check `failure_reason` in the transaction record for details.

**Payment shows pending but user was charged**
→ Use the "بررسی وضعیت CentralPay" action in `/zed-admin/payment-transactions` to manually trigger server-side verify.

**`duplicate_orderId` error from CentralPay**
→ This should not occur — ZedProxy uses `payment_transactions.id` as orderId, which is unique per payment attempt. If it does occur, check for stale pending transactions and mark them failed before retrying.
