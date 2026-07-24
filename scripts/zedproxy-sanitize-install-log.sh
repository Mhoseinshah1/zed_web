#!/usr/bin/env bash
# =============================================================================
# zedproxy-sanitize-install-log — scan / redact / truncate an existing ZedProxy
# install log that may contain plaintext credentials from an affected installer
# version (before the credential-safe-logging fix).
#
#   sudo zedproxy-sanitize-install-log --scan       # report categories only
#   sudo zedproxy-sanitize-install-log --redact     # replace secrets in place
#   sudo zedproxy-sanitize-install-log --truncate    # empty the file entirely
#
# Options:
#   --file PATH   target log (default: /var/log/zedproxy-install.log)
#   --backup      keep a root-only .bak before --redact/--truncate
#   -h|--help
#
# Guarantees: requires root; refuses symlinks; regular-file only; preserves
# owner + mode; NEVER prints a detected secret value; idempotent.
#
# LIMITATION: redaction/truncation rewrites this file only. It CANNOT guarantee
# secure erasure on SSD/COW filesystems, snapshots, or off-box backups — rotate
# the exposed credentials (see README "Credential rotation").
# =============================================================================
set -Eeuo pipefail
PS4='+ '

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
LOG_DEFAULT="/var/log/zedproxy-install.log"

_die() { printf "${RED}[ERROR] %s${NC}\n" "$*" >&2; exit 2; }

# Load the redaction/scan helpers (local checkout preferred, else download).
_dir="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
for _cand in "${_dir}/lib/installer-lib.sh" "${_dir}/../scripts/lib/installer-lib.sh" "/var/www/zedproxy/scripts/lib/installer-lib.sh"; do
    if [ -f "$_cand" ]; then # shellcheck disable=SC1090
        source "$_cand"; break
    fi
done
declare -F zp_redact >/dev/null 2>&1 || _die "installer-lib.sh (zp_redact) not found."

MODE=""; FILE="$LOG_DEFAULT"; BACKUP=0
while [ $# -gt 0 ]; do
    case "$1" in
        --scan)     MODE="scan" ;;
        --redact)   MODE="redact" ;;
        --truncate) MODE="truncate" ;;
        --backup)   BACKUP=1 ;;
        --file)     shift; FILE="${1:-}" ;;
        -h|--help)  sed -n '2,26p' "$0"; exit 0 ;;
        *) _die "Unknown argument: $1" ;;
    esac
    shift
done
[ -n "$MODE" ] || _die "Choose one of --scan | --redact | --truncate."

# Safety gates.
[ "$(id -u)" = "0" ] || _die "این دستور باید با دسترسی root اجرا شود."
case "$FILE" in /*) ;; *) _die "مسیر باید مطلق باشد: $FILE" ;; esac
[ -e "$FILE" ] || _die "فایل یافت نشد: $FILE"
[ -L "$FILE" ] && _die "فایل یک symlink است؛ به‌دلایل امنیتی رد شد: $FILE"
[ -f "$FILE" ] || _die "فایل عادی نیست: $FILE"

# scan — categories only, never values.
if [ "$MODE" = "scan" ]; then
    if cats="$(zp_scan_secrets_in_file "$FILE")"; then
        printf "${YELLOW}اطلاعات حساس احتمالی در فایل لاگ شناسایی شد.${NC}\n"
        printf '  - %s\n' $cats
        exit 1
    fi
    printf "${GREEN}هیچ اطلاعات حساس شناخته‌شده‌ای یافت نشد.${NC}\n"
    exit 0
fi

# Preserve owner + mode across the rewrite.
owner="$(stat -c '%u:%g' "$FILE" 2>/dev/null || echo 0:0)"
mode="$(stat -c '%a' "$FILE" 2>/dev/null || echo 600)"

if [ "$BACKUP" = "1" ]; then
    bak="${FILE}.$(date -u +%Y%m%d%H%M%S).bak"
    ( umask 077; cp -p "$FILE" "$bak" ) || _die "تهیهٔ نسخهٔ پشتیبان ناموفق بود."
    chmod 600 "$bak" 2>/dev/null || true
    printf "${BLUE}نسخهٔ پشتیبان (فقط root): %s${NC}\n" "$bak"
fi

if [ "$MODE" = "truncate" ]; then
    : > "$FILE"
    chmod "$mode" "$FILE" 2>/dev/null || true
    chown "$owner" "$FILE" 2>/dev/null || true
    printf "${GREEN}فایل لاگ نصب با موفقیت پاک شد.${NC}\n"
    exit 0
fi

# redact — rewrite through zp_redact atomically (same dir, private temp).
tmp="$(cd "$(dirname "$FILE")" && mktemp ".zpsan.XXXXXX")" || _die "mktemp ناموفق بود."
tmp="$(dirname "$FILE")/$tmp"
# shellcheck disable=SC2064
trap "rm -f '$tmp'" EXIT
( umask 077; zp_redact < "$FILE" > "$tmp" ) || _die "بازنویسی ایمن ناموفق بود."
chmod "$mode" "$tmp" 2>/dev/null || true
chown "$owner" "$tmp" 2>/dev/null || true
mv -f "$tmp" "$FILE" || _die "جایگزینی فایل ناموفق بود."
trap - EXIT
printf "${GREEN}اطلاعات حساس بدون نمایش مقدار از فایل لاگ حذف شد.${NC}\n"
printf "${YELLOW}توجه: پاک‌سازی فقط این فایل را بازنویسی می‌کند و حذف امن روی SSD/snapshot/بکاپ تضمین نمی‌شود. رمزها را چرخش دهید.${NC}\n"
exit 0
