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

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USERNAME" \
    -Fc \
    -f "$BACKUP_DIR/$FILENAME" \
    "$DB_DATABASE"

echo "[$(date)] Backup complete: $BACKUP_DIR/$FILENAME"
ls -lh "$BACKUP_DIR/$FILENAME"

# Delete backups older than 30 days
find "$BACKUP_DIR" -name "zedproxy_*.dump" -mtime +30 -delete
echo "[$(date)] Old backups cleaned up (>30 days)"
