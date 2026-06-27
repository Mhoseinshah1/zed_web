#!/usr/bin/env bash
# =============================================================================
# ZedProxy - One-command installation script for Ubuntu 24.04
# Usage: curl -fsSL https://raw.githubusercontent.com/OWNER/zed_web/main/install.sh | sudo bash
# =============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()   { echo -e "${BLUE}[ZedProxy]${NC} $*"; }
ok()    { echo -e "${GREEN}[OK]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

# ─── Verify root ─────────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && error "This script must be run as root: sudo bash install.sh"

# ─── Configuration ───────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/zedproxy}"
APP_URL="${APP_URL:-http://localhost}"
DOMAIN="${DOMAIN:-localhost}"
PHP_VERSION="8.4"
NODE_VERSION="22"
DB_NAME="zedproxy"
DB_USER="zedproxy_user"
DB_PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9!@#$%^&*' | head -c 32)
NGINX_CONF="/etc/nginx/sites-available/zedproxy"

log "Starting ZedProxy installation..."
log "App directory: $APP_DIR"
log "Domain: $DOMAIN"

# ─── System packages ─────────────────────────────────────────────────────────
log "Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq

log "Installing base packages..."
apt-get install -y -qq \
    curl wget git unzip zip gnupg2 \
    ca-certificates lsb-release \
    apt-transport-https software-properties-common \
    supervisor cron

# ─── PHP 8.4 ─────────────────────────────────────────────────────────────────
log "Installing PHP $PHP_VERSION..."
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq \
    php${PHP_VERSION} \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-pgsql \
    php${PHP_VERSION}-redis \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-opcache

ok "PHP $PHP_VERSION installed: $(php -v | head -1)"

# ─── Composer ────────────────────────────────────────────────────────────────
log "Installing Composer..."
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
ok "Composer: $(composer --version --no-ansi)"

# ─── Node.js ─────────────────────────────────────────────────────────────────
log "Installing Node.js $NODE_VERSION..."
if ! command -v node &>/dev/null || [[ $(node --version | cut -d'v' -f2 | cut -d'.' -f1) -lt $NODE_VERSION ]]; then
    curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
    apt-get install -y -qq nodejs
fi
ok "Node.js: $(node --version), npm: $(npm --version)"

# ─── PostgreSQL ──────────────────────────────────────────────────────────────
log "Installing PostgreSQL..."
apt-get install -y -qq postgresql postgresql-contrib

systemctl enable postgresql
systemctl start postgresql

log "Creating PostgreSQL database and user..."
sudo -u postgres psql <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}';
    ELSE
        ALTER ROLE ${DB_USER} WITH PASSWORD '${DB_PASS}';
    END IF;
END
\$\$;

SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')
\gexec

GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};
SQL

ok "PostgreSQL database '${DB_NAME}' and user '${DB_USER}' ready"

# ─── Redis ───────────────────────────────────────────────────────────────────
log "Installing Redis..."
apt-get install -y -qq redis-server

# Bind to localhost only
sed -i 's/^bind .*/bind 127.0.0.1/' /etc/redis/redis.conf

systemctl enable redis-server
systemctl start redis-server

redis-cli ping | grep -q PONG || error "Redis did not respond to PING"
ok "Redis is running"

# ─── Nginx ───────────────────────────────────────────────────────────────────
log "Installing Nginx..."
apt-get install -y -qq nginx

# ─── Application setup ───────────────────────────────────────────────────────
if [ -d "$APP_DIR" ]; then
    warn "Directory $APP_DIR already exists. Updating in place."
else
    mkdir -p "$APP_DIR"
fi

# Copy files if running from a different directory
if [ "$(pwd)" != "$APP_DIR" ] && [ -f "$(pwd)/artisan" ]; then
    log "Copying application files to $APP_DIR..."
    rsync -a --exclude='.git' --exclude='node_modules' --exclude='vendor' . "$APP_DIR/"
fi

cd "$APP_DIR"

# ─── .env ────────────────────────────────────────────────────────────────────
log "Creating .env file..."
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Write secure values
cat > .env <<ENV
APP_NAME=ZedProxy
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_URL}

APP_LOCALE=fa
APP_FALLBACK_LOCALE=fa
APP_FAKER_LOCALE=fa_IR

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis
CACHE_PREFIX=zedproxy_

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@${DOMAIN}"
MAIL_FROM_NAME="ZedProxy"

VITE_APP_NAME="ZedProxy"
ENV

chmod 600 .env
ok ".env created with secure credentials"

