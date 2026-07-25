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
# Dump to a UNIQUE temporary path (PID-scoped) and atomically publish a
# collision-free final name only after pg_dump succeeds — a failed/timed-out
# run can only ever delete its OWN temporary file, never an earlier completed
# backup from the same minute, and concurrent invocations cannot clobber each
# other's output.
TMPFILE="$BACKUP_DIR/.${FILENAME}.$$.tmp"
if ! PGPASSWORD="$DB_PASSWORD" timeout -k 10 "${ZPD_BACKUP_TIMEOUT:-3600}s" pg_dump \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USERNAME" \
    -Fc \
    -f "$TMPFILE" \
    "$DB_DATABASE" </dev/null; then
    # Only THIS invocation's incomplete temporary dump is removed.
    rm -f "$TMPFILE"
    echo "[ERROR] pg_dump failed or timed out — incomplete dump removed"
    exit 1
fi
FINAL="$BACKUP_DIR/$FILENAME"
n=0
while [ -e "$FINAL" ]; do
    n=$((n + 1))
    FINAL="$BACKUP_DIR/zedproxy_${TIMESTAMP}_${n}.dump"
done
mv "$TMPFILE" "$FINAL"

echo "[$(date)] Backup complete: $FINAL"
ls -lh "$FINAL"

# Delete backups older than 30 days
find "$BACKUP_DIR" -name "zedproxy_*.dump" -mtime +30 -delete
echo "[$(date)] Old backups cleaned up (>30 days)"
