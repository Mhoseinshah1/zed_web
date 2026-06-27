#!/usr/bin/env bash
# ZedProxy — safe production update script
# Usage: sudo bash update.sh
# Logs to: /var/log/zedproxy-update.log
# Backup location: /var/backups/zedproxy/updates/YYYYMMDD_HHMMSS/

set -Eeuo pipefail

# ─── Variables ────────────────────────────────────────────────────────────────
PROJECT_DIR="/var/www/zedproxy"
BRANCH="main"
REPO_URL="https://github.com/mhoseinshah1/zed_web.git"
LOG_FILE="/var/log/zedproxy-update.log"
BACKUP_BASE="/var/backups/zedproxy/updates"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${BACKUP_BASE}/${TIMESTAMP}"

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

# ─── Colors ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

# ─── Logging ──────────────────────────────────────────────────────────────────
touch "$LOG_FILE"
chmod 600 "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  ZedProxy Update — $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

# ─── Helpers ──────────────────────────────────────────────────────────────────
log()  { echo -e "${BLUE}[$(date +%H:%M:%S)]${NC} $*"; }
ok()   { echo -e "${GREEN}[$(date +%H:%M:%S)] ✓${NC} $*"; }
warn() { echo -e "${YELLOW}[$(date +%H:%M:%S)] ⚠${NC} $*"; }
error() { echo -e "${RED}[$(date +%H:%M:%S)] ✗ ERROR:${NC} $*" >&2; exit 1; }

# ─── Traps ────────────────────────────────────────────────────────────────────
UPDATE_SUCCESS=false

_on_err() {
    local exit_code=$? line_no=${BASH_LINENO[0]} cmd="${BASH_COMMAND}"
    echo -e "${RED}[ERROR] line ${line_no}: ${cmd} (exit ${exit_code})${NC}" >&2
    echo "  See full log: ${LOG_FILE}"
    # Attempt to bring the app back up on error
    if [ -d "$PROJECT_DIR" ]; then
        cd "$PROJECT_DIR" && php artisan up 2>/dev/null || true
    fi
}

_on_exit() {
    if [ "$UPDATE_SUCCESS" != "true" ]; then
        echo ""
        echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
        echo -e "${RED}  Update did NOT complete successfully.${NC}"
        echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
        echo "  Log: ${LOG_FILE}"
        if [ -d "$BACKUP_DIR" ]; then
            echo "  Backup from before update: ${BACKUP_DIR}"
        fi
    fi
}

trap '_on_err' ERR
trap '_on_exit' EXIT

# ─── Root check ───────────────────────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    error "This script must be run as root: sudo bash update.sh"
fi

# ─── Verify project exists ────────────────────────────────────────────────────
log "Verifying project at ${PROJECT_DIR}..."
for required in ".git" "composer.json" "artisan" "package.json"; do
    if [ ! -e "${PROJECT_DIR}/${required}" ]; then
        error "Project file/dir missing: ${PROJECT_DIR}/${required}
  ZedProxy does not appear to be installed at ${PROJECT_DIR}.
  Run the installer first: curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh && chmod +x /tmp/zedproxy-install.sh && sudo bash /tmp/zedproxy-install.sh"
    fi
done
ok "Project verified"

cd "$PROJECT_DIR"

# ─── Mark git directory safe ──────────────────────────────────────────────────
git config --global --add safe.directory "$PROJECT_DIR" 2>/dev/null || true

# ─── Detect PHP version ───────────────────────────────────────────────────────
if ! command -v php &>/dev/null; then
    error "php is not in PATH. Cannot continue."
fi
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
ok "PHP ${PHP_VERSION} detected (FPM service: ${PHP_FPM_SERVICE})"

# ─── Load .env for DB credentials ────────────────────────────────────────────
if [ ! -f "${PROJECT_DIR}/.env" ]; then
    error ".env file not found at ${PROJECT_DIR}/.env — cannot create backup"
fi

