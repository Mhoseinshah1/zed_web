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
| **Users** | `/zed-admin/users` | Manage admin and regular users |
| **Plans** | `/zed-admin/plans` | Create/edit VPN plans with price, traffic, duration, features |
| **Features** | `/zed-admin/features` | Manage plan features (e.g. "بدون محدودیت سرعت") |
| **Locations** | `/zed-admin/locations` | Manage server locations with flag emoji |
| **Site Texts** | `/zed-admin/site-texts` | Edit all homepage/footer/legal texts stored in DB |
| **System Status** | `/zed-admin/system-status` | Live health checks for DB, Redis, storage, queue |

### Content that survives updates

All site content lives in the database — `update.sh` seeds only **missing** defaults and **never overwrites** admin-edited values:

| Content | Table | Admin URL |
|---------|-------|-----------|
| Homepage hero, features section, CTA | `site_texts` | `/zed-admin/site-texts` |
| VPN plan names, prices, descriptions | `plans` | `/zed-admin/plans` |
| Plan feature titles | `features` | `/zed-admin/features` |
| Server location names | `locations` | `/zed-admin/locations` |
| Footer text, legal pages | `site_texts` | `/zed-admin/site-texts` |

> **Note:** `update.sh` never resets admin passwords, site texts, plans, features, or locations.

### `site_setting()` helper

Use `site_setting('key', 'default')` anywhere in Blade or PHP to read a site text:

```php
{{ site_setting('homepage.hero.title', 'Default title') }}
```

Values are cached for 1 hour and auto-invalidated when the admin saves a change.

Upcoming sections (in future development phases):

- Payment gateway — Rial/crypto integration
- Marzban API — automatic VPN service creation
- Telegram bot — admin reports and notifications
- Ticket system — support tickets
- User dashboard — order history, active services
- Monitoring — live server status

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
8. `php artisan db:seed --class=SiteTextSeeder` — inserts **only missing** site text defaults (never overwrites admin-edited values)
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

## What's next

**Completed:**
- Plans, Features, Locations admin CRUD — fully DB-backed
- Site texts system with `site_setting()` helper
- Public plans page at `/plans` — shows active plans, features, locations
- Update-safe seeders (`firstOrCreate` — never overwrites admin edits)

**Next:**
1. Payment gateway — Rial/crypto integration
2. Marzban integration — API client, auto VPN service creation
3. Order flow — checkout, invoicing
4. Ticket system — support ticket model and panel
5. Subscription links — V2Ray subscription URL generation
6. Telegram bot — admin reports and notifications
7. Email — order confirmations, expiry reminders
8. Docker deployment — containerized installation
