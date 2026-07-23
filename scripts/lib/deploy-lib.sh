#!/usr/bin/env bash
# =============================================================================
# ZedProxy atomic release-deployment helper library.
#
# Pure, side-effect-free helper functions shared by deploy.sh / rollback.sh /
# deploy-status.sh and covered by tests/deploy/run-tests.sh. Sourcing this file
# MUST NOT run any deployment step, print anything, or mutate global state beyond
# defining functions and the load sentinel — so it is safe to source from both
# the deployment scripts and the test runner.
#
# All function names are prefixed with `zpd_` (ZedProxy Deploy).
#
# Filesystem layout (paths overridable via env for tests/back-compat):
#   $ZPD_BASE/                 (default /var/www/zedproxy)
#     current -> releases/<release-id>   (atomic symlink; Nginx root)
#     releases/<release-id>/...
#     shared/.env
#     shared/storage/
#     shared/persistent/
#   Lock:   $ZPD_LOCK_FILE     (default /var/run/zedproxy-deploy.lock — NOT in a release)
#   Logs:   $ZPD_LOG_DIR       (default /var/log/zedproxy — outside releases)
#   State:  $ZPD_STATE_FILE    (default $ZPD_BASE/shared/deploy/state.json)
#   Backups:$ZPD_BACKUP_DIR    (default /var/backups/zedproxy/deploys)
# =============================================================================