_env_val() {
    grep -E "^${1}=" "${PROJECT_DIR}/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}
DB_DATABASE=$(_env_val "DB_DATABASE")
DB_USERNAME=$(_env_val "DB_USERNAME")
DB_PASSWORD=$(_env_val "DB_PASSWORD")
DB_HOST=$(_env_val "DB_HOST")
DB_PORT=$(_env_val "DB_PORT")
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

# ─── Backup ───────────────────────────────────────────────────────────────────
log "Creating backup in ${BACKUP_DIR}..."
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_BASE" "$BACKUP_DIR"

# Store current commit hash
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
echo "$CURRENT_COMMIT" > "${BACKUP_DIR}/commit.txt"

# Copy .env (chmod 600)
cp "${PROJECT_DIR}/.env" "${BACKUP_DIR}/.env"
chmod 600 "${BACKUP_DIR}/.env"

# PostgreSQL dump
if [ -n "$DB_DATABASE" ] && [ -n "$DB_USERNAME" ]; then
    log "Dumping PostgreSQL database ${DB_DATABASE}..."
    PGPASSWORD="$DB_PASSWORD" pg_dump \
        -h "$DB_HOST" \
        -p "$DB_PORT" \
        -U "$DB_USERNAME" \
        -d "$DB_DATABASE" \
        -Fc \
        -f "${BACKUP_DIR}/${DB_DATABASE}_${TIMESTAMP}.dump" \
        || error "pg_dump failed — aborting update to protect data"
    chmod 600 "${BACKUP_DIR}/${DB_DATABASE}_${TIMESTAMP}.dump"
    ok "Database backup saved: ${BACKUP_DIR}/${DB_DATABASE}_${TIMESTAMP}.dump"
else
    warn "DB_DATABASE or DB_USERNAME not found in .env — skipping pg_dump"
fi

ok "Backup complete: ${BACKUP_DIR}"

# ─── Maintenance mode ─────────────────────────────────────────────────────────
log "Enabling maintenance mode..."
php artisan down --render="errors::503" 2>/dev/null || php artisan down || true
ok "Maintenance mode ON"

# ─── Git pull ─────────────────────────────────────────────────────────────────
log "Fetching latest code from ${REPO_URL} (branch: ${BRANCH})..."
git fetch origin "$BRANCH"
git reset --hard "origin/${BRANCH}"
git clean -fd
NEW_COMMIT=$(git rev-parse HEAD)
ok "Code updated to commit ${NEW_COMMIT}"

# ─── Composer install ─────────────────────────────────────────────────────────
log "Installing PHP dependencies (composer install)..."
composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-dev
ok "Composer done"

# ─── Node / npm build ─────────────────────────────────────────────────────────
log "Installing Node.js dependencies..."
if npm ci --no-audit --prefer-offline 2>/dev/null; then
    ok "npm ci succeeded"
else
    warn "npm ci failed — falling back to npm install"
    npm install --no-audit
fi

log "Building frontend assets..."
npm run build
ok "Frontend build done"

# ─── Database migrations ──────────────────────────────────────────────────────
log "Running database migrations..."
php artisan migrate --force
ok "Migrations done"

# ─── Seed missing defaults (idempotent — firstOrCreate, never overwrites) ─────
log "Seeding default site texts..."
php artisan db:seed --class=SiteTextSeeder --force
log "Seeding default features..."
php artisan db:seed --class=FeatureSeeder --force
log "Seeding default locations..."
php artisan db:seed --class=LocationSeeder --force
log "Seeding default plans..."
php artisan db:seed --class=PlanSeeder --force
ok "Default data seeded (existing admin-edited values preserved)"

# ─── Storage link ─────────────────────────────────────────────────────────────
log "Ensuring storage symlink..."
php artisan storage:link 2>/dev/null || true

# ─── Caches ───────────────────────────────────────────────────────────────────
log "Clearing and rebuilding caches..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
ok "Caches rebuilt"

# ─── Disable maintenance mode ─────────────────────────────────────────────────
log "Disabling maintenance mode..."
php artisan up
ok "Application is back online"

# ─── Restart services ─────────────────────────────────────────────────────────
log "Restarting PHP-FPM (${PHP_FPM_SERVICE})..."
if systemctl is-active --quiet "$PHP_FPM_SERVICE" 2>/dev/null; then
    systemctl restart "$PHP_FPM_SERVICE"
    ok "PHP-FPM restarted"
else
    warn "PHP-FPM service (${PHP_FPM_SERVICE}) is not active — skipping restart"
fi

log "Reloading Supervisor workers..."
if command -v supervisorctl &>/dev/null; then
    supervisorctl reread 2>/dev/null || true
    supervisorctl update 2>/dev/null || true
    supervisorctl restart zedproxy-worker:* 2>/dev/null || true
    ok "Supervisor workers restarted"
else
    warn "supervisorctl not found — skipping"
fi

log "Reloading Nginx..."
if nginx -t 2>/dev/null; then
    systemctl reload nginx
    ok "Nginx reloaded"
else
    warn "Nginx config test failed — skipping reload"
    nginx -t
fi

# ─── Health check ─────────────────────────────────────────────────────────────
log "Running health check..."
sleep 2

APP_URL=$(_env_val "APP_URL")
APP_URL="${APP_URL:-http://localhost}"

HEALTH_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 \
    "http://localhost/health" \
    -H "Host: $(echo "$APP_URL" | sed 's|https\?://||')" \
    2>/dev/null || echo "000")

