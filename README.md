# ZedProxy

A production-ready VPN/proxy sales platform built with Laravel, PostgreSQL, Redis, Filament, and Tailwind CSS. Designed to scale to 40,000+ users with full RTL Persian support.

## Tech Stack

| Component     | Technology                   |
|---------------|------------------------------|
| Backend       | Laravel 11, PHP 8.4          |
| Database      | PostgreSQL 16+               |
| Cache/Queue   | Redis                        |
| Frontend      | Blade + Tailwind CSS (RTL)   |
| Admin Panel   | Filament v3                  |
| Web Server    | Nginx + PHP-FPM              |
| OS            | Ubuntu 24.04                 |

## Requirements

- Ubuntu 24.04 (for `install.sh`)
- PHP 8.4 with extensions: pgsql, redis, mbstring, xml, curl, zip, bcmath, gd, intl, opcache
- PostgreSQL 14+
- Redis 6+
- Node.js 22+, npm
- Composer 2+

## One-command installation

```bash
sudo bash <(curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh)
```

Or with custom domain:

```bash
sudo APP_URL=https://yourdomain.com DOMAIN=yourdomain.com bash <(curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh)
```

## Manual installation

### 1. Clone and enter directory

```bash
git clone https://github.com/mhoseinshah1/zed_web.git /var/www/zedproxy
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

```bash
php artisan tinker
```

```php
App\Models\User::create([
    'name'     => 'Admin',
    'email'    => 'admin@yourdomain.com',
    'password' => Hash::make('your_secure_password'),
    'is_admin' => true,
]);
```

Then log in at `/admin`.

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
sudo systemctl status php8.4-fpm
sudo nginx -t
sudo journalctl -u nginx -n 50
```

### Permission errors

```bash
sudo chown -R www-data:www-data /var/www/zedproxy
sudo chmod -R 775 storage bootstrap/cache
```

### Admin panel not loading

Make sure your user has `is_admin = true`:

```bash
php artisan tinker
>>> App\Models\User::where('email','admin@example.com')->update(['is_admin'=>true]);
```

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
