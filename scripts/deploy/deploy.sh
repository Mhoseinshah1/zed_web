#!/usr/bin/env bash
# =============================================================================
# ZedProxy atomic release-based deployment.
#
#   sudo bash scripts/deploy/deploy.sh
#
# Builds a brand-new release in an isolated directory, runs preflight + backups,
# then activates it with an atomic `current` symlink switch. Any failure leaves
# the currently-live release untouched (build failures) or triggers an automatic
# code rollback (activation failures). The DB is never auto-rolled-back.
#
# This file is SOURCE-SAFE: sourcing it only defines functions (no logging, no
# traps, no deployment). main() runs only when executed directly, so the test
# harness can source it and drive individual functions with mocked commands.
#
# Overridable external commands (tests inject mocks):
#   ZPD_COMPOSER ZPD_NPM ZPD_PHP ZPD_PG_DUMP ZPD_SYSTEMCTL ZPD_NGINX
#   ZPD_SUPERVISORCTL ZPD_CURL ZPD_GIT
# Overridable paths come from deploy-lib.sh (ZPD_BASE, ZPD_LOCK_FILE, ...).
# =============================================================================

# shellcheck source=scripts/lib/deploy-lib.sh
_DEP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
for _cand in "${_DEP_DIR}/../lib/deploy-lib.sh" "${ZPD_LIB:-}"; do
    if [ -n "${_cand:-}" ] && [ -f "$_cand" ]; then
        # shellcheck disable=SC1090
        source "$_cand"; break
    fi
done

# Injectable commands.
ZPD_COMPOSER="${ZPD_COMPOSER:-composer}"
ZPD_NPM="${ZPD_NPM:-npm}"
ZPD_PHP="${ZPD_PHP:-php}"
ZPD_PG_DUMP="${ZPD_PG_DUMP:-pg_dump}"
ZPD_SYSTEMCTL="${ZPD_SYSTEMCTL:-systemctl}"
ZPD_NGINX="${ZPD_NGINX:-nginx}"
ZPD_SUPERVISORCTL="${ZPD_SUPERVISORCTL:-supervisorctl}"
ZPD_CURL="${ZPD_CURL:-curl}"
ZPD_GIT="${ZPD_GIT:-git}"

ZPD_HEALTH_URL="${ZPD_HEALTH_URL:-http://localhost}"
ZPD_MIN_DISK_MB="${ZPD_MIN_DISK_MB:-1024}"
ZPD_KEEP_RELEASES="${ZPD_KEEP_RELEASES:-5}"