if [ -n "${ZPD_DEPLOY_LIB_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
ZPD_DEPLOY_LIB_LOADED=1

# ── Path resolution ─────────────────────────────────────────────────────────

zpd_base()        { printf '%s' "${ZPD_BASE:-/var/www/zedproxy}"; }
zpd_releases_dir(){ printf '%s/releases' "$(zpd_base)"; }
zpd_shared_dir()  { printf '%s/shared' "$(zpd_base)"; }
zpd_current_link(){ printf '%s/current' "$(zpd_base)"; }
zpd_lock_file()   { printf '%s' "${ZPD_LOCK_FILE:-/var/run/zedproxy-deploy.lock}"; }
zpd_log_dir()     { printf '%s' "${ZPD_LOG_DIR:-/var/log/zedproxy}"; }
zpd_state_file()  { printf '%s' "${ZPD_STATE_FILE:-$(zpd_shared_dir)/deploy/state.json}"; }
zpd_backup_dir()  { printf '%s' "${ZPD_BACKUP_DIR:-/var/backups/zedproxy/deploys}"; }

# -----------------------------------------------------------------------------
# zpd_release_id [SHA] [EPOCH]
#
# Immutable release identifier: YYYYMMDDHHMMSS-<short-sha>. SHA/epoch may be
# passed for deterministic tests; otherwise the current git short SHA (or
# "nogit") and current time are used.
# -----------------------------------------------------------------------------
zpd_release_id() {
    local sha="${1:-}" epoch="${2:-}"
    if [ -z "$sha" ]; then
        sha="$(git rev-parse --short=12 HEAD 2>/dev/null || echo nogit)"
    fi
    # Keep only the first 12 hex chars; fall back to "nogit".
    sha="$(printf '%s' "$sha" | tr -cd '0-9a-fA-F' | cut -c1-12)"
    [ -n "$sha" ] || sha="nogit"
    local ts
    if [ -n "$epoch" ]; then
        ts="$(date -u -d "@${epoch}" +%Y%m%d%H%M%S 2>/dev/null || date -u +%Y%m%d%H%M%S)"
    else
        ts="$(date -u +%Y%m%d%H%M%S)"
    fi
    printf '%s-%s' "$ts" "$sha"
}

# -----------------------------------------------------------------------------
# zpd_valid_release_id ID — return 0 for a well-formed release id.
# -----------------------------------------------------------------------------
zpd_valid_release_id() {
    printf '%s' "${1:-}" | grep -Eq '^[0-9]{14}-[0-9a-fA-F]{1,12}$'
}

# ── Secret masking ──────────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_mask_secrets — filter stdin→stdout, redacting credentials/secrets so logs
# and manifests never contain them.
# -----------------------------------------------------------------------------
zpd_mask_secrets() {
    sed -E \
        -e 's/(PGPASSWORD)=[^[:space:]]*/\1=***/g' \
        -e 's/(APP_KEY|DB_PASSWORD|REDIS_PASSWORD|MAIL_PASSWORD)=[^[:space:]]*/\1=***/gI' \
        -e 's/((password|passwd|pass|secret|token|api[_-]?key|auth)[[:space:]]*[=:][[:space:]]*)[^[:space:]"'"'"']+/\1***/gI' \
        -e 's#(://)[^/@[:space:]:]+:[^/@[:space:]]+@#\1***:***@#g'
}

# ── JSON manifest / state (no secrets) ──────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_json_escape STRING — minimal JSON string escaping.
# -----------------------------------------------------------------------------
zpd_json_escape() {
    local s="${1:-}"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\t'/\\t}"
    printf '%s' "$s"
}

# -----------------------------------------------------------------------------
# zpd_write_manifest FILE key=value...
#
# Write a flat JSON manifest to FILE (atomically, mode 600). Values are masked
# and JSON-escaped so no secret can leak into the manifest. Creates parent dirs.
# -----------------------------------------------------------------------------
zpd_write_manifest() {
    local file="$1"; shift
    [ -n "$file" ] || return 1
    mkdir -p "$(dirname "$file")" 2>/dev/null || return 1

    local tmp; tmp="$(mktemp)" || return 1
    {
        printf '{\n'
        local first=1 pair key val
        for pair in "$@"; do
            key="${pair%%=*}"
            val="${pair#*=}"
            val="$(printf '%s' "$val" | zpd_mask_secrets)"
            [ "$first" -eq 1 ] || printf ',\n'
            first=0
            printf '  "%s": "%s"' "$(zpd_json_escape "$key")" "$(zpd_json_escape "$val")"
        done
        printf '\n}\n'
    } > "$tmp" || { rm -f "$tmp"; return 1; }

    chmod 600 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$file" 2>/dev/null || { rm -f "$tmp"; return 1; }
}

# -----------------------------------------------------------------------------
# zpd_manifest_get FILE KEY — read a value from a flat JSON manifest. Prints the
# value (empty if absent). Returns 1 if FILE is missing.
# -----------------------------------------------------------------------------
zpd_manifest_get() {
    local file="$1" key="$2"
    [ -f "$file" ] || return 1
    # Match:  "key": "value"
    sed -nE "s/^[[:space:]]*\"$(printf '%s' "$key" | sed 's/[][\.*^$/]/\\&/g')\"[[:space:]]*:[[:space:]]*\"(.*)\"[[:space:]]*,?[[:space:]]*$/\1/p" "$file" | head -n1
}

# -----------------------------------------------------------------------------
# zpd_manifest_valid FILE — return 0 if FILE looks like a valid manifest that
# carries a non-empty release_id. Used to reject corrupt/partial manifests.
# -----------------------------------------------------------------------------
zpd_manifest_valid() {
    local file="$1"
    [ -f "$file" ] || return 1
    grep -q '^{' "$file" 2>/dev/null || return 1
    grep -q '}[[:space:]]*$' "$file" 2>/dev/null || return 1
    [ -n "$(zpd_manifest_get "$file" release_id)" ] || return 1
    return 0
}

# ── Release listing / current / previous ────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_current_release — basename of the release the `current` symlink points to
# (empty if none). Resolves one level only (the immediate target).
# -----------------------------------------------------------------------------
zpd_current_release() {
    local link; link="$(zpd_current_link)"
    [ -L "$link" ] || return 0
    local target; target="$(readlink "$link" 2>/dev/null)" || return 0
    basename "$target"
}

# -----------------------------------------------------------------------------
# zpd_list_releases — release ids newest-first (name sort; ids are time-prefixed).
# -----------------------------------------------------------------------------
zpd_list_releases() {
    local dir; dir="$(zpd_releases_dir)"
    [ -d "$dir" ] || return 0
    find "$dir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
        | grep -E '^[0-9]{14}-' | sort -r
}

# -----------------------------------------------------------------------------
# zpd_previous_release — the release immediately before `current` in the
# newest-first list (empty if none / current is oldest).
# -----------------------------------------------------------------------------
zpd_previous_release() {
    local current; current="$(zpd_current_release)"
    local found_current=0 rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        if [ "$found_current" -eq 1 ]; then
            printf '%s' "$rel"
            return 0
        fi
        [ "$rel" = "$current" ] && found_current=1
    done < <(zpd_list_releases)
    return 0
}

# ── Atomic symlink switch ───────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_switch_current RELEASE_ID
#
# Atomically point `current` at releases/RELEASE_ID via a temp symlink + rename
# (rename(2) is atomic), so a reader never sees a missing/half link. The release
# directory must exist. Returns 1 on any failure.
# -----------------------------------------------------------------------------
zpd_switch_current() {
    local id="$1"
    local base rel_dir link tmp
    base="$(zpd_base)"
    rel_dir="$(zpd_releases_dir)/${id}"
    link="$(zpd_current_link)"
    [ -d "$rel_dir" ] || return 1
    tmp="${link}.tmp.$$"

    # Relative target keeps the tree relocatable.
    ln -s "releases/${id}" "$tmp" 2>/dev/null || return 1
    # mv -T renames the symlink itself atomically over the existing `current`.
    if mv -Tf "$tmp" "$link" 2>/dev/null; then
        return 0
    fi
    rm -f "$tmp" 2>/dev/null || true
    return 1
}

# ── Disk space ──────────────────────────────────────────────────────────────

# zpd_disk_free_mb PATH — available MB on the filesystem holding PATH (0 on error).
zpd_disk_free_mb() {
    local path="${1:-/}"
    [ -e "$path" ] || path="$(dirname "$path")"
    df -Pm "$path" 2>/dev/null | awk 'NR==2 {print $4+0}' || printf '0'
}

# zpd_check_disk_space PATH MIN_MB — return 0 iff at least MIN_MB is free.
zpd_check_disk_space() {
    local path="$1" min_mb="$2" free
    free="$(zpd_disk_free_mb "$path")"
    [ "${free:-0}" -ge "${min_mb:-0}" ]
}

# ── Release retention ───────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_prunable_releases KEEP
#
# Print release ids that may be deleted: keep the newest KEEP releases, and
# NEVER the active or immediately-previous release. Input is the newest-first
# list; everything past position KEEP (and not active/previous) is prunable.
# -----------------------------------------------------------------------------
zpd_prunable_releases() {
    local keep="${1:-5}"
    local current previous
    current="$(zpd_current_release)"
    previous="$(zpd_previous_release)"

    local i=0 rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        i=$((i + 1))
        [ "$i" -le "$keep" ] && continue
        [ "$rel" = "$current" ] && continue
        [ "$rel" = "$previous" ] && continue
        printf '%s\n' "$rel"
    done < <(zpd_list_releases)
}

# ── Migration classification ────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_migration_is_destructive FILE
#
# Return 0 if a Laravel migration file contains potentially destructive schema
# operations (dropColumn/dropTable/drop/delete/truncate/dropIfExists). A
# backward-compatible migration (add columns/indexes only) returns 1.
# -----------------------------------------------------------------------------
zpd_migration_is_destructive() {
    local file="$1"
    [ -f "$file" ] || return 1
    grep -Eiq 'dropColumn|dropColumns|drop\(|dropIfExists|dropTable|->delete\(|truncate|renameColumn' "$file"
}

# -----------------------------------------------------------------------------
# zpd_migration_preflight DIR [DIR2...]
#
# Print a report line per *.php migration file: "<name> SAFE|DESTRUCTIVE".
# Returns 0 if all safe, 2 if any destructive (so a caller can require approval).
# -----------------------------------------------------------------------------
zpd_migration_preflight() {
    local rc=0 f name
    for dir in "$@"; do
        [ -d "$dir" ] || continue
        while IFS= read -r f; do
            name="$(basename "$f")"
            if zpd_migration_is_destructive "$f"; then
                printf '%s DESTRUCTIVE\n' "$name"
                rc=2
            else
                printf '%s SAFE\n' "$name"
            fi
        done < <(find "$dir" -maxdepth 1 -name '*.php' 2>/dev/null | sort)
    done
    return $rc
}

# ── Shared-storage compatibility migration ──────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_init_shared_from_existing OLD_DIR SHARED_DIR
#
# Migrate an existing single-directory install into shared storage WITHOUT
# destroying data:
#   - move OLD_DIR/.env → SHARED_DIR/.env (only if not already in shared)
#   - move OLD_DIR/storage → SHARED_DIR/storage (preserving storage/app/public)
# Existing files are moved (not deleted); if a shared copy already exists the old
# one is left untouched. Returns 0 on success.
# -----------------------------------------------------------------------------
zpd_init_shared_from_existing() {
    local old="$1" shared="$2"
    [ -n "$old" ] && [ -n "$shared" ] || return 1
    mkdir -p "$shared/persistent" 2>/dev/null || return 1

    if [ -f "$old/.env" ] && [ ! -e "$shared/.env" ]; then
        cp -a "$old/.env" "$shared/.env" 2>/dev/null || return 1
        chmod 600 "$shared/.env" 2>/dev/null || true
    fi

    if [ -d "$old/storage" ] && [ ! -e "$shared/storage" ]; then
        cp -a "$old/storage" "$shared/storage" 2>/dev/null || return 1
    fi

    return 0
}

# -----------------------------------------------------------------------------
# zpd_link_shared RELEASE_DIR SHARED_DIR
#
# Wire a release to shared state: replace the release's .env and storage with
# symlinks into shared, so every release sees the same .env/uploads and the
# encryption key never changes. Returns 0 on success.
# -----------------------------------------------------------------------------
zpd_link_shared() {
    local rel="$1" shared="$2"
    [ -d "$rel" ] && [ -d "$shared" ] || return 1

    rm -rf "$rel/.env" 2>/dev/null || true
    ln -s "$shared/.env" "$rel/.env" 2>/dev/null || return 1

    if [ -d "$shared/storage" ]; then
        rm -rf "$rel/storage" 2>/dev/null || true
        ln -s "$shared/storage" "$rel/storage" 2>/dev/null || return 1
    fi
    return 0
}

# ── Locking ─────────────────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_run_locked LOCKFILE -- CMD...
#
# Run CMD holding an exclusive, non-blocking flock on LOCKFILE. If the lock is
# already held, return 200 immediately (a second deployment must fail fast). The
# lock is released automatically when the subshell exits (success/failure/signal).
# -----------------------------------------------------------------------------
zpd_run_locked() {
    local lock="$1"; shift
    [ "${1:-}" = "--" ] && shift
    mkdir -p "$(dirname "$lock")" 2>/dev/null || true

    (
        # Open the lock file on fd 9 for this subshell only.
        exec 9>"$lock" || exit 201
        if ! flock -n 9; then
            exit 200
        fi
        "$@"
    )
}

# -----------------------------------------------------------------------------
# zpd_lock_busy_message — the Persian message shown when another deploy runs.
# -----------------------------------------------------------------------------
zpd_lock_busy_message() {
    printf 'یک عملیات به‌روزرسانی دیگر در حال اجرا است.'
}

# ── User-facing Persian result messages ─────────────────────────────────────

zpd_msg_success()          { printf 'به‌روزرسانی با موفقیت انجام شد.'; }
zpd_msg_rolled_back()      { printf 'به‌روزرسانی ناموفق بود و نسخه قبلی بازیابی شد.'; }
zpd_msg_previous_active()  { printf 'نسخه قبلی با موفقیت فعال شد.'; }
zpd_msg_db_needs_review()  { printf 'بازیابی خودکار کد انجام شد، اما مهاجرت‌های دیتابیس نیاز به بررسی دارند.'; }
