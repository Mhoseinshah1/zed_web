#!/usr/bin/env bash
# =============================================================================
# ZedProxy - One-command installation script for Ubuntu 22.04, 24.04, 26.04+
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/install.sh -o /tmp/zedproxy-install.sh
#   chmod +x /tmp/zedproxy-install.sh
#   sudo bash /tmp/zedproxy-install.sh
# =============================================================================

# -E: ERR trap inherited by functions/subshells
# -e: exit on error, -u: unset variable = error, -o pipefail: pipe failure = error
set -Eeuo pipefail

# ─── Secret-safety: refuse shell tracing ──────────────────────────────────────
# `bash -x install.sh` / `set -x` would echo every command — including the
# generated admin/DB passwords and APP_KEY — to stderr (and thus the log). Refuse
# outright, and pin PS4 to a fixed literal so it can never run a command
# substitution that exfiltrates secrets.
PS4='+ '
case $- in
    *x*)
        printf '%s\n' "خطا: اجرای نصب‌کننده با ردیابی پوسته (bash -x یا set -x) به دلیل خطر افشای اسرار مجاز نیست." >&2
        printf '%s\n' "ERROR: refusing to run the installer with shell tracing (bash -x / set -x) — it would leak secrets." >&2
        exit 1
        ;;
esac

# Prevent all interactive prompts from apt/dpkg during installation
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

# Allow Composer to run as root without interactive confirmation
export COMPOSER_ALLOW_SUPERUSER=1

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

# ─── Install log ─────────────────────────────────────────────────────────────
LOG_FILE="/var/log/zedproxy-install.log"
touch "$LOG_FILE"
chmod 600 "$LOG_FILE"
# Tee all installer NORMAL output (stdout + stderr) to the log file. Credentials
# are NEVER written here: secrets are delivered only through log_secret_once()
# (controlling terminal or a validated secure file), which bypasses this pipe.
exec > >(tee -a "$LOG_FILE") 2>&1
echo "=== ZedProxy install started $(date -u '+%Y-%m-%d %H:%M:%S UTC') ===" >> "$LOG_FILE"

# ─── Repository ──────────────────────────────────────────────────────────────
APP_NAME="ZedProxy"
GITHUB_OWNER="mhoseinshah1"
REPO_NAME="zed_web"
BRANCH="main"
# Optional explicit ref to pin the install to: a tag, a full/short commit SHA,
# or a branch. Empty → track BRANCH. The resolved commit SHA is always recorded
# (never just the branch name). Private-repo auth behaviour is unchanged.
INSTALL_REF="${ZP_REF:-}"
APP_DIR="${APP_DIR:-/var/www/zedproxy}"
REPO_URL="https://github.com/${GITHUB_OWNER}/${REPO_NAME}.git"

# ─── Atomic release layout ───────────────────────────────────────────────────
# ZedProxy uses the SAME release-based layout the atomic updater uses, from the
# very first install — there is no separate "legacy single-dir" install path:
#   $ZPD_BASE/current -> releases/<id>            (Nginx root; worker/scheduler)
#   $ZPD_BASE/releases/<id>/                       (one release's application code)
#   $ZPD_BASE/shared/{.env,storage,persistent}    (state shared across releases)
# During a fresh install $APP_DIR is repointed at the initial release directory
# so every existing build/config step operates on it transparently, while the
# operational services and shortcuts always target $ACTIVE_APP_DIR ($ZPD_CURRENT).
ZPD_BASE="${APP_DIR}"
ZPD_RELEASES="${ZPD_BASE}/releases"
ZPD_SHARED="${ZPD_BASE}/shared"
ZPD_CURRENT="${ZPD_BASE}/current"
ACTIVE_APP_DIR="${ZPD_CURRENT}"          # services + shortcuts always target current/
INITIAL_RELEASE_ID=""
INITIAL_RELEASE_DIR=""
INSTALL_LAYOUT="fresh"                    # fresh | atomic | legacy
DEPLOY_ENV_FILE="/etc/zedproxy/deploy.env"
export ZPD_BASE

# ─── Fail-safe traps ─────────────────────────────────────────────────────────
INSTALL_SUCCESS=false

_on_exit() {
    if [[ "$INSTALL_SUCCESS" != "true" ]]; then
        echo -e "\n${RED}[ERROR] Installation did not complete. Admin credentials were NOT saved.${NC}"
        echo -e "${RED}[ERROR] See full log: sudo tail -n 120 ${LOG_FILE}${NC}"
    fi
}

_on_err() {
    local exit_code=$?
    local line_no=${BASH_LINENO[0]}
    { set +x; } 2>/dev/null
    # NEVER print the raw failing command: it may contain a password argument,
    # an Authorization header, PGPASSWORD, a token or an authenticated URL. Mask
    # any in-memory secret literals, then apply structural redaction. Falls back
    # to a stage-only message if the masking helper is unavailable.
    local safe_cmd
    if declare -F zp_mask_command >/dev/null 2>&1; then
        safe_cmd="$(zp_mask_command "${BASH_COMMAND}" "${ADMIN_PASS:-}" "${DB_PASS:-}" "${APP_KEY:-}" 2>/dev/null)"
    else
        safe_cmd="(دستور به‌دلایل امنیتی نمایش داده نشد)"
    fi
    echo -e "\n${RED}[ERROR] Command failed (exit ${exit_code}) at line ${line_no}.${NC}" >&2
    echo -e "${RED}[ERROR]   ${safe_cmd}${NC}" >&2
    echo -e "${RED}[ERROR] برای جزئیات غیرحساس، فایل لاگ را بررسی کنید: sudo tail -n 120 ${LOG_FILE}${NC}" >&2
    # If a re-run failed while the live app was in maintenance mode, bring it
    # back up so the site is not left offline. Uploaded data is never touched.
    if [ "${MAINT_MODE_ON:-false}" = "true" ] && [ -d "${APP_DIR:-}" ]; then
        ( cd "$APP_DIR" && php artisan up >/dev/null 2>&1 ) || true
    fi
}

trap '_on_exit' EXIT
trap '_on_err' ERR

# ─── Installer helper library ─────────────────────────────────────────────────
# The pure/testable helpers live in scripts/lib/installer-lib.sh so the shell
# tests can exercise them. This script must also keep working when fetched
# standalone (curl → /tmp), so we load the library from a local checkout when
# present, otherwise download it from the repo.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo /tmp)"
LIB_REL="scripts/lib/installer-lib.sh"
SUPPLY_LIB_REL="scripts/lib/supply-chain-lib.sh"
RAW_BASE="https://raw.githubusercontent.com/${GITHUB_OWNER}/${REPO_NAME}/${BRANCH}"

# load_lib REL — source scripts/lib/<REL> from a local checkout when present,
# otherwise download it over HTTPS (bounded timeout) into a temp file. The temp
# file is created with mktemp and removed after sourcing.
load_lib() {
    local rel="$1" candidate tmp_lib
    for candidate in "${SCRIPT_DIR}/${rel}" "${APP_DIR}/${rel}"; do
        if [ -f "$candidate" ]; then
            # shellcheck source=/dev/null
            source "$candidate"
            return 0
        fi
    done
    tmp_lib=$(mktemp) || return 1
    if curl -fsSL --proto '=https' --tlsv1.2 --max-time 20 --retry 2 "${RAW_BASE}/${rel}" -o "$tmp_lib" 2>/dev/null && [ -s "$tmp_lib" ]; then
        # shellcheck source=/dev/null
        source "$tmp_lib"
        rm -f "$tmp_lib"
        return 0
    fi
    rm -f "$tmp_lib"
    return 1
}
load_lib "$LIB_REL"       || error "Could not load installer helper library (${LIB_REL}). Check network access to GitHub and retry."
load_lib "$SUPPLY_LIB_REL" || error "Could not load supply-chain helper library (${SUPPLY_LIB_REL}). Check network access to GitHub and retry."
# Deploy library provides the atomic-layout helpers (zpd_write_deploy_env,
# zpd_switch_current, …) shared verbatim with the updater.
load_lib "scripts/lib/deploy-lib.sh" || error "Could not load deploy helper library (scripts/lib/deploy-lib.sh)."
# Shared command-wrapper installer (same implementation deploy.sh uses).
load_lib "scripts/deploy/install-command-wrappers.sh" || error "Could not load command-wrapper installer (scripts/deploy/install-command-wrappers.sh)."

# ─── Secret-safe output channel ───────────────────────────────────────────────
# Credentials are delivered ONLY through log_secret_once(): to the controlling
# terminal (/dev/tty) when present, otherwise appended to a validated secure
# file (ZP_CREDENTIAL_OUTPUT). They never pass through the tee'd $LOG_FILE.
ZP_TTY=""
if [ -e /dev/tty ] && { true >/dev/tty; } 2>/dev/null; then
    ZP_TTY=/dev/tty
fi
CREDENTIAL_FILE=""            # set when non-interactive secure-file delivery is used
SECRET_DELIVERY="none"        # tty | file | none

# log_secret_once MESSAGE — write secret text exactly once, bypassing tee/$LOG_FILE.
log_secret_once() {
    local msg="$1"
    { set +x; } 2>/dev/null
    if [ -n "$ZP_TTY" ]; then
        printf '%s\n' "$msg" > "$ZP_TTY"
    elif [ -n "$CREDENTIAL_FILE" ]; then
        printf '%s\n' "$msg" >> "$CREDENTIAL_FILE"
    fi
    return 0
}

# ensure_secret_delivery — decide (and prepare) how the generated admin password
# will reach the operator BEFORE the admin account is created. Aborts safely
# when neither a TTY nor an explicit secure file is available.
ensure_secret_delivery() {
    if [ -n "$ZP_TTY" ]; then
        SECRET_DELIVERY="tty"
        return 0
    fi
    if [ -n "${ZP_CREDENTIAL_OUTPUT:-}" ]; then
        local reason force=""
        reason="$(zp_validate_credential_dest "$ZP_CREDENTIAL_OUTPUT")" \
            || error "مسیر امن ZP_CREDENTIAL_OUTPUT نامعتبر است (${reason}). یک مسیر مطلق متعلق به root خارج از /tmp و storage انتخاب کنید."
        if [ -e "$ZP_CREDENTIAL_OUTPUT" ] && [ "${ZP_CREDENTIAL_FORCE:-0}" != "1" ]; then
            error "فایل خروجی امن از قبل موجود است. برای بازنویسی امن ZP_CREDENTIAL_FORCE=1 را تنظیم کنید."
        fi
        [ "${ZP_CREDENTIAL_FORCE:-0}" = "1" ] && force="--force"
        zp_write_credential_file "$ZP_CREDENTIAL_OUTPUT" \
            "# ZedProxy bootstrap credentials — این فایل را پس از ذخیرهٔ امن اطلاعات حذف کنید." "$force" \
            || error "ایجاد فایل خروجی امن اطلاعات ورود ناموفق بود."
        CREDENTIAL_FILE="$ZP_CREDENTIAL_OUTPUT"
        SECRET_DELIVERY="file"
        log "اطلاعات ورود به‌صورت امن در فایل زیر ذخیره می‌شود: ${CREDENTIAL_FILE}"
        return 0
    fi
    error "امکان نمایش امن اطلاعات ورود وجود ندارد.\nبرای نصب غیرتعاملی مسیر امن ZP_CREDENTIAL_OUTPUT را مشخص کنید."
}

