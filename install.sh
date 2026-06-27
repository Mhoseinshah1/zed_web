#!/usr/bin/env bash
# =============================================================================
# ZedProxy - One-command installation script for Ubuntu 24.04
# Usage: sudo bash <(curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh)
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
[[ $EUID -ne 0 ]] && error "This script must be run as root. Use: sudo bash <(curl -fsSL ...)"

# ─── Repository ──────────────────────────────────────────────────────────────
GITHUB_OWNER="mhoseinshah1"
REPO_NAME="zed_web"

# ─── Fail-safe: never print admin credentials on unexpected exit ──────────────
INSTALL_SUCCESS=false
trap '[[ "$INSTALL_SUCCESS" != "true" ]] && echo -e "\n${RED}[ERROR] Installation did not complete. Admin credentials were NOT saved.${NC}"' EXIT

# ─── Interactive prompts ──────────────────────────────────────────────────────

_prompt_domain() {
    while true; do
        echo -e "\n${BLUE}Enter the domain for this website, without http/https, example: zedproxy.com:${NC}"
        read -r INPUT_DOMAIN </dev/tty

        # Strip all whitespace
        INPUT_DOMAIN="${INPUT_DOMAIN//[[:space:]]/}"

        if [[ -z "$INPUT_DOMAIN" ]]; then
            warn "Domain cannot be empty. Please try again."
            continue
        fi
        if [[ "$INPUT_DOMAIN" == http://* || "$INPUT_DOMAIN" == https://* ]]; then
            warn "Do not include http:// or https://. Enter the bare domain, e.g.: zedproxy.com"
            continue
        fi
        if [[ "$INPUT_DOMAIN" == */ ]]; then
            warn "Domain must not end with a slash."
            continue
        fi

        DOMAIN="$INPUT_DOMAIN"
        ok "Domain: $DOMAIN"
        break
    done
}

_prompt_app_url() {
    local default_url="https://${DOMAIN}"
    echo -e "\n${BLUE}Enter the final website URL, example: https://zedproxy.com${NC}"
    echo -e "${BLUE}Press Enter to use: ${YELLOW}${default_url}${NC}"
    read -r INPUT_URL </dev/tty

    INPUT_URL="${INPUT_URL//[[:space:]]/}"
    APP_URL="${INPUT_URL:-$default_url}"
    ok "Website URL: $APP_URL"
}

_prompt_admin_email() {
    local default_email="admin@${DOMAIN}"
    echo -e "\n${BLUE}Enter admin email:${NC}"
    echo -e "${BLUE}Press Enter to use default: ${YELLOW}${default_email}${NC}"
    read -r INPUT_EMAIL </dev/tty

    INPUT_EMAIL="${INPUT_EMAIL//[[:space:]]/}"
    ADMIN_EMAIL="${INPUT_EMAIL:-$default_email}"
    ok "Admin email: $ADMIN_EMAIL"
}

_prompt_admin_name() {
    local rand_suffix
    rand_suffix=$(openssl rand -hex 3 2>/dev/null || printf '%06x' $((RANDOM * RANDOM % 16777216)))
    local default_name="zedadmin_${rand_suffix}"
    echo -e "\n${BLUE}Enter admin name/username:${NC}"
    echo -e "${BLUE}Press Enter to generate automatically: ${YELLOW}${default_name}${NC}"
    read -r INPUT_NAME </dev/tty

    ADMIN_NAME="${INPUT_NAME:-$default_name}"
    ok "Admin name: $ADMIN_NAME"
}

_prompt_admin_password() {
    echo -e "\n${BLUE}Enter admin password (input hidden):${NC}"
    echo -e "${BLUE}Press Enter to generate a strong random password automatically:${NC}"
    read -rs INPUT_PASS </dev/tty
    echo ""  # newline after hidden input

    if [[ -z "$INPUT_PASS" ]]; then
        ADMIN_PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9!@#$%^&*' | head -c 24)
        ok "Admin password: (generated — shown in final summary)"
    else
        ADMIN_PASS="$INPUT_PASS"
        ok "Admin password: (provided — shown in final summary)"
    fi
}

# ─── Run interactive prompts ──────────────────────────────────────────────────
echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ZedProxy Interactive Setup${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"

_prompt_domain
_prompt_app_url
_prompt_admin_email
_prompt_admin_name
_prompt_admin_password

echo ""
echo -e "${BLUE}────────────────────────────────────────────────────────────${NC}"
echo -e "  Domain:      ${YELLOW}${DOMAIN}${NC}"
echo -e "  Website URL: ${YELLOW}${APP_URL}${NC}"
echo -e "  Admin email: ${YELLOW}${ADMIN_EMAIL}${NC}"
echo -e "  Admin name:  ${YELLOW}${ADMIN_NAME}${NC}"
echo -e "  Password:    ${YELLOW}(configured — shown in final summary)${NC}"
echo -e "${BLUE}────────────────────────────────────────────────────────────${NC}"
echo -e "${BLUE}Proceeding with installation in 3 seconds... (Ctrl+C to cancel)${NC}"
sleep 3

# ─── Static configuration ─────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/zedproxy}"
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
ok ".env created (APP_URL=${APP_URL})"

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

# ─── Admin user creation ──────────────────────────────────────────────────────
log "Creating admin user (${ADMIN_EMAIL})..."
# Pass password via env var to keep it out of the process list
ZEDPROXY_ADMIN_PASS="$ADMIN_PASS" php artisan zedproxy:create-admin \
    --email="$ADMIN_EMAIL" \
    --name="$ADMIN_NAME" \
    || error "Failed to create admin user. Check: tail -f ${APP_DIR}/storage/logs/laravel.log"
ok "Admin user ready: $ADMIN_EMAIL"

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
log "Configuring Nginx for domain: ${DOMAIN}..."
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
ok "Nginx configured for: ${DOMAIN}"

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
HEALTH_OK=false

if [ "$HEALTH_RESPONSE" = "200" ]; then
    ok "Health check PASSED (HTTP 200)"
    curl -s http://localhost/health
    echo ""
    HEALTH_OK=true
else
    warn "Health check returned HTTP $HEALTH_RESPONSE"
    warn "The app may still be warming up. Run: curl http://localhost/health"
    warn "Check logs: tail -f ${APP_DIR}/storage/logs/laravel.log"
fi

# ─── Final summary ────────────────────────────────────────────────────────────
echo ""

if [ "$HEALTH_OK" = "true" ]; then
    INSTALL_SUCCESS=true
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}  ZedProxy installation completed successfully!${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  Website URL:       ${BLUE}${APP_URL}${NC}"
    echo -e "  Admin panel URL:   ${BLUE}${APP_URL}/admin${NC}"
    echo -e "  Health check URL:  ${BLUE}${APP_URL}/health${NC}"
    echo ""
    echo -e "  Admin email:       ${YELLOW}${ADMIN_EMAIL}${NC}"
    echo -e "  Admin username:    ${YELLOW}${ADMIN_NAME}${NC}"
    echo -e "  Admin password:    ${YELLOW}${ADMIN_PASS}${NC}"
    echo ""
    echo -e "  DB name:           ${YELLOW}${DB_NAME}${NC}"
    echo -e "  DB user:           ${YELLOW}${DB_USER}${NC}"
    echo -e "  DB password:       ${YELLOW}${DB_PASS}${NC}"
    echo ""
    echo -e "  ${RED}IMPORTANT: Save the passwords above. They will not be shown again.${NC}"
    echo ""
    echo -e "  Next step — add SSL:"
    echo -e "    ${BLUE}sudo certbot --nginx -d ${DOMAIN} -d www.${DOMAIN}${NC}"
    echo ""
else
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}  ZedProxy installation did not complete cleanly.${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  Health check failed (HTTP ${HEALTH_RESPONSE})."
    echo -e "  Admin credentials are NOT shown in a failed state."
    echo ""
    echo -e "  Investigate:"
    echo -e "    ${BLUE}tail -50 ${APP_DIR}/storage/logs/laravel.log${NC}"
    echo -e "    ${BLUE}curl http://localhost/health${NC}"
    echo -e "    ${BLUE}sudo systemctl status nginx php${PHP_VERSION}-fpm postgresql redis-server${NC}"
    echo ""
fi
