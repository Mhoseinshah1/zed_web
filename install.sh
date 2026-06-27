#!/usr/bin/env bash
# =============================================================================
# ZedProxy - One-command installation script for Ubuntu 22.04, 24.04, 26.04+
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh
#   chmod +x /tmp/zedproxy-install.sh
#   sudo bash /tmp/zedproxy-install.sh
# =============================================================================

set -euo pipefail

# Prevent all interactive prompts from apt/dpkg during installation
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

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
[[ $EUID -ne 0 ]] && error "This script must be run as root. Download and run with: curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh && sudo bash /tmp/zedproxy-install.sh"

# ─── Repository ──────────────────────────────────────────────────────────────
GITHUB_OWNER="mhoseinshah1"
REPO_NAME="zed_web"
BRANCH="main"

# ─── Fail-safe: never print admin credentials on unexpected exit ──────────────
INSTALL_SUCCESS=false
trap '[[ "$INSTALL_SUCCESS" != "true" ]] && echo -e "\n${RED}[ERROR] Installation did not complete. Admin credentials were NOT saved.${NC}"' EXIT

# ─── OS Detection ────────────────────────────────────────────────────────────
OS_ID=""
OS_VERSION_ID=""
OS_CODENAME=""
OS_PRETTY=""

detect_os() {
    if [ ! -f /etc/os-release ]; then
        error "Cannot detect OS: /etc/os-release not found."
    fi

    # Source variables without polluting the environment permanently
    OS_ID=$(grep -E '^ID=' /etc/os-release | cut -d= -f2 | tr -d '"')
    OS_VERSION_ID=$(grep -E '^VERSION_ID=' /etc/os-release | cut -d= -f2 | tr -d '"')
    OS_CODENAME=$(grep -E '^VERSION_CODENAME=' /etc/os-release | cut -d= -f2 | tr -d '"')
    OS_PRETTY=$(grep -E '^PRETTY_NAME=' /etc/os-release | cut -d= -f2 | tr -d '"')

    if [ "$OS_ID" != "ubuntu" ]; then
        error "This installer currently supports Ubuntu only.\nDetected: $OS_PRETTY"
    fi

    ok "Detected OS: $OS_PRETTY (codename: ${OS_CODENAME:-unknown})"
}

# ─── Remove stale ondrej/php sources that would break apt update ──────────────
clean_ondrej_php_sources() {
    local cleaned=false
    local patterns=(
        "/etc/apt/sources.list.d/ondrej-ubuntu-php*.list"
        "/etc/apt/sources.list.d/*ondrej*.list"
        "/etc/apt/sources.list.d/*ondrej*.sources"
    )
    for pattern in "${patterns[@]}"; do
        for f in $pattern; do
            [ -f "$f" ] || continue
            warn "Removing stale ondrej/php repository file: $f"
            rm -f "$f"
            cleaned=true
        done
    done
    $cleaned && ok "Cleaned stale ondrej/php repository files" || true
}

# ─── Safe apt-get update ─────────────────────────────────────────────────────
safe_apt_update() {
    log "Running apt update..."
    local err_file
    err_file=$(mktemp)
    if ! apt-get update -qq 2>"$err_file"; then
        echo -e "${RED}[ERROR]${NC} apt update failed:" >&2
        cat "$err_file" >&2
        echo "" >&2
        echo "Active repository files in /etc/apt/sources.list.d/:" >&2
        ls /etc/apt/sources.list.d/ 2>/dev/null >&2 || true
        rm -f "$err_file"
        error "Fix the broken repositories shown above, then re-run the installer."
    fi
    rm -f "$err_file"
}

# ─── Check if ondrej/php PPA supports a given Ubuntu codename ────────────────
ondrej_ppa_supports() {
    local codename="$1"
    local url="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${codename}/Release"
    curl -fsI --max-time 10 "$url" &>/dev/null
}

# ─── PHP installation ─────────────────────────────────────────────────────────
# Minimum PHP version required by this Laravel project
PHP_MIN_VERSION="8.2"
# Will be set to the detected installed version (e.g. "8.3" or "8.4")
PHP_VERSION=""