# ─── Fresh vs. safe re-run state ──────────────────────────────────────────────
REINSTALL_BACKUP_ROOT="/var/backups/zedproxy/reinstall"
REINSTALL_TS="$(date +%Y%m%d_%H%M%S)"
ENV_BACKUP_PATH=""      # set once the existing .env is backed up
PRE_UPDATE_COMMIT=""    # git SHA before we touch the code
DB_BACKUP_PATH=""       # pg_dump path before migrations
MAINT_MODE_ON=false     # whether we put the live app into maintenance mode
IS_EXISTING=false       # true when an already-configured install is detected
INSTALL_MODE="fresh"    # "fresh" | "reinstall"

# Existing configuration read from the current .env (populated on a re-run).
EXISTING_DOMAIN=""
RESET_ADMIN_PASSWORD=false

detect_install_mode() {
    if [ -L "${ZPD_CURRENT}" ] && [ -e "${ZPD_CURRENT}/.env" ]; then
        # Already using the atomic release layout → safe repair of the active
        # release. Code updates go through the atomic deployer, never a git
        # reset --hard of the live release.
        IS_EXISTING=true
        INSTALL_MODE="reinstall"
        INSTALL_LAYOUT="atomic"
        APP_DIR="${ZPD_CURRENT}"
    elif zp_detect_existing_installation "$ZPD_BASE"; then
        # Legacy single-directory install. The installer performs idempotent
        # infrastructure repair in place; migrating it to the atomic layout is
        # done by the updater (`zedproxy-update`), which keeps a legacy rollback.
        IS_EXISTING=true
        INSTALL_MODE="reinstall"
        INSTALL_LAYOUT="legacy"
        APP_DIR="${ZPD_BASE}"
        # A legacy install has no `current` symlink; keep services on the legacy
        # path until the updater performs the atomic migration.
        ACTIVE_APP_DIR="${ZPD_BASE}"
    else
        IS_EXISTING=false
        INSTALL_MODE="fresh"
        INSTALL_LAYOUT="fresh"
        # $APP_DIR is repointed at the initial release directory after clone.
    fi
}

# Undo a failed re-run: restore .env + code, exit maintenance mode, print DB
# restore instructions. Uploaded files and storage are never touched, and the
# original APP_KEY is preserved (it lives inside the restored .env).
rollback_existing() {
    warn "بازیابی تنظیمات قبلی..."
    if [ -n "$ENV_BACKUP_PATH" ] && [ -f "$ENV_BACKUP_PATH" ]; then
        cp "$ENV_BACKUP_PATH" "${APP_DIR}/.env" 2>/dev/null || true
        chmod 600 "${APP_DIR}/.env" 2>/dev/null || true
        ok ".env از نسخه پشتیبان بازیابی شد."
    fi
    if [ -n "$PRE_UPDATE_COMMIT" ] && zp_is_git_repo "$APP_DIR"; then
        git -C "$APP_DIR" reset --hard "$PRE_UPDATE_COMMIT" 2>/dev/null || true
        ok "کد به نسخهٔ قبلی بازگردانده شد (${PRE_UPDATE_COMMIT})."
    fi
    ( cd "$APP_DIR" && php artisan up >/dev/null 2>&1 ) || true
    MAINT_MODE_ON=false
    if [ -n "$DB_BACKUP_PATH" ]; then
        echo ""
        warn "اگر مهاجرت‌ها اجرا شده‌اند، دیتابیس را از این نسخهٔ پشتیبان بازیابی کنید:"
        echo -e "    PGPASSWORD=… pg_restore -h <host> -p <port> -U <user> -d <db> --clean --if-exists ${DB_BACKUP_PATH}"
    fi
}

# Controlled failure during a re-run: roll back, then abort with a message.
fail_reinstall() {
    rollback_existing
    error "$1"
}

# ─── OS Detection ────────────────────────────────────────────────────────────
OS_ID=""
OS_VERSION_ID=""
OS_CODENAME=""
OS_PRETTY=""