# ─── PHP-FPM config ──────────────────────────────────────────────────────────
log "Configuring PHP-FPM..."
PHP_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/zedproxy.conf"
cat > "$PHP_FPM_POOL" <<PHPFPM
[zedproxy]
user = www-data
group = www-data
listen = /run/php/php${PHP_VERSION}-fpm-zedproxy.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500

php_admin_value[error_log] = /var/log/php/${PHP_VERSION}-fpm-zedproxy.log
php_admin_flag[log_errors] = on
php_value[memory_limit] = 256M
php_value[upload_max_filesize] = 20M
php_value[post_max_size] = 20M
php_value[max_execution_time] = 60
PHPFPM

mkdir -p /var/log/php
systemctl restart php${PHP_VERSION}-fpm
ok "PHP-FPM configured"

# ─── Composer install ────────────────────────────────────────────────────────
log "Installing PHP dependencies..."
COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --quiet

ok "Composer dependencies installed"

# ─── Node / build ────────────────────────────────────────────────────────────
log "Installing Node.js dependencies..."
npm ci --silent

log "Building frontend assets..."
npm run build

ok "Frontend assets built"

# ─── Laravel setup ───────────────────────────────────────────────────────────
log "Generating application key..."
php artisan key:generate --force

log "Running database migrations..."
php artisan migrate --force || error "Migration failed. Check database credentials."

log "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

ok "Laravel optimized"

# ─── Permissions ─────────────────────────────────────────────────────────────
log "Setting file permissions..."
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type f -exec chmod 644 {} \;
find "$APP_DIR" -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
chmod 600 .env
chmod +x scripts/backup.sh

ok "Permissions set"

# ─── Nginx configuration ─────────────────────────────────────────────────────
log "Configuring Nginx..."
cat > "$NGINX_CONF" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;

    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm-zedproxy.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    client_max_body_size 20M;
}
NGINX

ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/zedproxy
rm -f /etc/nginx/sites-enabled/default
nginx -t || error "Nginx config test failed"
systemctl enable nginx
systemctl reload nginx
ok "Nginx configured"

# ─── Queue worker (Supervisor) ───────────────────────────────────────────────
log "Configuring queue worker..."
cat > /etc/supervisor/conf.d/zedproxy-worker.conf <<SUPERVISOR
[program:zedproxy-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_DIR}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stopwaitsecs=3600
SUPERVISOR

systemctl enable supervisor
systemctl start supervisor
supervisorctl reread
supervisorctl update
ok "Queue workers configured"

# ─── Cron for backup ─────────────────────────────────────────────────────────
log "Scheduling daily backup cron..."
CRON_JOB="0 3 * * * www-data bash ${APP_DIR}/scripts/backup.sh >> /var/log/zedproxy-backup.log 2>&1"
CRON_FILE="/etc/cron.d/zedproxy-backup"
echo "$CRON_JOB" > "$CRON_FILE"
chmod 0644 "$CRON_FILE"
ok "Daily backup scheduled at 3:00 AM"

# ─── Health check ────────────────────────────────────────────────────────────
log "Running health check..."
sleep 2

HEALTH_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/health 2>/dev/null || echo "000")

if [ "$HEALTH_RESPONSE" = "200" ]; then
    ok "Health check PASSED (HTTP 200)"
    HEALTH_BODY=$(curl -s http://localhost/health)
    echo "$HEALTH_BODY"
else
    warn "Health check returned HTTP $HEALTH_RESPONSE"
    warn "The app may still be warming up. Check: curl http://localhost/health"
    warn "And check logs: tail -f ${APP_DIR}/storage/logs/laravel.log"
fi

# ─── Summary ─────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ZedProxy installation complete!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  App URL:      ${BLUE}${APP_URL}${NC}"
echo -e "  Admin panel:  ${BLUE}${APP_URL}/admin${NC}"
echo -e "  Health:       ${BLUE}${APP_URL}/health${NC}"
echo ""
echo -e "  DB name:      ${YELLOW}${DB_NAME}${NC}"
echo -e "  DB user:      ${YELLOW}${DB_USER}${NC}"
echo -e "  DB password:  ${YELLOW}${DB_PASS}${NC}"
echo ""
echo -e "  ${YELLOW}IMPORTANT: Save the database password above. It is also stored in .env${NC}"
echo ""
echo -e "  Create admin user:"
echo -e "    ${BLUE}cd ${APP_DIR} && php artisan tinker${NC}"
echo -e '    >>> App\Models\User::create(["name"=>"Admin","email"=>"admin@example.com","password"=>Hash::make("CHANGE_ME"),"is_admin"=>true])'
echo ""
if [ "$HEALTH_RESPONSE" != "200" ]; then
    echo -e "  ${RED}Health check did not pass. Investigate before considering this complete.${NC}"
fi
echo ""