dep_log()  { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
dep_warn() { printf '[%s] WARN: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
dep_err()  { printf '[%s] ERROR: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }

# ── Preflight ────────────────────────────────────────────────────────────────

# dep_preflight SHARED_DIR — verify the release can proceed. Returns non-zero
# (and prints the failing check) BEFORE anything live is touched.
dep_preflight() {
    local shared="$1"
    local env_file="${shared}/.env"

    [ -f "$env_file" ] || { dep_err "preflight: .env missing at ${env_file}"; return 1; }

    local appkey
    appkey="$(grep -E '^APP_KEY=' "$env_file" | head -1 | cut -d= -f2-)"
    appkey="${appkey//\"/}"
    [ -n "$appkey" ] || { dep_err "preflight: APP_KEY is empty"; return 1; }

    command -v "$ZPD_PHP"      >/dev/null 2>&1 || { dep_err "preflight: php missing"; return 1; }
    command -v "$ZPD_COMPOSER" >/dev/null 2>&1 || { dep_err "preflight: composer missing"; return 1; }
    command -v "$ZPD_NPM"      >/dev/null 2>&1 || { dep_err "preflight: npm missing"; return 1; }

    [ -w "$shared" ] || { dep_err "preflight: shared dir not writable"; return 1; }

    if ! zpd_check_disk_space "$(zpd_base)" "$ZPD_MIN_DISK_MB"; then
        dep_err "preflight: not enough disk space (need ${ZPD_MIN_DISK_MB}MB)"
        return 1
    fi

    return 0
}

# ── Backups ──────────────────────────────────────────────────────────────────

# dep_backup_env SRC_ENV OUT_DIR — copy .env (mode 600). Returns non-zero on fail.
dep_backup_env() {
    local src="$1" out="$2"
    [ -f "$src" ] || return 1
    mkdir -p "$out" 2>/dev/null || return 1
    chmod 700 "$out" 2>/dev/null || true
    cp -a "$src" "${out}/.env" 2>/dev/null || return 1
    chmod 600 "${out}/.env" 2>/dev/null || true
}

# _dep_env_get ENV_FILE KEY — read a dotenv value, stripping surrounding quotes.
_dep_env_get() {
    local file="$1" key="$2" line val
    line="$(grep -E "^${key}=" "$file" 2>/dev/null | head -1)" || true
    val="${line#*=}"
    val="${val%$'\r'}"
    val="${val#\"}"; val="${val%\"}"
    val="${val#\'}"; val="${val%\'}"
    printf '%s' "$val"
}

# dep_backup_database OUT_FILE ENV_FILE — pg_dump the DB. If the DB backup fails
# the deployment MUST stop, so this returns the dump's exit code. Never prints
# the password.
dep_backup_database() {
    local out="$1" env_file="$2"
    local db user pass host port
    db="$(_dep_env_get "$env_file" DB_DATABASE)"
    user="$(_dep_env_get "$env_file" DB_USERNAME)"
    pass="$(_dep_env_get "$env_file" DB_PASSWORD)"
    host="$(_dep_env_get "$env_file" DB_HOST)"; host="${host:-127.0.0.1}"
    port="$(_dep_env_get "$env_file" DB_PORT)"; port="${port:-5432}"

    [ -n "$db" ] && [ -n "$user" ] || { dep_err "backup: DB_DATABASE/DB_USERNAME missing"; return 1; }
    mkdir -p "$(dirname "$out")" 2>/dev/null || return 1

    PGPASSWORD="$pass" "$ZPD_PG_DUMP" -h "$host" -p "$port" -U "$user" -d "$db" -Fc -f "$out" || return 1
    chmod 600 "$out" 2>/dev/null || true
    return 0
}

# ── Build (inside the NEW release; never touches current) ────────────────────

# dep_build RELEASE_DIR — install deps, build assets, cache config. A composer/
# npm/build failure returns non-zero; the caller must discard the release.
dep_build() {
    local rel="$1"
    ( cd "$rel" || exit 1
      "$ZPD_COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction || exit 10
      "$ZPD_NPM" ci || exit 11
      "$ZPD_NPM" run build || exit 12
      "$ZPD_PHP" artisan optimize:clear || exit 13
      "$ZPD_PHP" artisan config:cache || exit 14
      "$ZPD_PHP" artisan route:cache  || exit 15
      "$ZPD_PHP" artisan view:cache   || exit 16
    )
}

# dep_smoke RELEASE_DIR — application smoke test before activation.
dep_smoke() {
    local rel="$1"
    ( cd "$rel" || exit 1
      "$ZPD_PHP" artisan zedproxy:health --json >/dev/null 2>&1 || true  # infra may be down mid-deploy
      "$ZPD_PHP" -v >/dev/null 2>&1 || exit 1
    )
}

# ── Health verification (after activation) ───────────────────────────────────

# dep_http_code URL — print the HTTP status (000 on failure).
dep_http_code() {
    "$ZPD_CURL" -s -o /dev/null -w '%{http_code}' --max-time 10 "$1" 2>/dev/null || printf '000'
}

# dep_health BASE_URL — verify required public + infra checks. Returns non-zero
# on the first required failure. A failed check MUST fail the deployment.
dep_health() {
    local base="${1:-$ZPD_HEALTH_URL}"

    local code
    code="$(dep_http_code "${base}/health")"
    [ "$code" = "200" ] || { dep_err "health: /health returned ${code}"; return 1; }

    code="$(dep_http_code "${base}/health/live")"
    [ "$code" = "200" ] || { dep_err "health: liveness returned ${code}"; return 1; }

    code="$(dep_http_code "${base}/")"
    case "$code" in 200|301|302) ;; *) dep_err "health: homepage returned ${code}"; return 1;; esac

    code="$(dep_http_code "${base}/login")"
    case "$code" in 200|301|302) ;; *) dep_err "health: login route returned ${code}"; return 1;; esac

    return 0
}

# dep_verify_scheduler CURRENT_DIR — scheduler installed + heartbeat reachable.
dep_verify_scheduler() {
    local cur="$1"
    ( cd "$cur" 2>/dev/null || exit 1
      "$ZPD_PHP" artisan schedule:list >/dev/null 2>&1 || exit 1
    )
}

# ── Service reloads ──────────────────────────────────────────────────────────

dep_reload_php_fpm() {
    local svc="$1"
    "$ZPD_SYSTEMCTL" reload "$svc" 2>/dev/null || "$ZPD_SYSTEMCTL" restart "$svc"
}