detect_os() {
    if [ ! -f /etc/os-release ]; then
        error "Cannot detect OS: /etc/os-release not found."
    fi

    OS_ID=$(grep -E '^ID=' /etc/os-release | cut -d= -f2 | tr -d '"')
    OS_VERSION_ID=$(grep -E '^VERSION_ID=' /etc/os-release | cut -d= -f2 | tr -d '"')
    OS_CODENAME=$(grep -E '^VERSION_CODENAME=' /etc/os-release | cut -d= -f2 | tr -d '"')
    OS_PRETTY=$(grep -E '^PRETTY_NAME=' /etc/os-release | cut -d= -f2 | tr -d '"')

    if [ "$OS_ID" != "ubuntu" ]; then
        error "This installer currently supports Ubuntu only.\nDetected: $OS_PRETTY"
    fi

    # Runtime version policy (single source of truth: supply-chain-lib.sh).
    if ! zsc_check_ubuntu "$OS_VERSION_ID"; then
        if [ "${ZP_ALLOW_UNSUPPORTED:-0}" = "1" ]; then
            warn "Ubuntu ${OS_VERSION_ID} در فهرست پشتیبانی‌شده (${ZSC_SUPPORTED_UBUNTU}) نیست — override فعال است."
        else
            error "Ubuntu ${OS_VERSION_ID} پشتیبانی نمی‌شود. نسخه‌های مجاز: ${ZSC_SUPPORTED_UBUNTU}.\nبرای ادامه با مسئولیت خود، ZP_ALLOW_UNSUPPORTED=1 را تنظیم کنید."
        fi
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

# ─── DNS validation before certbot ───────────────────────────────────────────
# Sets DOMAIN_RESOLVES and WWW_RESOLVES (true/false)
DOMAIN_RESOLVES=false
WWW_RESOLVES=false

check_domain_dns() {
    local domain="$1"
    local server_ip=""

    # Try multiple public IP services in case one is blocked
    for ip_url in \
        "https://api.ipify.org" \
        "https://ifconfig.me" \
        "https://icanhazip.com"; do
        server_ip=$(curl -fsSL --max-time 5 "$ip_url" 2>/dev/null | tr -d '[:space:]' || true)
        [[ "$server_ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && break
        server_ip=""
    done

    if [[ -z "$server_ip" ]]; then
        warn "Could not determine server public IP. Skipping DNS validation."
        warn "Certbot will attempt SSL anyway — it will fail if DNS is not pointed here."
        DOMAIN_RESOLVES=true
        WWW_RESOLVES=false
        return
    fi

    log "Server public IP: ${server_ip}"

    local domain_ip
    domain_ip=$(getent hosts "$domain" 2>/dev/null | awk '{print $1}' | head -1 || true)

    if [[ "$domain_ip" == "$server_ip" ]]; then
        DOMAIN_RESOLVES=true
        ok "DNS: ${domain} → ${domain_ip} (matches server IP)"
    else
        DOMAIN_RESOLVES=false
        if [[ -n "$domain_ip" ]]; then
            warn "DNS: ${domain} → ${domain_ip} (server IP is ${server_ip} — mismatch)"
        else
            warn "DNS: ${domain} does not resolve to any IP"
        fi
        warn "SSL certificate will likely fail until DNS propagates to this server."
    fi

    local www_ip
    www_ip=$(getent hosts "www.${domain}" 2>/dev/null | awk '{print $1}' | head -1 || true)

    if [[ "$www_ip" == "$server_ip" ]]; then
        WWW_RESOLVES=true
        ok "DNS: www.${domain} → ${www_ip} (matches server IP)"
    else
        WWW_RESOLVES=false
        if [[ -n "$www_ip" ]]; then
            warn "DNS: www.${domain} → ${www_ip} (mismatch — www excluded from SSL cert)"
        else
            warn "DNS: www.${domain} does not resolve — www excluded from SSL cert"
        fi
    fi
}

# ─── Let's Encrypt SSL setup ─────────────────────────────────────────────────
SSL_ACTIVE=false
SSL_STAGING=false
SSL_FAIL_REASON=""

setup_ssl() {
    log "Installing certbot..."
    apt-get install -y -qq \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" \
        certbot python3-certbot-nginx

    # Check whether a valid cert already exists — reuse it to avoid rate limits
    local cert_file="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    if [ -f "$cert_file" ]; then
        if openssl x509 -checkend 86400 -noout -in "$cert_file" 2>/dev/null; then
            ok "Found existing valid Let's Encrypt certificate for ${DOMAIN} — reusing it."
            # Run certbot --reinstall to wire up Nginx without requesting a new cert
            local reinstall_exit=0
            certbot --nginx \
                -d "$DOMAIN" \
                --non-interactive \
                --agree-tos \
                --no-eff-email \
                -m "$ADMIN_EMAIL" \
                --reinstall \
                --redirect 2>&1 || reinstall_exit=$?
            if [ "$reinstall_exit" -eq 0 ]; then
                SSL_ACTIVE=true
                ok "Existing SSL certificate reinstalled for ${DOMAIN}"
            else
                warn "Could not reinstall existing certificate (exit ${reinstall_exit}). Requesting new one..."
            fi
        else
            warn "Existing certificate for ${DOMAIN} is expired or nearly expired — requesting new one."
        fi
    fi

    if [ "$SSL_ACTIVE" = "false" ]; then
        log "Validating DNS for ${DOMAIN}..."
        check_domain_dns "$DOMAIN"

        # Build certbot -d args: always include bare domain; include www only if it resolves here
        local certbot_domains="-d ${DOMAIN}"
        if [ "$WWW_RESOLVES" = "true" ]; then
            certbot_domains="${certbot_domains} -d www.${DOMAIN}"
            log "Including www.${DOMAIN} in the SSL certificate"
        else
            log "www.${DOMAIN} not included (DNS mismatch or not resolving)"
        fi

        local staging_flag=""
        if [ "$SSL_STAGING" = "true" ]; then
            staging_flag="--staging"
            warn "Using Let's Encrypt STAGING — certificate will NOT be trusted by browsers"
        fi

        log "Running certbot for ${DOMAIN}..."
        local certbot_exit=0
        local certbot_output
        # shellcheck disable=SC2086
        certbot_output=$(certbot --nginx \
            $certbot_domains \
            $staging_flag \
            --non-interactive \
            --agree-tos \
            --redirect \
            --no-eff-email \
            -m "$ADMIN_EMAIL" 2>&1) || certbot_exit=$?

        echo "$certbot_output"

        if [ "$certbot_exit" -eq 0 ]; then
            SSL_ACTIVE=true
            ok "SSL certificate issued for ${DOMAIN}"
        else
            # Detect specific failure reasons from certbot output
            if echo "$certbot_output" | grep -qiE "too many certificates|rate limit|too many failed"; then
                SSL_FAIL_REASON="rate_limit"
                warn "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
                warn "SSL FAILED: Let's Encrypt rate limit reached."
                warn "Too many certificates have been issued for this domain"
                warn "in the past 168 hours (7 days)."
                warn ""
                warn "Wait until the retry-after time shown by Certbot above,"
                warn "or use a different subdomain to avoid the limit."
                warn "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
            elif echo "$certbot_output" | grep -qiE "dns|not resolve|NXDOMAIN|no valid"; then
                SSL_FAIL_REASON="dns"
                warn "SSL FAILED: DNS did not resolve correctly for ${DOMAIN}."
                warn "Point your domain's A record to this server IP, then retry certbot."
            else
                SSL_FAIL_REASON="other"
                warn "Certbot exited with code ${certbot_exit} — SSL was not configured."
            fi
            warn "The HTTP site is still fully working."
            warn "To install SSL manually once the issue is resolved:"
            warn "  certbot --nginx -d ${DOMAIN} -m ${ADMIN_EMAIL} --non-interactive --agree-tos --redirect --no-eff-email"
        fi
    fi

    if [ "$SSL_ACTIVE" = "true" ]; then
        # Update APP_URL in .env to https
        sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" "${APP_DIR}/.env"
        ok "APP_URL updated to https://${DOMAIN}"

        # Rebuild all caches with new APP_URL (route/view caches may embed URLs)
        cd "$APP_DIR"
        php artisan optimize:clear
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        ok "Application caches refreshed (APP_URL → https)"

        # Verify HTTPS health endpoint
        sleep 3
        local https_health
        https_health=$(curl -sk -o /dev/null -w "%{http_code}" "https://${DOMAIN}/health" 2>/dev/null || echo "000")
        if [ "$https_health" = "200" ]; then
            ok "HTTPS health check PASSED (https://${DOMAIN}/health → HTTP 200)"
        else
            warn "HTTPS health check returned HTTP ${https_health} — site may still be starting."
            warn "Run manually: curl https://${DOMAIN}/health"
        fi
    fi
}

# ─── PHP installation ─────────────────────────────────────────────────────────
PHP_MIN_VERSION="8.2"
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
        php-bcmath php-gd php-intl php-opcache || true

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

        # Strip invisible/control characters, then all whitespace
        INPUT_DOMAIN=$(printf '%s' "$INPUT_DOMAIN" | LC_ALL=C tr -cd '[:print:]')
        INPUT_DOMAIN="${INPUT_DOMAIN//[[:space:]]/}"

        # Strip http:// or https:// prefix if accidentally included
        INPUT_DOMAIN="${INPUT_DOMAIN#http://}"
        INPUT_DOMAIN="${INPUT_DOMAIN#https://}"

        # Strip trailing slash
        INPUT_DOMAIN="${INPUT_DOMAIN%/}"

        if [[ -z "$INPUT_DOMAIN" ]]; then
            warn "Domain cannot be empty. Please try again."
            continue
        fi

        # Basic domain format validation: must contain a dot, only valid chars
        if ! [[ "$INPUT_DOMAIN" =~ ^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)+$ ]]; then
            warn "Invalid domain format: '${INPUT_DOMAIN}'. Enter a valid domain, e.g.: zedproxy.com"
            continue
        fi

        DOMAIN="$INPUT_DOMAIN"
        ok "Domain: $DOMAIN"
        break
    done
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
    echo -e "\n${BLUE}Enter admin username (used to log in to the admin panel):${NC}"
    echo -e "${BLUE}Press Enter to generate automatically: ${YELLOW}${default_name}${NC}"
    read -r INPUT_NAME </dev/tty

    ADMIN_NAME="${INPUT_NAME:-$default_name}"
    ok "Admin username: $ADMIN_NAME"
}

_prompt_admin_password() {
    echo -e "\n${BLUE}Enter admin password (input hidden):${NC}"
    echo -e "${BLUE}Press Enter to generate a strong random password automatically:${NC}"
    read -rs INPUT_PASS </dev/tty
    echo ""  # newline after hidden input

    if [[ -z "$INPUT_PASS" ]]; then
        ADMIN_PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9!@#$%^&*' | head -c 24)
        ok "Admin password: (تولید شد — فقط از مسیر امن نمایش داده می‌شود)"
    else
        ADMIN_PASS="$INPUT_PASS"
        ok "Admin password: (دریافت شد — فقط از مسیر امن نمایش داده می‌شود)"
    fi
}

_prompt_ssl() {
    echo -e "\n${BLUE}Install free SSL certificate with Let's Encrypt (certbot)? [Y/n]:${NC}"
    read -r INPUT_SSL </dev/tty
    INPUT_SSL="${INPUT_SSL//[[:space:]]/}"
    INPUT_SSL="${INPUT_SSL,,}"

    if [[ "$INPUT_SSL" == "n" || "$INPUT_SSL" == "no" ]]; then
        INSTALL_SSL=false
        SSL_STAGING=false
        ok "SSL: skipped (you can add it later with certbot)"
        return
    fi

    INSTALL_SSL=true

    echo -e "\n${BLUE}Use Let's Encrypt STAGING mode? (testing only — not trusted by browsers) [y/N]:${NC}"
    read -r INPUT_STAGING </dev/tty
    INPUT_STAGING="${INPUT_STAGING//[[:space:]]/}"
    INPUT_STAGING="${INPUT_STAGING,,}"

    if [[ "$INPUT_STAGING" == "y" || "$INPUT_STAGING" == "yes" ]]; then
        SSL_STAGING=true
        ok "SSL: staging mode (test cert — not browser-trusted)"
    else
        SSL_STAGING=false
        ok "SSL: will use production Let's Encrypt certificate"
    fi
}

# ─── Optional admin password reset (re-run only) ──────────────────────────────
_prompt_admin_password_reset() {
    echo -e "\n${BLUE}آیا رمز عبور مدیر فعلی بازنشانی شود؟ [y/N]${NC}"
    read -r INPUT_RESET </dev/tty
    INPUT_RESET="${INPUT_RESET//[[:space:]]/}"
    INPUT_RESET="${INPUT_RESET,,}"
    if [[ "$INPUT_RESET" == "y" || "$INPUT_RESET" == "yes" ]]; then
        RESET_ADMIN_PASSWORD=true
        # Target the EXISTING admin explicitly (no random default) so we update
        # the right record instead of creating a second admin.
        echo -e "${BLUE}نام کاربری مدیری که رمز آن بازنشانی می‌شود:${NC}"
        read -r ADMIN_NAME </dev/tty
        ADMIN_NAME="${ADMIN_NAME//[[:space:]]/}"
        while [[ -z "$ADMIN_NAME" ]]; do
            warn "نام کاربری نمی‌تواند خالی باشد."
            read -r ADMIN_NAME </dev/tty
            ADMIN_NAME="${ADMIN_NAME//[[:space:]]/}"
        done
        echo -e "${BLUE}ایمیل همان مدیر (Enter برای ${YELLOW}admin@${DOMAIN}${BLUE}):${NC}"
        read -r INPUT_ADMIN_EMAIL </dev/tty
        INPUT_ADMIN_EMAIL="${INPUT_ADMIN_EMAIL//[[:space:]]/}"
        ADMIN_EMAIL="${INPUT_ADMIN_EMAIL:-admin@${DOMAIN}}"
        _prompt_admin_password
        ok "رمز عبور مدیر «${ADMIN_NAME}» پس از بروزرسانی بازنشانی خواهد شد."
    else
        RESET_ADMIN_PASSWORD=false
        ok "رمز عبور مدیر فعلی حفظ می‌شود."
    fi
}

# ─── Run OS detection first ───────────────────────────────────────────────────
detect_os

# ─── Determine install mode (fresh vs. safe re-run) ───────────────────────────
detect_install_mode

echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ZedProxy Interactive Setup${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"

if [ "$IS_EXISTING" = "true" ]; then
    # ── Safe re-run / repair / upgrade of an existing installation ──
    echo ""
    echo -e "${YELLOW}نصب قبلی ZedProxy شناسایی شد.${NC}"
    echo -e "${YELLOW}مقادیر APP_KEY، اطلاعات دیتابیس و تنظیمات فعلی حفظ خواهند شد.${NC}"
    echo ""

    # Reuse the domain from the existing .env (APP_URL) instead of re-prompting.
    EXISTING_APP_URL=$(zp_env_get "${APP_DIR}/.env" "APP_URL" || true)
    EXISTING_DOMAIN=$(printf '%s' "$EXISTING_APP_URL" | sed -E 's#^https?://##; s#/.*$##')
    if [[ -n "$EXISTING_DOMAIN" ]]; then
        DOMAIN="$EXISTING_DOMAIN"
        ok "دامنهٔ فعلی: ${DOMAIN}"
    else
        _prompt_domain
    fi

    ADMIN_EMAIL="admin@${DOMAIN}"   # used only for certbot on re-run
    ADMIN_NAME=""
    ADMIN_PASS=""

    _prompt_admin_password_reset
    _prompt_ssl
else
    # ── Fresh installation ──
    _prompt_domain
    _prompt_admin_email
    _prompt_admin_name
    _prompt_admin_password
    _prompt_ssl
fi

echo ""
echo -e "${BLUE}────────────────────────────────────────────────────────────${NC}"
if [ "$IS_EXISTING" = "true" ]; then
    echo -e "  Mode:        ${YELLOW}safe re-run (existing installation)${NC}"
else
    echo -e "  Mode:        ${YELLOW}fresh installation${NC}"
fi
echo -e "  Domain:      ${YELLOW}${DOMAIN}${NC}"
if [ "$IS_EXISTING" != "true" ]; then
    echo -e "  Admin email: ${YELLOW}${ADMIN_EMAIL}${NC}"
    echo -e "  Admin user:  ${YELLOW}${ADMIN_NAME}${NC}"
    echo -e "  Password:    ${YELLOW}(تنظیم شد — فقط از مسیر امن نمایش داده می‌شود)${NC}"
else
    echo -e "  Admin:       ${YELLOW}preserved (no changes)${NC}"
fi
if [ "$INSTALL_SSL" = "true" ]; then
    if [ "$SSL_STAGING" = "true" ]; then
        echo -e "  SSL:         ${YELLOW}yes — Let's Encrypt STAGING (test only)${NC}"
    else
        echo -e "  SSL:         ${YELLOW}yes — Let's Encrypt (production)${NC}"
    fi
else
    echo -e "  SSL:         ${YELLOW}no — HTTP only${NC}"
fi
echo -e "${BLUE}────────────────────────────────────────────────────────────${NC}"
echo -e "${BLUE}Proceeding with installation in 3 seconds... (Ctrl+C to cancel)${NC}"
sleep 3

# ─── Static configuration ─────────────────────────────────────────────────────
NODE_VERSION="22"
NGINX_CONF="/etc/nginx/sites-available/zedproxy"

if [ "$IS_EXISTING" = "true" ]; then
    # Re-run: reuse the existing database credentials verbatim. The role
    # password is NEVER rotated and the database is NEVER recreated.
    DB_NAME=$(zp_env_get "${APP_DIR}/.env" "DB_DATABASE" || true)
    DB_USER=$(zp_env_get "${APP_DIR}/.env" "DB_USERNAME" || true)
    DB_PASS=$(zp_env_get "${APP_DIR}/.env" "DB_PASSWORD" || true)
    DB_HOST=$(zp_env_get "${APP_DIR}/.env" "DB_HOST" || true); DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT=$(zp_env_get "${APP_DIR}/.env" "DB_PORT" || true); DB_PORT="${DB_PORT:-5432}"
    if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
        error "اطلاعات دیتابیس در .env موجود نیست. نصب متوقف شد تا از تخریب داده جلوگیری شود."
    fi
else
    # Fresh: create the database + role with a freshly generated password.
    DB_NAME="zedproxy"
    DB_USER="zedproxy_user"
    DB_PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9!@#$%^&*' | head -c 32)
    DB_HOST="127.0.0.1"
    DB_PORT="5432"
fi

# APP_URL starts as HTTP — setup_ssl() upgrades it to HTTPS on success
APP_URL="http://${DOMAIN}"

log "Starting ZedProxy installation..."
log "OS: $OS_PRETTY"
log "App directory: $APP_DIR"
log "Domain: $DOMAIN"
log "Log file: $LOG_FILE"

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

# ─── Composer (verified installer, no `curl | php`) ──────────────────────────
install_composer_verified() {
    log "Installing Composer (verified)..."

    # Download the installer + the official SHA-384 signature to private temp
    # files (umask 077), verify BEFORE executing, then always clean up.
    local tmpdir installer sig expected
    tmpdir="$(mktemp -d)" || error "mktemp failed for Composer install."
    chmod 700 "$tmpdir"
    installer="${tmpdir}/composer-setup.php"
    sig="${tmpdir}/installer.sig"
    # shellcheck disable=SC2064
    trap "rm -rf '$tmpdir'" RETURN

    ( umask 077
      curl --fail --location --show-error --silent --proto '=https' --tlsv1.2 \
           --max-time 60 --retry 3 --retry-delay 2 \
           "$ZSC_COMPOSER_INSTALLER_URL" -o "$installer" ) \
        || error "دانلود نصب‌کنندهٔ Composer ناموفق بود. عملیات متوقف شد."

    ( umask 077
      curl --fail --location --show-error --silent --proto '=https' --tlsv1.2 \
           --max-time 30 --retry 3 --retry-delay 2 \
           "$ZSC_COMPOSER_SIG_URL" -o "$sig" ) \
        || error "$ZSC_MSG_COMPOSER_BAD"

    expected="$(tr -d '[:space:]' < "$sig")"
    if ! zsc_verify_composer_installer "$installer" "$expected"; then
        # Never print the installer contents; only report the failure.
        error "$ZSC_MSG_COMPOSER_BAD"
    fi
    ok "امضای SHA-384 نصب‌کنندهٔ Composer تأیید شد."

    # Verified — safe to execute. Install into a controlled path.
    php "$installer" --quiet --install-dir=/usr/local/bin --filename=composer \
        || error "$ZSC_MSG_COMPOSER_BAD"
    trap - RETURN
    rm -rf "$tmpdir"

    command -v composer >/dev/null 2>&1 || error "Composer executable not found after install."
}

if ! command -v composer &>/dev/null; then
    install_composer_verified
fi

# Verify the installed Composer and enforce the supported version range.
COMPOSER_VERSION="$(composer --version --no-ansi 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1)"
if [ -n "$COMPOSER_VERSION" ] && ! zsc_check_composer "$COMPOSER_VERSION"; then
    if [ "${ZP_ALLOW_UNSUPPORTED:-0}" = "1" ]; then
        warn "Composer ${COMPOSER_VERSION} خارج از بازهٔ پشتیبانی‌شده (${ZSC_COMPOSER_MIN}–${ZSC_COMPOSER_MAX}) است — override فعال است."
    else
        error "Composer ${COMPOSER_VERSION} پشتیبانی نمی‌شود (بازهٔ مجاز: ${ZSC_COMPOSER_MIN}–${ZSC_COMPOSER_MAX}). برای ادامه ZP_ALLOW_UNSUPPORTED=1 تنظیم کنید."
    fi
fi
ok "Composer: $(composer --version --no-ansi)"

# ─── Node.js (verified NodeSource repo, no `curl | bash`) ────────────────────
install_node_verified() {
    log "Installing Node.js ${NODE_VERSION} (verified repository)..."

    # Tooling required to fetch + verify the signing key.
    apt-get install -y -qq \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" \
        ca-certificates curl gnupg apt-transport-https

    local tmpkey
    tmpkey="$(mktemp)" || error "mktemp failed for Node key."
    # shellcheck disable=SC2064
    trap "rm -f '$tmpkey'" RETURN

    ( umask 077
      curl --fail --location --show-error --silent --proto '=https' --tlsv1.2 \
           --max-time 30 --retry 3 --retry-delay 2 \
           "$ZSC_NODE_KEY_URL" -o "$tmpkey" ) \
        || error "$ZSC_MSG_NODE_BAD"

    # Dearmor into a dedicated keyring and verify a real public key was imported.
    install -d -m 0755 /usr/share/keyrings
    rm -f "$ZSC_NODE_KEYRING"
    gpg --dearmor -o "$ZSC_NODE_KEYRING" < "$tmpkey" 2>/dev/null \
        || error "$ZSC_MSG_NODE_BAD"
    chmod 0644 "$ZSC_NODE_KEYRING"

    if ! zsc_gpg_key_valid "$ZSC_NODE_KEYRING"; then
        rm -f "$ZSC_NODE_KEYRING"
        error "$ZSC_MSG_NODE_BAD"
    fi
    ok "کلید امضای مخزن Node.js تأیید و ذخیره شد."

    # Configure the apt source pinned to the supported major, signed-by keyring.
    printf 'deb [signed-by=%s] https://deb.nodesource.com/node_%s.x nodistro main\n' \
        "$ZSC_NODE_KEYRING" "$NODE_VERSION" > /etc/apt/sources.list.d/nodesource.list
    chmod 0644 /etc/apt/sources.list.d/nodesource.list

    trap - RETURN
    rm -f "$tmpkey"

    safe_apt_update || error "$ZSC_MSG_NODE_BAD"
    apt-get install -y -qq \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" \
        nodejs \
        || error "نصب Node.js از مخزن تأییدشده ناموفق بود."
}

if ! command -v node &>/dev/null || [[ $(node --version | cut -d'v' -f2 | cut -d'.' -f1) -lt $NODE_VERSION ]]; then
    install_node_verified
fi

# Verify Node + npm and enforce the supported versions.
NODE_INSTALLED="$(node --version 2>/dev/null || echo unknown)"
NPM_INSTALLED="$(npm --version 2>/dev/null || echo unknown)"
if ! zsc_check_node_major "$NODE_INSTALLED"; then
    if [ "${ZP_ALLOW_UNSUPPORTED:-0}" = "1" ]; then
        warn "Node.js ${NODE_INSTALLED} خارج از نسخهٔ پشتیبانی‌شده (major ${ZSC_NODE_MAJOR}) است — override فعال است."
    else
        error "Node.js ${NODE_INSTALLED} پشتیبانی نمی‌شود (major مجاز: ${ZSC_NODE_MAJOR}). برای ادامه ZP_ALLOW_UNSUPPORTED=1 تنظیم کنید."
    fi
fi
ok "Node.js: ${NODE_INSTALLED}, npm: ${NPM_INSTALLED}"

# ─── Dependency vulnerability audit (policy in supply-chain-lib.sh) ───────────
# Policy: critical → fail; high → fail unless an unexpired allowlist entry
# exists; moderate/low → report and continue. Audit output is masked so no
# path/credential leaks, and audit failures are NEVER swallowed with `|| true`.
AUDIT_ALLOWLIST="${AUDIT_ALLOWLIST:-${APP_DIR}/.zedproxy/audit-allowlist}"

run_supply_chain_audit() {
    log "Running dependency vulnerability audit..."
    local today findings=0 fails=0 tmp
    today="$(date -u +%Y-%m-%d)"
    tmp="$(mktemp)" || return 0
    # shellcheck disable=SC2064
    trap "rm -f '$tmp'" RETURN

    # Composer advisories → "package<TAB>advisory<TAB>severity" lines.
    composer audit --format=json --no-interaction 2>/dev/null \
        | php -r '$d=json_decode(stream_get_contents(STDIN),true)?:[]; foreach(($d["advisories"]??[]) as $p=>$as){foreach($as as $a){printf("%s\t%s\t%s\n",$p,$a["advisoryId"]??($a["cve"]??"unknown"),strtolower($a["severity"]??"unknown"));}}' \
        >> "$tmp" 2>/dev/null || true

    # npm advisories → same shape (severity from each vulnerability entry).
    npm audit --json 2>/dev/null \
        | php -r '$d=json_decode(stream_get_contents(STDIN),true)?:[]; foreach(($d["vulnerabilities"]??[]) as $n=>$v){$sev=strtolower($v["severity"]??"unknown"); $id="npm"; if(!empty($v["via"])&&is_array($v["via"])){foreach($v["via"] as $via){if(is_array($via)){$id=(string)($via["url"]??$via["source"]??"npm");break;}}} printf("%s\t%s\t%s\n",$n,$id,$sev);}' \
        >> "$tmp" 2>/dev/null || true

    local pkg adv sev allowed decision
    while IFS=$'\t' read -r pkg adv sev; do
        [ -n "$pkg" ] || continue
        findings=$((findings + 1))
        allowed=0
        if zsc_allowlist_entry_active "$AUDIT_ALLOWLIST" "$pkg" "$adv" "$today"; then
            allowed=1
        fi
        decision="$(zsc_audit_decision "$sev" "$allowed")"
        case "$decision" in
            fail)
                warn "[audit:FAIL] $(printf '%s advisory=%s severity=%s' "$pkg" "$adv" "$sev" | zsc_mask_secrets)"
                fails=$((fails + 1)) ;;
            report)
                if [ "$allowed" = "1" ]; then
                    log "[audit:allowlisted] $(printf '%s advisory=%s severity=%s' "$pkg" "$adv" "$sev" | zsc_mask_secrets)"
                else
                    warn "[audit:report] $(printf '%s advisory=%s severity=%s' "$pkg" "$adv" "$sev" | zsc_mask_secrets)"
                fi ;;
        esac
    done < "$tmp"

    trap - RETURN
    rm -f "$tmp"

    if [ "$fails" -gt 0 ]; then
        error "ممیزی امنیتی وابستگی‌ها ${fails} مورد بحرانی/بالای رفع‌نشده یافت. نصب متوقف شد. برای استثنای موقت، ورودی معتبر با تاریخ انقضا در ${AUDIT_ALLOWLIST} اضافه کنید."
    fi
    if [ "$findings" -eq 0 ]; then
        ok "ممیزی امنیتی: هیچ آسیب‌پذیری شناخته‌شده‌ای یافت نشد."
    else
        ok "ممیزی امنیتی کامل شد (${findings} مورد؛ بدون آسیب‌پذیری بحرانی/بالای مسدودکننده)."
    fi
}

