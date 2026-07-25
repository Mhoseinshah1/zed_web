#!/usr/bin/env bash
# ZedProxy PostgreSQL backup script
# Usage: bash scripts/backup.sh
# Cron example: 0 3 * * * /var/www/zedproxy/scripts/backup.sh >> /var/log/zedproxy-backup.log 2>&1

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="$APP_DIR/storage/app/backups"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M")
FILENAME="zedproxy_${TIMESTAMP}.dump"

# Load .env
if [ -f "$APP_DIR/.env" ]; then
    set -o allexport
    # shellcheck source=/dev/null
    source <(grep -E '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD|DB_HOST|DB_PORT)=' "$APP_DIR/.env")
    set +o allexport
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-zedproxy}"
DB_USERNAME="${DB_USERNAME:-zedproxy_user}"

if [ -z "${DB_PASSWORD:-}" ]; then
    echo "[ERROR] DB_PASSWORD is not set in .env"
    exit 1
fi

mkdir -p "$BACKUP_DIR"

echo "[$(date)] Starting backup: $FILENAME"

# Bounded: a wedged database connection must not leave the backup job hanging
# forever (a real dump of this DB takes minutes, not hours — override with
# ZPD_BACKUP_TIMEOUT for very large databases).
# PGPASSWORD travels via the environment (prefix assignment), never as an argv
# word under timeout/env where /proc/*/cmdline would expose it while the dump runs.
if ! PGPASSWORD="$DB_PASSWORD" timeout "${ZPD_BACKUP_TIMEOUT:-3600}s" pg_dump \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USERNAME" \
    -Fc \
    -f "$BACKUP_DIR/$FILENAME" \
    "$DB_DATABASE" </dev/null; then
    # A killed/failed dump may have partially written the normally named file —
    # remove it so a truncated dump can never be mistaken for a completed
    # backup (and later selected for restoration).
    rm -f "$BACKUP_DIR/$FILENAME"
    echo "[ERROR] pg_dump failed or timed out — incomplete dump removed"
    exit 1
fi

echo "[$(date)] Backup complete: $BACKUP_DIR/$FILENAME"
ls -lh "$BACKUP_DIR/$FILENAME"

# Delete backups older than 30 days
find "$BACKUP_DIR" -name "zedproxy_*.dump" -mtime +30 -delete
echo "[$(date)] Old backups cleaned up (>30 days)"