dep_validate_nginx() { "$ZPD_NGINX" -t; }
dep_reload_nginx()   { "$ZPD_SYSTEMCTL" reload nginx; }

dep_restart_workers() {
    "$ZPD_SUPERVISORCTL" restart 'zedproxy-worker:*'
}

# ── Activation (atomic) ──────────────────────────────────────────────────────

# dep_activate RELEASE_ID PHP_FPM_SVC CURRENT_BEFORE
#
# The atomic activation sequence. Returns:
#   0   success
#   30  migration failed (nothing switched)
#   31  post-switch reload/health failed (caller must roll back code)
dep_activate() {
    local id="$1" fpm="$2" current_dir; current_dir="$(zpd_current_link)"

    # 1. maintenance mode (against the CURRENT release)
    ( cd "$current_dir" 2>/dev/null && "$ZPD_PHP" artisan down --render="errors::503" 2>/dev/null ) || true

    # 2. pause workers (stop consuming with the old code)
    "$ZPD_SUPERVISORCTL" stop 'zedproxy-worker:*' 2>/dev/null || true

    # 3. migrations (run from the NEW release, which is linked to shared .env)
    if ! ( cd "$(zpd_releases_dir)/${id}" && "$ZPD_PHP" artisan migrate --force ); then
        dep_err "activation: migrations failed"
        return 30
    fi

    # 4. atomic symlink switch
    if ! zpd_switch_current "$id"; then
        dep_err "activation: symlink switch failed"
        return 31
    fi

    # 5-7. reload PHP-FPM, validate + reload Nginx
    dep_reload_php_fpm "$fpm" || { dep_err "activation: php-fpm reload failed"; return 31; }
    dep_validate_nginx        || { dep_err "activation: nginx -t failed"; return 31; }
    dep_reload_nginx          || { dep_err "activation: nginx reload failed"; return 31; }

    # 8. restart workers against the new release
    dep_restart_workers || { dep_err "activation: worker restart failed"; return 31; }

    # 9. readiness
    if ! dep_health "$ZPD_HEALTH_URL"; then
        return 31
    fi

    # 10. exit maintenance only after success
    ( cd "$current_dir" 2>/dev/null && "$ZPD_PHP" artisan up 2>/dev/null ) || true
    return 0
}

# ── Automatic rollback ───────────────────────────────────────────────────────

# dep_rollback_code PREV_ID PHP_FPM_SVC — switch current back to PREV_ID and
# reload services against it. Returns non-zero if the previous release can't be
# restored.
dep_rollback_code() {
    local prev="$1" fpm="$2"
    [ -n "$prev" ] || { dep_err "rollback: no previous release"; return 1; }
    zpd_switch_current "$prev" || { dep_err "rollback: switch back failed"; return 1; }
    dep_reload_php_fpm "$fpm" || true
    dep_reload_nginx          || true
    dep_restart_workers       || true
    ( cd "$(zpd_current_link)" 2>/dev/null && "$ZPD_PHP" artisan up 2>/dev/null ) || true
    return 0
}

# dep_ensure_shared SHARED_DIR — one-time compatibility migration. If shared/.env
# is absent but a legacy single-directory .env exists (ZPD_LEGACY_DIR, default
# the base dir), move .env + storage into shared without altering their content
# so the encryption key and uploads are preserved. Idempotent.
dep_ensure_shared() {
    local shared="$1" legacy="${ZPD_LEGACY_DIR:-$(zpd_base)}"
    [ -e "${shared}/.env" ] && return 0
    if [ -f "${legacy}/.env" ]; then
        dep_log "Bootstrapping shared storage from legacy install at ${legacy}…"
        zpd_init_shared_from_existing "$legacy" "$shared" \
            || { dep_warn "shared bootstrap failed"; return 1; }
    fi
    return 0
}

# ── main ─────────────────────────────────────────────────────────────────────