# ─── PostgreSQL ──────────────────────────────────────────────────────────────
log "Installing PostgreSQL..."
apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    postgresql postgresql-contrib

systemctl enable postgresql
systemctl start postgresql

if [ "$IS_EXISTING" = "true" ]; then
    # Re-run: DO NOT create/drop the database or rotate the role password.
    # Only verify that the stored credentials still connect before continuing.
    log "Verifying existing database connection (no changes made)..."
    if PGPASSWORD="$DB_PASS" timeout 15s psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -tAc 'SELECT 1' </dev/null >/dev/null 2>&1; then
        ok "اتصال به دیتابیس موجود ('${DB_NAME}') برقرار است."
    else
        error "اتصال به دیتابیس با اطلاعات موجود در .env ناموفق بود. نصب متوقف شد؛ هیچ تغییری اعمال نشد.\n  دیتابیس: ${DB_NAME} — کاربر: ${DB_USER} — میزبان: ${DB_HOST}:${DB_PORT}"
    fi
else
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
fi

# ─── Redis ───────────────────────────────────────────────────────────────────
log "Installing Redis..."
apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    redis-server

sed -i 's/^bind .*/bind 127.0.0.1/' /etc/redis/redis.conf

systemctl enable redis-server
systemctl start redis-server

