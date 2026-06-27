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
| Final website URL | `https://DOMAIN` |
| Admin email | `admin@DOMAIN` |
| Admin name/username | `zedadmin_RANDOM` (e.g. `zedadmin_a83f21`) |
| Admin password | Strong 24-char random password |

After all questions are answered, a 3-second countdown lets you cancel with Ctrl+C before anything is installed.

The admin user is created automatically. **Credentials are only shown once at the end of a successful installation.**  If installation fails at any step, the admin password is not printed.

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

Use the dedicated Artisan command (safe to re-run — updates the user if the email already exists):

```bash
php artisan zedproxy:create-admin \
    --email="admin@yourdomain.com" \
    --name="Admin" \
    --password="your_secure_password"
```

Then log in at `/admin`.

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
    --name="Admin" \
    --password="your_password"
```

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

After installation, add SSL with Certbot:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### After code update

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
sudo supervisorctl restart zedproxy-worker:*
```

### Files to preserve when moving to another server

| Path | Why |
|------|-----|
| `.env` | App secrets and DB credentials |
| `storage/app/` | Uploads, backups, local files |
| `storage/logs/` | Application logs |
| PostgreSQL backup | All database data |

Do NOT copy `vendor/`, `node_modules/`, or `public/build/` - regenerate these with `composer install` and `npm run build`.

## Admin panel

Visit `/admin` after creating an admin user. Current sections:

- **Users** - list, create, edit users and admin access
- **System Status** - live checks for DB, Redis, storage, cache, queue

Upcoming sections (in future development phases):

- Orders, Plans, Services, Payments, Tickets, Settings
- Marzban Settings, Telegram Settings, Email Settings
- Logs, Backups, System Monitoring

## What's next

1. Plans & Orders - plan model, order flow, admin CRUD
2. Marzban integration - API client, auto VPN service creation
3. Payment gateway - Rial/crypto payment integration
4. Ticket system - support ticket model and panel
5. Subscription links - V2Ray subscription URL generation
6. Telegram bot - admin reports and notifications
7. Monitoring - real server status checks
8. Email - order confirmations, expiry reminders
9. Docker deployment - containerized installation for environments where the native installer cannot satisfy PHP requirements