dep_main() {
    local base shared releases lockfile fpm ver
    base="$(zpd_base)"; shared="$(zpd_shared_dir)"; releases="$(zpd_releases_dir)"
    lockfile="$(zpd_lock_file)"
    mkdir -p "$(zpd_log_dir)" "$releases" "$shared" 2>/dev/null || true

    ver="$("$ZPD_PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.3)"
    fpm="php${ver}-fpm"

    # First release-based deploy on a legacy single-dir install: migrate the
    # existing .env + storage (uploads, encryption key) into shared, once, safely.
    dep_ensure_shared "$shared"

    dep_log "Preflight…"
    dep_preflight "$shared" || return 1

    local id rel_dir current_before manifest ts_start
    id="$(zpd_release_id)"
    rel_dir="${releases}/${id}"
    current_before="$(zpd_current_release)"
    ts_start="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    manifest="${rel_dir}/RELEASE_MANIFEST.json"

    dep_log "Backing up .env + database…"
    dep_backup_env "${shared}/.env" "$(zpd_backup_dir)/${id}" || { dep_err "env backup failed"; return 1; }
    if ! dep_backup_database "$(zpd_backup_dir)/${id}/db.dump" "${shared}/.env"; then
        dep_err "database backup failed — aborting (nothing changed)"
        return 1
    fi

    dep_log "Fetching code into ${rel_dir}…"
    mkdir -p "$rel_dir"
    "$ZPD_GIT" clone --depth 1 "${ZPD_REPO_URL:-.}" "$rel_dir" 2>/dev/null \
        || { dep_err "git clone failed"; rm -rf "$rel_dir"; return 1; }

    zpd_link_shared "$rel_dir" "$shared" || { dep_err "link shared failed"; rm -rf "$rel_dir"; return 1; }

    dep_log "Building release…"
    if ! dep_build "$rel_dir"; then
        dep_err "build failed — marking release failed, current untouched"
        zpd_write_manifest "$manifest" "release_id=${id}" "result=failed" "stage=build" "started_at=${ts_start}"
        mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
        return 1
    fi
    dep_smoke "$rel_dir" || { dep_err "smoke test failed"; mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true; return 1; }

    local sha; sha="$("$ZPD_GIT" -C "$rel_dir" rev-parse HEAD 2>/dev/null || echo unknown)"
    zpd_write_manifest "$manifest" \
        "release_id=${id}" "git_sha=${sha}" "previous_release=${current_before}" \
        "started_at=${ts_start}" "result=activating" "migration_status=pending"

    dep_log "Activating ${id}…"
    dep_activate "$id" "$fpm" "$current_before"
    local rc=$?

    if [ "$rc" -eq 0 ]; then
        zpd_write_manifest "$manifest" \
            "release_id=${id}" "git_sha=${sha}" "previous_release=${current_before}" \
            "started_at=${ts_start}" "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            "result=success" "migration_status=applied" "health=ok"
        zpd_write_manifest "$(zpd_state_file)" "active_release=${id}" "previous_release=${current_before}" "result=success"
        dep_log "$(zpd_msg_success)"
        dep_cleanup_releases
        return 0
    fi

    # Activation failed → automatic code rollback.
    dep_err "activation failed (rc=${rc}) — rolling back code"
    if dep_rollback_code "$current_before" "$fpm" && dep_health "$ZPD_HEALTH_URL"; then
        if [ "$rc" -eq 30 ]; then
            # migrations never ran (failure was during migrate) → DB unchanged
            zpd_write_manifest "$manifest" "release_id=${id}" "result=failed" "stage=migrate" \
                "rolled_back_to=${current_before}" "migration_status=none"
            dep_log "$(zpd_msg_rolled_back)"
        else
            # migrations MAY have applied before activation failed → warn about DB
            zpd_write_manifest "$manifest" "release_id=${id}" "result=failed" "stage=activate" \
                "rolled_back_to=${current_before}" "migration_status=applied_not_rolled_back"
            dep_warn "$(zpd_msg_db_needs_review)"
            dep_warn "DB backup: $(zpd_backup_dir)/${id}/db.dump"
        fi
        dep_log "$(zpd_msg_previous_active)"
    else
        dep_err "automatic rollback could not restore a healthy previous release"
    fi
    return 1
}

# dep_cleanup_releases — prune old releases (never active/previous) with a disk
# check before and after.
dep_cleanup_releases() {
    local before after rel
    before="$(zpd_disk_free_mb "$(zpd_base)")"
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        rm -rf "$(zpd_releases_dir)/${rel}" 2>/dev/null || true
        dep_log "pruned old release ${rel}"
    done < <(zpd_prunable_releases "$ZPD_KEEP_RELEASES")
    after="$(zpd_disk_free_mb "$(zpd_base)")"
    dep_log "disk free ${before}MB → ${after}MB"
}

# Run only when executed (not sourced), holding the deploy lock.
if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -Euo pipefail
    _lock="$(zpd_lock_file)"
    zpd_run_locked "$_lock" -- dep_main "$@"
    _rc=$?
    if [ "$_rc" -eq 200 ]; then
        echo "$(zpd_lock_busy_message)" >&2
        exit 200
    fi
    exit "$_rc"
fi