timeout 15s redis-cli ping </dev/null | grep -q PONG || error "Redis did not respond to PING"
ok "Redis is running"

# ─── Nginx ───────────────────────────────────────────────────────────────────
log "Installing Nginx..."

# Stop Apache if it is running — it holds port 80 and blocks Nginx
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

# Check port 80 for conflicts.
# nginx on port 80 is expected (this may be a re-run) — skip it.
# Apache is already handled above. Any other process is a hard stop.
_port80_other=$(ss -ltnp 2>/dev/null | grep ':80 ' | grep -v '"nginx"' || true)
if [ -n "$_port80_other" ]; then
    _proc=$(echo "$_port80_other" | grep -oP 'users:\(\("\K[^"]+' 2>/dev/null || echo "unknown")
    error "Port 80 is in use by '${_proc}' and cannot be freed automatically.\n\nProcess details:\n${_port80_other}\n\nStop the conflicting service manually, then re-run the installer."
fi

apt-get install -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    nginx

# ─── Project directory preparation ───────────────────────────────────────────
prepare_project_directory() {
    # Allow git to operate on APP_DIR when owned by www-data (re-run scenario)
    git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

    # ── Existing installation: never clone-fresh. Back up .env, record the
    #    deployed commit, protect local changes, enter maintenance mode, then
    #    do a safe code update. .env and gitignored runtime files (storage,
    #    uploaded media) are untracked/ignored and are never touched by
    #    reset --hard or clean -fd (clean runs WITHOUT -x). ──
    if [ "$IS_EXISTING" = "true" ]; then
        ENV_BACKUP_PATH=$(zp_backup_env "${APP_DIR}/.env" "$REINSTALL_BACKUP_ROOT" "$REINSTALL_TS") \
            || fail_reinstall "تهیهٔ نسخهٔ پشتیبان از .env ناموفق بود. عملیات متوقف شد."
        ok "نسخهٔ پشتیبان .env: ${ENV_BACKUP_PATH} (۶۰۰)"

        # Enter maintenance mode so the live site serves 503 during the update.
        ( cd "$APP_DIR" && php artisan down --render="errors::503" >/dev/null 2>&1 ) \
            || ( cd "$APP_DIR" && php artisan down >/dev/null 2>&1 ) || true
        MAINT_MODE_ON=true

        if [ "$INSTALL_LAYOUT" = "atomic" ]; then
            # Already-atomic safe repair: NEVER git reset --hard the live release.
            # Code changes must go through the atomic updater (zedproxy-update);
            # the installer only repairs infrastructure + re-runs deps/migrations
            # idempotently against the active release.
            warn "نصب اتمیک شناسایی شد — کد نسخهٔ فعال تغییر نمی‌کند."
            warn "برای به‌روزرسانی کد از دستور zedproxy-update استفاده کنید."
            ok "تعمیر ایمن زیرساخت روی نسخهٔ فعال انجام می‌شود (بدون بازنشانی کد)."
        elif zp_is_git_repo "$APP_DIR"; then
            PRE_UPDATE_COMMIT=$(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo "")
            [ -n "$PRE_UPDATE_COMMIT" ] && ok "کامیت فعلی برای بازگردانی ثبت شد: ${PRE_UPDATE_COMMIT}"

            if zp_git_has_local_changes "$APP_DIR"; then
                local lc
                lc=$(zp_git_backup_local_changes "$APP_DIR" "$REINSTALL_BACKUP_ROOT" "$REINSTALL_TS" || true)
                [ -n "$lc" ] && warn "تغییرات محلی پیش از بروزرسانی در ${lc} پشتیبان‌گیری شد."
            fi

            log "بروزرسانی امن کد به origin/${BRANCH}..."
            git -C "$APP_DIR" fetch origin "$BRANCH"
            git -C "$APP_DIR" reset --hard "origin/${BRANCH}"
            git -C "$APP_DIR" clean -fd      # no -x: ignored runtime files kept

            # Safety net — restore .env if a hook/edge case removed it.
            if [ ! -f "${APP_DIR}/.env" ] && [ -f "$ENV_BACKUP_PATH" ]; then
                cp "$ENV_BACKUP_PATH" "${APP_DIR}/.env"; chmod 600 "${APP_DIR}/.env"
                warn ".env پس از بروزرسانی کد از نسخهٔ پشتیبان بازیابی شد."
            fi
            ok "کد بروزرسانی شد؛ .env، storage و فایل‌های بارگذاری‌شده حفظ شدند."
        else
            warn "نصب موجود بدون مخزن Git است — بروزرسانی کد نادیده گرفته شد."
            warn "فقط وابستگی‌ها، مهاجرت‌ها و کش‌ها روی کد فعلی اجرا می‌شوند."
        fi
        return 0
    fi

    # ── Fresh installation → build directly into the atomic release layout ──
    # A non-empty, non-atomic base directory that is not our layout is backed up.
    if [ -e "$ZPD_BASE" ] && [ ! -d "$ZPD_RELEASES" ] && [ -n "$(ls -A "$ZPD_BASE" 2>/dev/null || true)" ]; then
        local backup_dir="/var/www/zedproxy_backup_$(date +%Y%m%d_%H%M%S)"
        warn "${ZPD_BASE} exists and is not a ZedProxy atomic layout."
        warn "Backing it up to ${backup_dir} before creating a fresh release layout..."
        mv "$ZPD_BASE" "$backup_dir"
        ok "Backup saved to ${backup_dir}"
    fi

    mkdir -p "$ZPD_RELEASES" "$ZPD_SHARED"

    # Clone into a private pending directory, resolve the EXACT commit, then name
    # the release from it — a release id can never end in "-nogit".
    local ref pending sha
    ref="${INSTALL_REF:-$BRANCH}"
    pending="${ZPD_RELEASES}/.pending.$$"
    rm -rf "$pending" 2>/dev/null || true

    log "Cloning ${REPO_URL} (ref: ${ref}) into a new release…"
    timeout "${ZPD_GIT_CLONE_TIMEOUT:-900}s" env GIT_TERMINAL_PROMPT=0 \
        git clone "$REPO_URL" "$pending" </dev/null \
        || { rm -rf "$pending"; error "دریافت کد پروژه از GitHub ناموفق بود. نصب متوقف شد."; }
    timeout "${ZPD_GIT_NET_TIMEOUT:-60}s" env GIT_TERMINAL_PROMPT=0 \
        git -C "$pending" fetch --tags --force --quiet origin </dev/null 2>/dev/null || true
    git -C "$pending" checkout --quiet --detach "$ref" 2>/dev/null \
        || { rm -rf "$pending"; error "ref موردنظر برای نصب (${ref}) قابل‌بازیابی نبود. نصب متوقف شد."; }

    sha="$(git -C "$pending" rev-parse HEAD 2>/dev/null || echo "")"
    [ -n "$sha" ] || { rm -rf "$pending"; error "commit مقصد قابل‌تشخیص نبود. نصب متوقف شد."; }
    INITIAL_RELEASE_ID="$(date -u +%Y%m%d%H%M%S)-$(printf '%s' "$sha" | tr -cd '0-9a-fA-F' | cut -c1-12)"
    INITIAL_RELEASE_DIR="${ZPD_RELEASES}/${INITIAL_RELEASE_ID}"
    mv "$pending" "$INITIAL_RELEASE_DIR" \
        || { rm -rf "$pending"; error "ایجاد دایرکتوری نسخه ناموفق بود. نصب متوقف شد."; }

    # From here on APP_DIR is the initial release directory; every existing build
    # and configuration step operates on it, while shared state lives in $ZPD_SHARED.
    APP_DIR="$INITIAL_RELEASE_DIR"
    ok "Initial release created: ${INITIAL_RELEASE_ID}"

    prepare_shared_and_link "$APP_DIR"
    ok "Shared storage prepared and linked (.env, storage, public/storage)"
}

# ─── Shared-state provisioning for a fresh release ───────────────────────────
# Seed $ZPD_SHARED from the freshly-cloned release, then replace the release's
# .env / storage / public/storage with symlinks into shared so every future
# release sees the same encryption key and uploads.
prepare_shared_and_link() {
    local rel="$1"
    mkdir -p "${ZPD_SHARED}/persistent" 2>/dev/null || true

    # Seed shared storage from the cloned skeleton (first release only).
    if [ -d "${rel}/storage" ] && [ ! -e "${ZPD_SHARED}/storage" ]; then
        mv "${rel}/storage" "${ZPD_SHARED}/storage"
    fi
    mkdir -p "${ZPD_SHARED}/storage/app/public" \
             "${ZPD_SHARED}/storage/framework/cache" \
             "${ZPD_SHARED}/storage/framework/sessions" \
             "${ZPD_SHARED}/storage/framework/views" \
             "${ZPD_SHARED}/storage/logs" 2>/dev/null || true

    # .env placeholder so the release symlink resolves before .env is written.
    if [ ! -e "${ZPD_SHARED}/.env" ]; then
        : > "${ZPD_SHARED}/.env"
        chmod 600 "${ZPD_SHARED}/.env" 2>/dev/null || true
    fi

    # Wire release → shared.
    rm -rf "${rel}/.env" "${rel}/storage" 2>/dev/null || true
    ln -s "${ZPD_SHARED}/.env" "${rel}/.env"
    ln -s "${ZPD_SHARED}/storage" "${rel}/storage"
    mkdir -p "${rel}/public" 2>/dev/null || true
    rm -rf "${rel}/public/storage" 2>/dev/null || true
    ln -s "${ZPD_SHARED}/storage/app/public" "${rel}/public/storage"
}