install_php() {
    log "Attempting PHP installation from official Ubuntu packages..."

    # Install ONLY cli/fpm and extension packages.
    # Do NOT install the 'php' meta-package — on Ubuntu 24.04 it pulls in
    # libapache2-mod-php8.3 which drags in Apache2, conflicting with Nginx.
    apt-get install -y -qq \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" \
        php-cli php-fpm \
        php-pgsql php-redis php-mbstring \
        php-xml php-curl php-zip \
        php-bcmath php-gd php-intl php-opcache 2>/dev/null || true

    if command -v php &>/dev/null; then
        PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    fi

    # Check if the installed version satisfies our minimum
    if [[ -n "$PHP_VERSION" ]] && php -r "exit(version_compare('$PHP_VERSION', '$PHP_MIN_VERSION', '>=') ? 0 : 1);"; then
        ok "PHP $PHP_VERSION installed from official Ubuntu packages"
        return 0
    fi

    # Official packages are too old — try ondrej/php as a fallback
    warn "Official Ubuntu PHP packages provide PHP ${PHP_VERSION:-not found}, which is below the required $PHP_MIN_VERSION."
    warn "Checking ondrej/php PPA support for Ubuntu ${OS_CODENAME}..."

    if ondrej_ppa_supports "$OS_CODENAME"; then
        log "ondrej/php PPA supports Ubuntu $OS_CODENAME — adding PPA as fallback..."
        add-apt-repository -y ppa:ondrej/php
        safe_apt_update

        local target="8.4"
        apt-get install -y -qq \
            -o Dpkg::Options::="--force-confdef" \
            -o Dpkg::Options::="--force-confold" \
            "php${target}-cli" "php${target}-fpm" \
            "php${target}-pgsql" "php${target}-redis" "php${target}-mbstring" \
            "php${target}-xml" "php${target}-curl" "php${target}-zip" \
            "php${target}-bcmath" "php${target}-gd" "php${target}-intl" "php${target}-opcache"

        PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
        ok "PHP $PHP_VERSION installed via ondrej/php PPA"
    else
        error "Cannot install a compatible PHP version on Ubuntu ${OS_CODENAME} (${OS_PRETTY}).\n\n  Official Ubuntu PHP : ${PHP_VERSION:-not found} (required: PHP $PHP_MIN_VERSION+)\n  ondrej/php PPA      : does not support Ubuntu ${OS_CODENAME}\n\nOptions:\n  - Use Ubuntu 22.04 (jammy) or 24.04 (noble) where ondrej/php is available\n  - Use Docker-based deployment (see README.md for guidance)\n\nInstallation aborted."
    fi
}

# ─── Interactive prompts ──────────────────────────────────────────────────────