if [ "$HEALTH_RESPONSE" = "200" ]; then
    ok "Health check PASSED (HTTP 200)"
    curl -s "http://localhost/health" | python3 -m json.tool 2>/dev/null || curl -s "http://localhost/health"
    echo ""
else
    warn "Health check returned HTTP ${HEALTH_RESPONSE}"
    warn "Check: curl ${APP_URL}/health"
    warn "Logs:  tail -f ${PROJECT_DIR}/storage/logs/laravel.log"
fi

# Check HTTPS if APP_URL starts with https
if echo "$APP_URL" | grep -q "^https://"; then
    DOMAIN=$(echo "$APP_URL" | sed 's|https\?://||' | cut -d/ -f1)
    HTTPS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 \
        "https://${DOMAIN}/health" 2>/dev/null || echo "000")
    if [ "$HTTPS_RESPONSE" = "200" ]; then
        ok "HTTPS health check PASSED (HTTP 200)"
    else
        warn "HTTPS health check returned HTTP ${HTTPS_RESPONSE} — SSL may need attention"
    fi
fi

# ─── Cleanup old backups (keep last 30) ───────────────────────────────────────
if [ -d "$BACKUP_BASE" ]; then
    BACKUP_COUNT=$(ls -1d "${BACKUP_BASE}"/[0-9]* 2>/dev/null | wc -l)
    if [ "$BACKUP_COUNT" -gt 30 ]; then
        log "Pruning old update backups (keeping 30 most recent)..."
        ls -1dt "${BACKUP_BASE}"/[0-9]* | tail -n +31 | xargs rm -rf
        ok "Old backups pruned"
    fi
fi

# ─── Final summary ────────────────────────────────────────────────────────────
UPDATE_SUCCESS=true
echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ZedProxy update completed successfully!${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  Previous commit:  ${YELLOW}${CURRENT_COMMIT}${NC}"
echo -e "  Updated to:       ${YELLOW}${NEW_COMMIT}${NC}"
echo -e "  Backup:           ${BLUE}${BACKUP_DIR}${NC}"
echo -e "  Log:              ${BLUE}${LOG_FILE}${NC}"
echo -e "  Website:          ${BLUE}${APP_URL}${NC}"
echo ""
echo -e "  Rollback (if needed):"
echo -e "    ${YELLOW}cd ${PROJECT_DIR} && git reset --hard ${CURRENT_COMMIT}${NC}"
echo -e "    Then restore DB from backup if migrations ran:"
echo -e "    ${YELLOW}PGPASSWORD=... pg_restore -h ${DB_HOST} -p ${DB_PORT} -U ${DB_USERNAME} -d ${DB_DATABASE} --clean --if-exists ${BACKUP_DIR}/${DB_DATABASE}_${TIMESTAMP}.dump${NC}"
echo ""