# ─── Atomic activation of the initial release ────────────────────────────────
# Point $ZPD_CURRENT at the initial release via an atomic symlink swap, exactly
# like the updater, so services can target current/ from the very first install.
activate_initial_release() {
    [ -n "$INITIAL_RELEASE_ID" ] || return 0
    if declare -F zpd_switch_current >/dev/null 2>&1; then
        ZPD_BASE="$ZPD_BASE" zpd_switch_current "$INITIAL_RELEASE_ID" \
            || error "فعال‌سازی نسخهٔ اولیه (symlink current) ناموفق بود."
    else
        local tmp="${ZPD_CURRENT}.tmp.$$"
        ln -s "releases/${INITIAL_RELEASE_ID}" "$tmp" \
            && mv -Tf "$tmp" "$ZPD_CURRENT" \
            || { rm -f "$tmp"; error "فعال‌سازی نسخهٔ اولیه (symlink current) ناموفق بود."; }
    fi
    ok "Active release symlink: current → releases/${INITIAL_RELEASE_ID}"
}

# ─── Release / commit metadata (records the RESOLVED SHA, never just a branch) ─
record_install_metadata() {
    local sha tag rid meta
    sha="$(zsc_git_resolve "$APP_DIR" HEAD 2>/dev/null || echo unknown)"
    tag="$(zsc_git_tag_for "$APP_DIR" "$sha" 2>/dev/null || true)"
    rid="$(date -u +%Y%m%d%H%M%S)-$(printf '%s' "$sha" | tr -cd '0-9a-fA-F' | cut -c1-12)"
    meta="${APP_DIR}/storage/app/release-metadata.json"
    mkdir -p "$(dirname "$meta")" 2>/dev/null || true
    ( umask 077
      cat > "$meta" <<JSON
{
  "release_id": "${rid}",
  "commit": "${sha}",
  "ref": "${INSTALL_REF:-${BRANCH}}",
  "tag": "${tag}",
  "build_timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "php_version": "$(zsc_tool_version php)",
  "composer_version": "$(zsc_tool_version composer)",
  "node_version": "$(zsc_tool_version node)",
  "npm_version": "$(zsc_tool_version npm)"
}
JSON
    )
    chmod 640 "$meta" 2>/dev/null || true
    log "Release metadata recorded: commit ${sha}${tag:+ (tag ${tag})} → ${meta}"
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
        echo -e "${RED}[ERROR]${NC} The Laravel project was not cloned correctly." >&2
        echo -e "${RED}[ERROR]${NC} The following required files/folders are missing from ${APP_DIR}:" >&2
        for m in "${missing[@]}"; do
            echo -e "         - ${m}" >&2
        done
        echo "" >&2
        echo -e "${RED}[ERROR]${NC} Check that the main branch contains the Laravel project." >&2
        echo "         Repository: ${REPO_URL}" >&2
        echo "         Branch:     ${BRANCH}" >&2
        exit 1
    fi

    ok "Laravel project structure verified in ${APP_DIR}"
}

prepare_project_directory
verify_laravel_project
record_install_metadata

cd "$APP_DIR"

# ─── .env ────────────────────────────────────────────────────────────────────
if [ "$IS_EXISTING" = "true" ]; then
    # Re-run: the existing .env is authoritative. It is NOT rewritten — APP_KEY,
    # DB_PASSWORD and every custom variable (Redis, mail, payment, Telegram,
    # panel, storage, queue, …) are preserved exactly as configured.
    log "‏.env موجود حفظ شد (بدون بازنویسی)."
    chmod 600 .env 2>/dev/null || true
    ok "تنظیمات فعلی .env دست‌نخورده باقی ماند."
else
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
fi

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

# ─── Lock-file enforcement ────────────────────────────────────────────────────
# Production installs are reproducible: both lock files MUST be present and the
# committed composer.lock MUST match composer.json. Never composer update /
# npm install here.
_missing_lock="$(zsc_require_lockfiles "$APP_DIR" || true)"
if [ -n "$_missing_lock" ]; then
    error "فایل قفل وابستگی‌ها یافت نشد: ${_missing_lock}. نصب تولیدی به composer.lock و package-lock.json نیاز دارد."
fi
ok "فایل‌های قفل موجودند (composer.lock و package-lock.json)."

# ─── Composer install ────────────────────────────────────────────────────────
log "Validating composer.json / composer.lock..."
composer validate --strict --no-check-publish --no-interaction \
    || error "composer validate ناموفق بود — composer.lock با composer.json هماهنگ نیست. نصب متوقف شد."

log "Installing PHP dependencies..."
# Run without --quiet so that any failure prints the real Composer error.
# COMPOSER_ALLOW_SUPERUSER is exported at the top of this script.
# No --ignore-platform-reqs, no update: the locked, verified graph is installed.
composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    || error "Composer install failed — see the output above for the exact error."

ok "Composer dependencies installed"

# ─── Node / build ────────────────────────────────────────────────────────────
# npm ci enforces package-lock.json and fails on any mismatch. There is NO
# fallback to `npm install` — a lock mismatch is a hard failure by design.
log "Installing Node.js dependencies (npm ci — locked)..."
npm ci || error "npm ci ناموفق بود (عدم تطابق package-lock.json یا خطای یکپارچگی). نصب متوقف شد؛ هیچ fallback به npm install انجام نمی‌شود."

log "Building frontend assets..."
npm run build || error "npm run build failed — see the output above."

ok "Frontend assets built"

# ─── Dependency vulnerability audit ───────────────────────────────────────────
run_supply_chain_audit

# ─── Laravel setup ───────────────────────────────────────────────────────────
if [ "$IS_EXISTING" = "true" ]; then
    # Preserve APP_KEY. Generate one ONLY when it is genuinely empty, and never
    # with --force (which would rotate a live key and make every encrypted
    # value undecryptable). A new key is written directly via --show.
    if zp_appkey_present_and_valid "${APP_DIR}/.env"; then
        ok "APP_KEY موجود معتبر است و بدون تغییر حفظ شد."
    elif [ -z "$(zp_env_get "${APP_DIR}/.env" APP_KEY || true)" ]; then
        warn "APP_KEY خالی است — کلید جدید تولید می‌شود (بدون --force)."
        NEW_KEY=$(php artisan key:generate --show)
        _esc=$(printf '%s' "$NEW_KEY" | sed -e 's/[&|\\]/\\&/g')
        if grep -qE '^APP_KEY=' .env; then
            sed -i "s|^APP_KEY=.*|APP_KEY=${_esc}|" .env
        else
            printf 'APP_KEY=%s\n' "$NEW_KEY" >> .env
        fi
        ok "APP_KEY جدید تنظیم شد."
    else
        # Non-empty but not the expected base64 shape — never rotate on a re-run.
        warn "APP_KEY در قالب base64 استاندارد نیست؛ بدون تغییر باقی ماند."
    fi
else
    log "Generating application key..."
    php artisan key:generate --force
fi

# ─── Database migrations ─────────────────────────────────────────────────────
log "Running database migrations..."
if [ "$IS_EXISTING" = "true" ]; then
    # A successful database backup MUST exist before migrations run on a live DB.
    log "تهیهٔ نسخهٔ پشتیبان دیتابیس پیش از مهاجرت‌ها..."
    mkdir -p "${REINSTALL_BACKUP_ROOT}/${REINSTALL_TS}"
    chmod 700 "$REINSTALL_BACKUP_ROOT" "${REINSTALL_BACKUP_ROOT}/${REINSTALL_TS}" 2>/dev/null || true
    DB_BACKUP_PATH="${REINSTALL_BACKUP_ROOT}/${REINSTALL_TS}/${DB_NAME}_${REINSTALL_TS}.dump"
    if PGPASSWORD="$DB_PASS" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Fc -f "$DB_BACKUP_PATH" 2>/dev/null; then
        chmod 600 "$DB_BACKUP_PATH"
        ok "نسخهٔ پشتیبان دیتابیس: ${DB_BACKUP_PATH}"
    else
        DB_BACKUP_PATH=""
        fail_reinstall "تهیهٔ نسخهٔ پشتیبان دیتابیس ناموفق بود. برای محافظت از داده، بروزرسانی متوقف شد."
    fi
    php artisan migrate --force || fail_reinstall "اجرای مهاجرت‌ها ناموفق بود. تنظیمات و کد قبلی بازیابی شد."
    ok "مهاجرت‌ها با موفقیت اجرا شدند."
else
    php artisan migrate --force || error "Migration failed. Check database credentials."
fi

# ─── Required default records ────────────────────────────────────────────────
# terms/privacy/about CMS pages (the 301 alias destinations) and the
# login/register noindex SEO records. Runs EXACTLY two firstOrCreate seeders —
# idempotent, never overwrites administrator edits, never seeds demo data.
log "Ensuring required default records (CMS pages + SEO registry)..."
if [ "$IS_EXISTING" = "true" ]; then
    php artisan zedproxy:seed-required-defaults --no-interaction \
        || fail_reinstall "ایجاد رکوردهای پیش‌فرض ضروری ناموفق بود. تنظیمات و کد قبلی بازیابی شد."
else
    php artisan zedproxy:seed-required-defaults --no-interaction \
        || error "Required default records could not be created. Check database credentials."
fi
ok "رکوردهای پیش‌فرض ضروری آماده‌اند."

# ─── Admin user ───────────────────────────────────────────────────────────────
if [ "$IS_EXISTING" = "true" ]; then
    # Re-run: never auto-create a second admin and never reset the existing
    # password. Only touch the admin when the operator explicitly opted in.
    if [ "$RESET_ADMIN_PASSWORD" = "true" ]; then
        # A new password will be shown → make sure we can deliver it securely
        # BEFORE we change anything.
        ensure_secret_delivery
        log "بازنشانی رمز عبور مدیر بنا به درخواست..."
        # Password is passed via env var (never argv → never visible in ps).
        ZEDPROXY_ADMIN_PASS="$ADMIN_PASS" php artisan zedproxy:create-admin \
            --email="$ADMIN_EMAIL" \
            --username="$ADMIN_NAME" \
            || fail_reinstall "بازنشانی رمز عبور مدیر ناموفق بود."
        ok "رمز عبور مدیر بازنشانی شد: ${ADMIN_NAME} <${ADMIN_EMAIL}> (رمز فقط از مسیر امن نمایش داده می‌شود)"
    else
        ok "رمز عبور مدیر فعلی بدون تغییر حفظ شد."
    fi
else
    # Fresh install always delivers a generated/entered password → require a
    # secure channel (TTY or ZP_CREDENTIAL_OUTPUT) or abort before creating it.
    ensure_secret_delivery
    log "Creating admin user (username: ${ADMIN_NAME}, email: ${ADMIN_EMAIL})..."
    ZEDPROXY_ADMIN_PASS="$ADMIN_PASS" php artisan zedproxy:create-admin \
        --email="$ADMIN_EMAIL" \
        --username="$ADMIN_NAME" \
        || error "Failed to create admin user. Check: tail -f ${APP_DIR}/storage/logs/laravel.log"
    ok "Admin user ready: ${ADMIN_NAME} <${ADMIN_EMAIL}>"
fi

log "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

ok "Laravel optimized"