_prompt_domain() {
    while true; do
        echo -e "\n${BLUE}Enter the domain for this website, without http/https, example: zedproxy.com:${NC}"
        read -r INPUT_DOMAIN </dev/tty

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

# ─── Run OS detection first ───────────────────────────────────────────────────
detect_os

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
NODE_VERSION="22"
DB_NAME="zedproxy"
DB_USER="zedproxy_user"
DB_PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9!@#$%^&*' | head -c 32)
NGINX_CONF="/etc/nginx/sites-available/zedproxy"

log "Starting ZedProxy installation..."
log "OS: $OS_PRETTY"
log "App directory: $APP_DIR"
log "Domain: $DOMAIN"

# ─── Clean any broken ondrej/php sources before first apt update ──────────────
clean_ondrej_php_sources

# ─── System packages ─────────────────────────────────────────────────────────
safe_apt_update
apt-get upgrade -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold"

log "Installing base packages..."
apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    curl wget git unzip zip gnupg2 \
    ca-certificates lsb-release \
    apt-transport-https software-properties-common \
    supervisor cron

# ─── PHP installation ─────────────────────────────────────────────────────────
install_php

# PHP_VERSION is now set to the installed version (e.g. "8.3" or "8.4")
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

ok "PHP version: $(php -v | head -1)"

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
    apt-get install -y -qq \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" \
        nodejs
fi
ok "Node.js: $(node --version), npm: $(npm --version)"

# ─── PostgreSQL ──────────────────────────────────────────────────────────────
log "Installing PostgreSQL..."
apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    postgresql postgresql-contrib

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
apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    redis-server

sed -i 's/^bind .*/bind 127.0.0.1/' /etc/redis/redis.conf

systemctl enable redis-server
systemctl start redis-server

redis-cli ping | grep -q PONG || error "Redis did not respond to PING"
ok "Redis is running"

# ─── Nginx ───────────────────────────────────────────────────────────────────
log "Installing Nginx..."

# Stop Apache if it is running — it would hold port 80 and block Nginx
for _apache_svc in apache2 httpd; do
    if systemctl is-active --quiet "$_apache_svc" 2>/dev/null; then
        warn "${_apache_svc} is running and would conflict with Nginx on port 80."
        warn "Stopping and disabling ${_apache_svc}..."
        systemctl stop    "$_apache_svc" || true
        systemctl disable "$_apache_svc" || true
        systemctl mask    "$_apache_svc" 2>/dev/null || true
        ok "${_apache_svc} stopped and masked"
    fi
done

# Verify port 80 is free before installing Nginx
_port80=$(ss -ltnp 2>/dev/null | grep ':80 ' || true)
if [ -n "$_port80" ]; then
    _proc=$(echo "$_port80" | grep -oP 'users:\(\("\K[^"]+' 2>/dev/null || echo "unknown")
    error "Port 80 is still in use by '${_proc}' and cannot be freed automatically.\n\nDetails:\n${_port80}\n\nStop the conflicting service manually, then re-run the installer."
fi

apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    nginx

# ─── Project directory preparation ───────────────────────────────────────────
REPO_URL="https://github.com/${GITHUB_OWNER}/${REPO_NAME}.git"

prepare_project_directory() {
    if [ ! -d "$APP_DIR" ]; then
        log "Cloning ${REPO_URL} (branch: ${BRANCH}) into ${APP_DIR}..."
        git clone -b "$BRANCH" "$REPO_URL" "$APP_DIR"
        ok "Repository cloned to ${APP_DIR}"

    elif [ -d "${APP_DIR}/.git" ]; then
        log "${APP_DIR} already contains a git repository — updating to origin/${BRANCH}..."
        git -C "$APP_DIR" fetch origin "$BRANCH"
        git -C "$APP_DIR" reset --hard "origin/${BRANCH}"
        ok "Repository updated to origin/${BRANCH}"

    else
        # Directory exists but is not a git repo
        if [ -z "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
            # Empty directory — just clone into it
            log "${APP_DIR} is empty — cloning repository..."
            rmdir "$APP_DIR"
            git clone -b "$BRANCH" "$REPO_URL" "$APP_DIR"
            ok "Repository cloned to ${APP_DIR}"
        else
            # Non-empty, non-git directory — back it up then clone fresh
            local backup_dir="/var/www/zedproxy_backup_$(date +%Y%m%d_%H%M%S)"
            warn "${APP_DIR} exists but is not a git repository."
            warn "Backing it up to ${backup_dir} and cloning fresh..."
            mv "$APP_DIR" "$backup_dir"
            git clone -b "$BRANCH" "$REPO_URL" "$APP_DIR"
            ok "Backup saved to ${backup_dir}"
            ok "Repository cloned to ${APP_DIR}"
        fi
    fi
}

# ─── Verify the Laravel project structure is present ─────────────────────────
verify_laravel_project() {
    local missing=()
    local required_paths=(
        "composer.json"
        "artisan"
        "package.json"
        "app"
        "bootstrap"
        "config"
        "routes"
    )

    for path in "${required_paths[@]}"; do
        [ -e "${APP_DIR}/${path}" ] || missing+=("$path")
    done

    if [ ${#missing[@]} -gt 0 ]; then
        echo -e "${RED}[ERROR]${NC} Laravel project was not found in ${APP_DIR}." >&2
        echo -e "${RED}[ERROR]${NC} The following required paths are missing:" >&2
        for m in "${missing[@]}"; do
            echo -e "         - ${m}" >&2
        done
        echo "" >&2
        echo "Check that ${REPO_URL} (branch: ${BRANCH}) contains a complete Laravel project." >&2
        exit 1
    fi

    ok "Laravel project structure verified in ${APP_DIR}"
}

prepare_project_directory
verify_laravel_project

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

# ─── PHP-FPM pool config ─────────────────────────────────────────────────────
log "Configuring PHP-FPM (${PHP_FPM_SERVICE})..."
PHP_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/zedproxy.conf"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm-zedproxy.sock"

cat > "$PHP_FPM_POOL" <<PHPFPM
[zedproxy]
user = www-data
group = www-data
listen = ${PHP_FPM_SOCK}
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

if systemctl is-active --quiet "$PHP_FPM_SERVICE" 2>/dev/null || systemctl is-enabled --quiet "$PHP_FPM_SERVICE" 2>/dev/null; then
    systemctl restart "$PHP_FPM_SERVICE"
else
    systemctl enable "$PHP_FPM_SERVICE"
    systemctl start "$PHP_FPM_SERVICE"
fi

ok "PHP-FPM configured (${PHP_FPM_SERVICE})"

# ─── Composer install ────────────────────────────────────────────────────────
log "Installing PHP dependencies..."
export COMPOSER_ALLOW_SUPERUSER=1

# Run without --quiet so that any failure prints the real Composer error.
# With set -euo pipefail, a non-zero exit code propagates immediately.
composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    || error "Composer install failed — see the output above for the exact error."

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
        fastcgi_pass unix:${PHP_FPM_SOCK};
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
systemctl restart nginx
sleep 1

# Confirm Nginx is running and owns port 80
if ! systemctl is-active --quiet nginx; then
    journalctl -u nginx --no-pager -n 20 >&2 || true
    error "Nginx failed to start. See the output above for details."
fi

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
    echo -e "  OS:                ${BLUE}${OS_PRETTY}${NC}"
    echo -e "  PHP version:       ${BLUE}PHP ${PHP_VERSION}${NC}"
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
    echo -e "    ${BLUE}sudo systemctl status nginx ${PHP_FPM_SERVICE} postgresql redis-server${NC}"
    echo ""
fi