# ─── Encrypted-secret validation + exit maintenance (re-run only) ─────────────
if [ "$IS_EXISTING" = "true" ]; then
    log "بررسی رمزگشایی اطلاعات حساس با APP_KEY فعلی..."
    if php artisan zedproxy:verify-encryption; then
        ok "اعتبارسنجی رمزگشایی اطلاعات حساس با موفقیت انجام شد."
    else
        # Encryption is broken (bad/rotated key) — do NOT report success.
        fail_reinstall "خطا در رمزگشایی اطلاعات حساس. APP_KEY یا اطلاعات رمزگذاری‌شده معتبر نیستند. عملیات متوقف و تنظیمات قبلی بازیابی شد."
    fi

    # Update succeeded — bring the site back online.
    php artisan up >/dev/null 2>&1 || true
    MAINT_MODE_ON=false
    ok "برنامه از حالت تعمیر و نگهداری خارج شد."
fi

# ─── Permissions ─────────────────────────────────────────────────────────────
log "Setting file permissions..."
chown -R www-data:www-data "$APP_DIR"
# `find` does not follow the symlinked .env/storage, so shared state is handled
# separately below.
find "$APP_DIR" -type f -exec chmod 644 {} \;
find "$APP_DIR" -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
chmod 600 .env
chmod +x scripts/backup.sh

# Shared state (owned by www-data, .env root-readable only) — applies to every
# release through the symlinks.
if [ -d "$ZPD_SHARED" ]; then
    chown -R www-data:www-data "$ZPD_SHARED" 2>/dev/null || true
    chmod -R 775 "${ZPD_SHARED}/storage" 2>/dev/null || true
    chmod 600 "${ZPD_SHARED}/.env" 2>/dev/null || true
fi
[ -L "$ZPD_CURRENT" ] && chown -h www-data:www-data "$ZPD_CURRENT" 2>/dev/null || true

ok "Permissions set"

# ─── Activate the initial release (fresh install only) ───────────────────────
# Point current → releases/<id> BEFORE configuring Nginx/Supervisor/scheduler so
# every service targets the active release from the first install.
if [ "$INSTALL_LAYOUT" = "fresh" ]; then
    activate_initial_release
    [ -L "$ZPD_CURRENT" ] && chown -h www-data:www-data "$ZPD_CURRENT" 2>/dev/null || true
fi

# ─── Nginx configuration ─────────────────────────────────────────────────────
log "Configuring Nginx for domain: ${DOMAIN}..."

# Only write a clean HTTP-only Nginx config if no SSL-managed config already exists.
# Certbot may have already added HTTPS blocks on a previous run — preserve them.
NGINX_HAS_SSL=false
if [ -f "$NGINX_CONF" ] && grep -q "ssl_certificate" "$NGINX_CONF" 2>/dev/null; then
    NGINX_HAS_SSL=true
    warn "Existing Nginx config at ${NGINX_CONF} already has SSL blocks — preserving certbot-managed config."
    warn "Only updating PHP-FPM socket path if needed."
    # Update socket path in case PHP version changed
    sed -i "s|fastcgi_pass unix:/run/php/php[0-9.]*-fpm-zedproxy.sock|fastcgi_pass unix:${PHP_FPM_SOCK}|g" "$NGINX_CONF"
else
    cat > "$NGINX_CONF" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    root ${ACTIVE_APP_DIR}/public;
    index index.php;

    # ZPD-GZIP-BEGIN (managed by ZedProxy deploy)
    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/css text/plain text/xml application/javascript application/json application/ld+json application/xml application/rss+xml image/svg+xml application/manifest+json;
    # ZPD-GZIP-END

    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    # robots.txt is served DYNAMICALLY by Laravel (RobotsController): keep the
    # quiet logging but fall through to the front controller. error_page 404
    # would NOT rescue a missing static file here (it preserves the 404 status,
    # which crawlers treat as "no robots.txt").
    location = /robots.txt {
        access_log off;
        log_not_found off;
        try_files \$uri /index.php?\$query_string;
    }

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

    # Livewire routes (livewire.js, /update, /upload-file, etc.) must reach PHP.
    # Without this, the \.js$ regex below would intercept livewire.js as a static
    # file, return 404, and Livewire's JS would never load — causing wire:submit to
    # not bind and the login form to fall back to a native POST → 405.
    location ^~ /livewire/ {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    client_max_body_size 20M;
}

# ZPD-WWW-REDIRECT-BEGIN (managed by ZedProxy deploy — canonical host routing)
server {
    listen 80;
    server_name www.${DOMAIN};
    # HTTP-01 renewals for www must keep working: serve ACME challenges
    # from the app webroot BEFORE the catch-all redirect.
    location ^~ /.well-known/acme-challenge/ {
        root ${ACTIVE_APP_DIR}/public;
    }
    location / {
        return 301 https://${DOMAIN}\$request_uri;
    }
}
# ZPD-WWW-REDIRECT-END
NGINX
fi

ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/zedproxy
rm -f /etc/nginx/sites-enabled/default
nginx -t || error "Nginx config test failed"
systemctl enable nginx
systemctl restart nginx
sleep 1

if ! systemctl is-active --quiet nginx; then
    journalctl -u nginx --no-pager -n 20 >&2 || true
    error "Nginx failed to start. See the output above for details."
fi

ok "Nginx configured for: ${DOMAIN}"

# ─── Internal loopback health virtual host (127.0.0.1:18080) ─────────────────
# Deployment health is validated against this LOOPBACK-only vhost so the updater
# never depends on Cloudflare, public DNS, public TLS, or the public vhost. It
# serves current/public through index.php and is never bound to a public
# interface. An intentional custom ZP_HEALTH_URL is preserved further below.
log "Configuring internal loopback health vhost..."
LOCAL_HEALTH_CONF="$(zpd_local_health_conf_path)"
zpd_local_health_conf_content "${ZPD_BASE}" "${PHP_FPM_SOCK}" > "$LOCAL_HEALTH_CONF"
if nginx -t 2>/dev/null; then
    systemctl reload nginx 2>/dev/null || systemctl restart nginx
    ok "Local health vhost active on 127.0.0.1:$(zpd_local_health_port) (loopback only)"
else
    warn "Local health vhost failed nginx -t; removing it (deployment health may fall back to the public host)."
    rm -f "$LOCAL_HEALTH_CONF"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx 2>/dev/null || true
fi

# ─── Queue worker (Supervisor) ───────────────────────────────────────────────
log "Configuring queue worker..."
cat > /etc/supervisor/conf.d/zedproxy-worker.conf <<SUPERVISOR
[program:zedproxy-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${ACTIVE_APP_DIR}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=${ACTIVE_APP_DIR}/storage/logs/worker.log
stopwaitsecs=3600
SUPERVISOR

systemctl enable supervisor
systemctl start supervisor
supervisorctl reread
supervisorctl update
ok "Queue workers configured"

# ─── Persistent deployment configuration (/etc/zedproxy/deploy.env) ───────────
# Non-secret configuration loaded by every update/rollback/deploy-status
# entrypoint. NEVER contains passwords, tokens, APP_KEY, DB credentials, or an
# authenticated repository URL. Existing custom values are preserved on re-run.
log "Writing deployment configuration (${DEPLOY_ENV_FILE})..."
zpd_load_deploy_env   # load any existing non-secret custom values (no override)
zpd_write_deploy_env "$DEPLOY_ENV_FILE" \
    "ZPD_BASE=${ZPD_BASE}" \
    "ZPD_REPO_URL=${ZPD_REPO_URL:-$REPO_URL}" \
    "ZPD_REF=${ZPD_REF:-${INSTALL_REF:-$BRANCH}}" \
    "ZPD_HEALTH_URL=${ZPD_HEALTH_URL:-$(zpd_local_health_url)}" \
    || warn "Could not write ${DEPLOY_ENV_FILE}"
ok "Deployment configuration written (repo/ref/base/health — no secrets)"

# ─── Deployment command shortcuts (stable bootstrap wrappers) ─────────────────
# The shortcuts resolve their target script at run time from a small bootstrap
# helper: they load /etc/zedproxy/deploy.env, prefer the ACTIVE release
# (current/), fall back to a detected install, and never depend on the caller's
# working directory. `zedproxy-update` runs the SELF-BOOTSTRAPPING update.sh,
# which fetches fresh deploy logic rather than blindly executing an on-disk copy.
log "Installing deployment command shortcuts..."

# Single shared implementation (identical to the one deploy.sh installs during
# every update, so a legacy server that migrates via update.sh also gets it).
zpw_install_wrappers || error "Installing command wrappers failed."

ok "Shortcuts installed: zedproxy-update, zedproxy-rollback, zedproxy-deploy-status, zedproxy-sanitize-install-log"

# ─── Laravel scheduler cron (the ONE supported scheduling method) ─────────────
# A single every-minute `schedule:run` drives every scheduled task defined in
# routes/console.php (backups, Telegram reports, panel health, Marzban sync, and
# the scheduler heartbeat). Writing a dedicated /etc/cron.d file (fully replaced
# each run) is idempotent — re-running the installer never duplicates entries.
log "Installing Laravel scheduler cron..."
SCHED_USER="www-data"
SCHED_LOG="/var/log/zedproxy-scheduler.log"
SCHED_CRON_FILE="/etc/cron.d/zedproxy-scheduler"
SCHED_LINE="$(zp_scheduler_cron_line "${ACTIVE_APP_DIR}" "${SCHED_USER}" "php" "${SCHED_LOG}")"
zp_write_cron_file "${SCHED_CRON_FILE}" "${SCHED_LINE}"
ok "Scheduler cron installed (runs every minute → ${SCHED_LOG})"

# Prepare the scheduler log with correct ownership/permissions so www-data can
# write to it (and log rotation can manage it).
touch "${SCHED_LOG}"
chown "${SCHED_USER}:${SCHED_USER}" "${SCHED_LOG}" 2>/dev/null || true
chmod 0640 "${SCHED_LOG}" 2>/dev/null || true

# Log rotation for the scheduler log.
cat > /etc/logrotate.d/zedproxy-scheduler <<LOGROTATE
${SCHED_LOG} {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su ${SCHED_USER} ${SCHED_USER}
    create 0640 ${SCHED_USER} ${SCHED_USER}
}
LOGROTATE
ok "Log rotation configured for ${SCHED_LOG}"

# Log rotation for the installer log. It is root-only (mode 600) and must stay
# that way after rotation: rotated + compressed copies are created root:root 600
# so an old install log can never become world-readable. It never contains
# credentials (see the credential-safe logging design), but is kept private as
# defence in depth.
cat > /etc/logrotate.d/zedproxy-install <<'LOGROTATE'
/var/log/zedproxy-install.log {
    monthly
    rotate 6
    compress
    delaycompress
    missingok
    notifempty
    su root root
    create 0600 root root
}
LOGROTATE
chmod 600 "$LOG_FILE" 2>/dev/null || true
chown root:root "$LOG_FILE" 2>/dev/null || true
ok "Log rotation configured for ${LOG_FILE} (root-only, mode 600)"

# Retire the legacy backup cron — backups are now controlled solely by the
# Laravel scheduler (zedproxy:backup --scheduled), so they run from ONE system.
if zp_remove_file "/etc/cron.d/zedproxy-backup"; then
    ok "Removed legacy backup cron (backups now run via the Laravel scheduler)"
fi

# Verify the scheduler is wired up: list the registered tasks. The heartbeat
# will appear within a minute; check it with: php artisan zedproxy:scheduler-status
log "Verifying scheduler configuration..."
if sudo -u "${SCHED_USER}" php "${APP_DIR}/artisan" schedule:list >/dev/null 2>&1 \
    || php "${APP_DIR}/artisan" schedule:list >/dev/null 2>&1; then
    ok "Scheduler tasks registered (php artisan schedule:list)"
else
    warn "Could not list scheduler tasks — run: cd ${APP_DIR} && php artisan schedule:list"
fi

# ─── HTTP health check (must pass before SSL) ─────────────────────────────────
log "Running HTTP health check..."
sleep 2

# Depend only on the safe public fields: HTTP 200 + "status":"ok". The response
# never contains error messages, so it is safe to print.
HEALTH_BODY=$(curl -s --max-time 10 http://localhost/health 2>/dev/null || echo "")
HEALTH_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://localhost/health 2>/dev/null || echo "000")
HEALTH_OK=false

if [ "$HEALTH_RESPONSE" = "200" ] && echo "$HEALTH_BODY" | grep -q '"status":[[:space:]]*"ok"'; then
    ok "Health check PASSED (HTTP 200, status ok)"
    echo "$HEALTH_BODY"
    echo ""
    HEALTH_OK=true
else
    warn "Health check returned HTTP $HEALTH_RESPONSE"
    warn "The app may still be warming up. Run: curl http://localhost/health"
    warn "For details run: cd ${APP_DIR} && php artisan zedproxy:health"
fi

# ─── SSL / Let's Encrypt (only after HTTP health check passes) ────────────────
if [ "$HEALTH_OK" = "true" ] && [ "$INSTALL_SSL" = "true" ]; then
    echo ""
    setup_ssl
fi

# ─── Compute final effective URL ──────────────────────────────────────────────
# Always derive from SSL result — never from user input prompt.
if [ "$SSL_ACTIVE" = "true" ]; then
    APP_URL="https://${DOMAIN}"
else
    APP_URL="http://${DOMAIN}"
fi

# ─── Final summary ────────────────────────────────────────────────────────────
echo ""

if [ "$HEALTH_OK" = "true" ]; then
    INSTALL_SUCCESS=true
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    if [ "$IS_EXISTING" = "true" ]; then
        echo -e "${GREEN}  ZedProxy safe re-run completed successfully!${NC}"
    else
        echo -e "${GREEN}  ZedProxy installation completed successfully!${NC}"
    fi
    echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
    echo ""
    if [ "$IS_EXISTING" = "true" ]; then
        echo -e "  Mode:              ${YELLOW}safe re-run (existing installation)${NC}"
    else
        echo -e "  Mode:              ${YELLOW}fresh installation${NC}"
    fi
    echo -e "  OS:                ${BLUE}${OS_PRETTY}${NC}"
    echo -e "  PHP version:       ${BLUE}PHP ${PHP_VERSION}${NC}"

    # SSL status line
    if [ "$INSTALL_SSL" = "false" ]; then
        echo -e "  SSL:               ${BLUE}not installed (HTTP only)${NC}"
    elif [ "$SSL_ACTIVE" = "true" ] && [ "$SSL_STAGING" = "true" ]; then
        echo -e "  SSL:               ${YELLOW}STAGING — test cert (not browser-trusted)${NC}"
    elif [ "$SSL_ACTIVE" = "true" ]; then
        echo -e "  SSL:               ${GREEN}active (Let's Encrypt)${NC}"
    elif [ "$SSL_FAIL_REASON" = "rate_limit" ]; then
        echo -e "  SSL:               ${RED}FAILED — Let's Encrypt rate limit${NC}"
    elif [ "$SSL_FAIL_REASON" = "dns" ]; then
        echo -e "  SSL:               ${RED}FAILED — DNS did not resolve${NC}"
    else
        echo -e "  SSL:               ${RED}FAILED — see warnings above${NC}"
    fi

    echo -e "  Website URL:       ${BLUE}${APP_URL}${NC}"
    echo -e "  Admin panel URL:   ${BLUE}${APP_URL}/zed-admin${NC}"
    echo -e "  Health check URL:  ${BLUE}${APP_URL}/health${NC}"
    echo ""
    if [ "$IS_EXISTING" = "true" ]; then
        # Re-run: preserve secrets. Never re-print APP_KEY / DB / admin passwords.
        echo -e "  Admin login URL:   ${YELLOW}${APP_URL}/zed-admin/login${NC}"
        echo -e "  APP_KEY:           ${GREEN}preserved (unchanged)${NC}"
        echo -e "  Database:          ${GREEN}preserved (password unchanged)${NC}"
        echo -e "  Encrypted secrets: ${GREEN}validated — decrypt OK${NC}"
        if [ "$RESET_ADMIN_PASSWORD" = "true" ]; then
            echo ""
            echo -e "  Admin username:    ${YELLOW}${ADMIN_NAME}${NC}"
            echo -e "  Admin password:    ${GREEN}تنظیم شد — فقط از مسیر امن نمایش داده شد${NC}"
            # Deliver the NEW password once, off-log.
            log_secret_once ""
            log_secret_once "════════════════════════════════════════════"
            log_secret_once "رمز مدیر فقط یک بار نمایش داده می‌شود و در فایل لاگ ذخیره نخواهد شد."
            log_secret_once "  Admin username: ${ADMIN_NAME}"
            log_secret_once "  Admin password: ${ADMIN_PASS}"
            log_secret_once "════════════════════════════════════════════"
        else
            echo -e "  Admin account:     ${GREEN}رمز عبور مدیر فعلی بدون تغییر حفظ شد.${NC}"
        fi
        echo ""
        echo -e "  Backups (this run):"
        [ -n "$ENV_BACKUP_PATH" ] && echo -e "    .env:            ${BLUE}${ENV_BACKUP_PATH}${NC}"
        [ -n "$DB_BACKUP_PATH" ]  && echo -e "    database:        ${BLUE}${DB_BACKUP_PATH}${NC}"
        [ -n "$PRE_UPDATE_COMMIT" ] && echo -e "    previous commit: ${BLUE}${PRE_UPDATE_COMMIT}${NC}"
        echo ""
        echo -e "  Installed commit:  ${BLUE}$(zsc_git_resolve "$APP_DIR" HEAD 2>/dev/null || echo unknown)${NC}"
        echo -e "  Install log:       ${BLUE}${LOG_FILE}${NC}"
    else
        echo -e "  Admin login URL:   ${YELLOW}${APP_URL}/zed-admin/login${NC}"
        echo -e "  Admin username:    ${YELLOW}${ADMIN_NAME}${NC}"
        echo -e "  Admin email:       ${YELLOW}${ADMIN_EMAIL}${NC}"
        echo -e "  Admin password:    ${GREEN}فقط از مسیر امن نمایش داده شد (در لاگ ذخیره نشد)${NC}"
        echo ""
        echo -e "  DB name:           ${YELLOW}${DB_NAME}${NC}"
        echo -e "  DB user:           ${YELLOW}${DB_USER}${NC}"
        echo -e "  DB password:       ${GREEN}اطلاعات اتصال دیتابیس به‌صورت امن در فایل .env ذخیره شد.${NC}"
        echo -e "                     ${GREEN}رمز دیتابیس در خروجی و لاگ نمایش داده نمی‌شود.${NC}"
        echo ""
        echo -e "  Installed commit:  ${BLUE}$(zsc_git_resolve "$APP_DIR" HEAD 2>/dev/null || echo unknown)${NC}"
        echo -e "  Install log:       ${BLUE}${LOG_FILE}${NC}"
        # ── Deliver the admin password exactly once, OFF the logging pipeline. ──
        log_secret_once ""
        log_secret_once "════════════════════════════════════════════"
        log_secret_once "رمز مدیر فقط یک بار نمایش داده می‌شود و در فایل لاگ ذخیره نخواهد شد."
        log_secret_once "  Admin login: ${APP_URL}/zed-admin/login"
        log_secret_once "  Admin username: ${ADMIN_NAME}"
        log_secret_once "  Admin email:    ${ADMIN_EMAIL}"
        log_secret_once "  Admin password: ${ADMIN_PASS}"
        log_secret_once "════════════════════════════════════════════"
        if [ "$SECRET_DELIVERY" = "file" ]; then
            echo ""
            echo -e "  ${YELLOW}نصب به‌صورت غیرتعاملی اجرا شده است.${NC}"
            echo -e "  ${YELLOW}اطلاعات ورود فقط در فایل امن مشخص‌شده ذخیره شد: ${CREDENTIAL_FILE}${NC}"
            echo -e "  ${YELLOW}پس از ذخیره اطلاعات، فایل را حذف کنید.${NC}"
        fi
        echo ""
        echo -e "  ${GREEN}اطلاعات ورود مدیر فقط از مسیر امن نمایش داده شد و در فایل لاگ ذخیره نشد.${NC}"
    fi
    # Clear secret shell variables now that delivery is complete.
    ADMIN_PASS=""; DB_PASS=""; unset ADMIN_PASS DB_PASS 2>/dev/null || true
    echo ""
    echo -e "  To update ZedProxy in the future:"
    echo -e "    ${GREEN}zedproxy-update${NC}  (shortcut installed at /usr/local/bin/zedproxy-update)"
    echo -e "    or: ${BLUE}curl -fsSL https://raw.githubusercontent.com/mhoseinshah1/zed_web/main/update.sh -o /tmp/zedproxy-update.sh && chmod +x /tmp/zedproxy-update.sh && sudo bash /tmp/zedproxy-update.sh${NC}"
    echo ""
    if [ "$INSTALL_SSL" = "true" ] && [ "$SSL_ACTIVE" = "false" ]; then
        echo -e "  ${YELLOW}To install SSL manually after the issue is resolved:${NC}"
        echo -e "    ${BLUE}certbot --nginx -d ${DOMAIN} -m ${ADMIN_EMAIL} --non-interactive --agree-tos --redirect --no-eff-email${NC}"
        echo ""
    fi
else
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}  ZedProxy installation did not complete cleanly.${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  Health check failed (HTTP ${HEALTH_RESPONSE})."
    echo -e "  Admin credentials are NOT shown in a failed state."
    echo ""
    echo -e "  Investigate:"
    echo -e "    ${BLUE}sudo tail -n 120 ${LOG_FILE}${NC}"
    echo -e "    ${BLUE}tail -50 ${APP_DIR}/storage/logs/laravel.log${NC}"
    echo -e "    ${BLUE}curl http://localhost/health${NC}"
    echo -e "    ${BLUE}sudo systemctl status nginx ${PHP_FPM_SERVICE} postgresql redis-server${NC}"
    echo ""
fi
