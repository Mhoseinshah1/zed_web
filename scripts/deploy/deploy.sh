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

# shellcheck source=scripts/lib/supply-chain-lib.sh
for _cand in "${_DEP_DIR}/../lib/supply-chain-lib.sh" "${ZPD_SUPPLY_LIB:-}"; do
    if [ -n "${_cand:-}" ] && [ -f "$_cand" ]; then
        # shellcheck disable=SC1090
        source "$_cand"; break
    fi
done

# shellcheck source=scripts/deploy/install-command-wrappers.sh
# Same command-wrapper implementation install.sh uses. Installing/refreshing the
# wrappers on every activation is what upgrades a LEGACY server (which migrates
# via update.sh→deploy.sh, never install.sh) off the old broken zedproxy-update.
for _cand in "${_DEP_DIR}/install-command-wrappers.sh" "${ZPD_WRAPPERS_LIB:-}"; do
    if [ -n "${_cand:-}" ] && [ -f "$_cand" ]; then
        # shellcheck disable=SC1090
        source "$_cand"; break
    fi
done

# Load persistent, non-secret deployment configuration (repo URL, ref, base,
# health URL) BEFORE any default assignment, so deploy.env values take effect
# but explicit environment variables still override them.
zpd_load_deploy_env

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
ZPD_PSQL="${ZPD_PSQL:-psql}"
ZPD_REDIS_CLI="${ZPD_REDIS_CLI:-redis-cli}"

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
      # Reproducible build: both lock files must be present; never composer
      # update / npm install; never --ignore-platform-reqs.
      if command -v zsc_require_lockfiles >/dev/null 2>&1; then
          zsc_require_lockfiles "$rel" >/dev/null || exit 9
      else
          [ -f composer.lock ] && [ -f package-lock.json ] || exit 9
      fi
      "$ZPD_COMPOSER" validate --strict --no-check-publish --no-interaction || exit 8
      "$ZPD_COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction || exit 10
      "$ZPD_NPM" ci || exit 11
      "$ZPD_NPM" run build || exit 12
      "$ZPD_PHP" artisan optimize:clear || exit 13
      "$ZPD_PHP" artisan config:cache || exit 14
      "$ZPD_PHP" artisan route:cache  || exit 15
      "$ZPD_PHP" artisan view:cache   || exit 16
    )
}

# dep_tool_versions RELEASE_DIR — print space-free "k=v" pairs for the toolchain
# and the resolved git tag, for inclusion in the release manifest.
dep_tool_versions() {
    local rel="$1" sha="$2" tag="" php cv node npm
    tag="$("$ZPD_GIT" -C "$rel" describe --tags --exact-match "$sha" 2>/dev/null || true)"
    php="$("$ZPD_PHP" -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
    cv="$("$ZPD_COMPOSER" --version --no-ansi 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || echo unknown)"
    node="$(node --version 2>/dev/null || echo unknown)"
    npm="$("$ZPD_NPM" --version 2>/dev/null || echo unknown)"
    printf 'git_tag=%s\0php_version=%s\0composer_version=%s\0node_version=%s\0npm_version=%s\0' \
        "$tag" "$php" "$cv" "$node" "$npm"
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

# ── Connectivity + worker-status probes (injectable, mockable) ───────────────

# dep_check_pg ENV_FILE — PostgreSQL connectivity via `psql -tAc 'SELECT 1'`.
dep_check_pg() {
    local env_file="$1" db user pass host port
    [ -f "$env_file" ] || return 1
    db="$(_dep_env_get "$env_file" DB_DATABASE)"
    user="$(_dep_env_get "$env_file" DB_USERNAME)"
    pass="$(_dep_env_get "$env_file" DB_PASSWORD)"
    host="$(_dep_env_get "$env_file" DB_HOST)"; host="${host:-127.0.0.1}"
    port="$(_dep_env_get "$env_file" DB_PORT)"; port="${port:-5432}"
    [ -n "$db" ] && [ -n "$user" ] || return 1
    PGPASSWORD="$pass" "$ZPD_PSQL" -h "$host" -p "$port" -U "$user" -d "$db" -tAc 'SELECT 1' >/dev/null 2>&1
}

# dep_check_redis ENV_FILE — Redis connectivity via `redis-cli ping` → PONG.
dep_check_redis() {
    local env_file="$1" host port pass out
    [ -f "$env_file" ] || return 1
    host="$(_dep_env_get "$env_file" REDIS_HOST)"; host="${host:-127.0.0.1}"
    port="$(_dep_env_get "$env_file" REDIS_PORT)"; port="${port:-6379}"
    pass="$(_dep_env_get "$env_file" REDIS_PASSWORD)"
    if [ -n "$pass" ] && [ "$pass" != "null" ]; then
        out="$("$ZPD_REDIS_CLI" -h "$host" -p "$port" -a "$pass" ping 2>/dev/null)"
    else
        out="$("$ZPD_REDIS_CLI" -h "$host" -p "$port" ping 2>/dev/null)"
    fi
    printf '%s' "$out" | grep -qi 'PONG'
}

# dep_supervisor_group_running — the worker group exists AND every process is
# RUNNING (no FATAL/BACKOFF/STOPPED/EXITED). Uses `supervisorctl status`.
dep_supervisor_group_running() {
    local group out; group="$(zpd_supervisor_worker_group)"
    out="$("$ZPD_SUPERVISORCTL" status "${group}:*" 2>/dev/null)"
    [ -n "$out" ] || return 1
    printf '%s\n' "$out" | grep -q 'RUNNING' || return 1
    if printf '%s\n' "$out" | grep -Eq 'FATAL|BACKOFF|STOPPED|EXITED'; then return 1; fi
    return 0
}

# ── Strict legacy→current cutover (fail-closed; each step verified) ──────────

# dep_cutover_nginx BASE — backup, rewrite root → current/public, verify, and
# `nginx -t`; restore the previous config and fail on any error.
dep_cutover_nginx() {
    local base="$1" conf; conf="$(zpd_nginx_conf_path)"
    [ -f "$conf" ] || { dep_err "nginx config missing: ${conf}"; return 1; }
    cp -a "$conf" "${conf}.zpd-precutover" 2>/dev/null || true
    zpd_nginx_rewrite_root "$conf" "$base" || { dep_err "$(zpd_msg_nginx_restored)"; cp -a "${conf}.zpd-precutover" "$conf" 2>/dev/null || true; return 1; }
    if ! zpd_nginx_root_ok "$conf" "$base" || ! dep_validate_nginx; then
        dep_err "$(zpd_msg_nginx_restored)"
        cp -a "${conf}.zpd-precutover" "$conf" 2>/dev/null || true
        return 1
    fi
    return 0
}

# dep_cutover_supervisor BASE — STRICT: config exists or is created explicitly;
# rewritten to current/artisan; verified; reread + update must both succeed. No
# `|| true` on any required operation. Returns 1 (→ rollback) on any failure.
dep_cutover_supervisor() {
    local base="$1" conf; conf="$(zpd_supervisor_conf_path)"
    if [ -f "$conf" ]; then
        cp -a "$conf" "${conf}.zpd-precutover" 2>/dev/null || true
        zpd_supervisor_rewrite "$conf" "$base" || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    else
        dep_warn "supervisor config missing — creating ${conf} explicitly"
        mkdir -p "$(dirname "$conf")" 2>/dev/null || true
        zpd_supervisor_conf_content "$base" > "$conf" || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    fi
    zpd_supervisor_ok "$conf" "$base"   || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    "$ZPD_SUPERVISORCTL" reread         || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    "$ZPD_SUPERVISORCTL" update         || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    return 0
}

# dep_cutover_scheduler BASE — STRICT: write the COMPLETE expected cron file
# atomically (root:root 644), then verify exactly one current/-based
# schedule:run entry and the file mode. Returns 1 (→ rollback) on any failure.
dep_cutover_scheduler() {
    local base="$1" cron dir tmp mode; cron="$(zpd_scheduler_cron_path)"
    [ -f "$cron" ] && cp -a "$cron" "${cron}.zpd-precutover" 2>/dev/null || true
    dir="$(dirname "$cron")"; mkdir -p "$dir" 2>/dev/null || true
    tmp="$(cd "$dir" && mktemp ".zpdcron.XXXXXX" 2>/dev/null)" || { dep_err "$(zpd_msg_scheduler_failed)"; return 1; }
    tmp="${dir}/${tmp}"
    if ! zpd_scheduler_cron_content "$base" > "$tmp"; then rm -f "$tmp"; dep_err "$(zpd_msg_scheduler_failed)"; return 1; fi
    chmod 644 "$tmp" 2>/dev/null || true
    chown 0:0 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$cron" || { rm -f "$tmp"; dep_err "$(zpd_msg_scheduler_failed)"; return 1; }
    zpd_scheduler_cron_ok "$cron" "$base" || { dep_err "$(zpd_msg_scheduler_failed)"; return 1; }
    mode="$(stat -c '%a' "$cron" 2>/dev/null || echo '')"
    [ "$mode" = "644" ] || { dep_err "$(zpd_msg_scheduler_failed)"; return 1; }
    # When running as root, the cron file must be root-owned.
    if [ "$(id -u)" = "0" ]; then
        [ "$(stat -c '%u' "$cron" 2>/dev/null || echo -1)" = "0" ] || { dep_err "$(zpd_msg_scheduler_failed)"; return 1; }
    fi
    return 0
}

# dep_cutover_services BASE — orchestrate the three strict cutovers. Any failure
# returns 1 so the caller rolls back to the legacy application (fail-closed).
dep_cutover_services() {
    local base="$1"
    dep_cutover_nginx "$base"      || return 1
    dep_cutover_supervisor "$base" || return 1
    dep_cutover_scheduler "$base"  || return 1
    return 0
}

# ── Wrapper backup location (for first-cutover rollback) ─────────────────────
dep_wrapper_backup_dir() { printf '%s/shared/deploy/wrappers.precutover' "$(zpd_base)"; }

# ── Expanded post-activation readiness (fail-closed) ─────────────────────────

# dep_verify_release BASE RELEASE_ID SHA [HEALTH_URL]
#
# The full readiness gate. Verifies HTTP routes, the current symlink + resolved
# release id, manifest-SHA == deployed HEAD, Nginx/Supervisor/Scheduler all on
# current/, the worker group running, schedule:list, command-wrapper resolution,
# and PostgreSQL + Redis connectivity. A failure of ANY check returns 1 so the
# deployment is NEVER reported successful while Queue or Scheduler still runs
# legacy code.
dep_verify_release() {
    local base="$1" id="$2" sha="$3" url="${4:-$ZPD_HEALTH_URL}"
    local manifest mansha headsha
    manifest="$(zpd_releases_dir)/${id}/RELEASE_MANIFEST.json"

    dep_health "$url"                                       || { dep_err "readiness: HTTP checks failed"; return 1; }
    [ -L "$(zpd_current_link)" ]                            || { dep_err "readiness: current symlink missing"; return 1; }
    [ "$(zpd_current_release)" = "$id" ]                    || { dep_err "readiness: current != ${id}"; return 1; }

    headsha="$(zpd_git_head_sha "$(zpd_current_link)" 2>/dev/null)"
    mansha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
    [ -n "$headsha" ] && [ "$mansha" = "$headsha" ]        || { dep_err "readiness: manifest SHA != deployed HEAD"; return 1; }
    [ -z "$sha" ] || [ "$sha" = "$headsha" ]               || { dep_err "readiness: deployed SHA mismatch"; return 1; }

    zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base"     || { dep_err "readiness: Nginx root not on current/"; return 1; }
    zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base" || { dep_err "readiness: Supervisor not on current/"; return 1; }
    dep_supervisor_group_running                           || { dep_err "readiness: worker group not running"; return 1; }
    zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" || { dep_err "readiness: Scheduler not on current/"; return 1; }
    dep_verify_scheduler "$(zpd_current_link)"             || { dep_err "readiness: schedule:list failed"; return 1; }

    if declare -F zpw_verify_wrappers >/dev/null 2>&1; then
        zpw_verify_wrappers "$base"                        || { dep_err "readiness: command wrappers not resolving current/"; return 1; }
    fi

    dep_check_pg "$(zpd_shared_dir)/.env"                  || { dep_err "readiness: PostgreSQL unreachable"; return 1; }
    dep_check_redis "$(zpd_shared_dir)/.env"               || { dep_err "readiness: Redis unreachable"; return 1; }
    return 0
}

# dep_activate RELEASE_ID PHP_FPM_SVC CURRENT_BEFORE [LEGACY] [SHA]
#
# The atomic activation sequence. When LEGACY=1 this is the first migration off a
# legacy single-directory install: maintenance mode targets the legacy app and
# Nginx/Supervisor/Scheduler are STRICTLY cut over to `current` after the symlink
# switch. The stable command wrappers are (re)installed from the new release on
# EVERY activation. Returns:
#   0   success
#   30  migration failed (nothing switched)
#   31  post-switch reload/cutover/readiness failed (caller must roll back)
dep_activate() {
    local id="$1" fpm="$2" current_before="$3" legacy="${4:-0}" sha="${5:-}"
    local current_dir base maint_dir
    current_dir="$(zpd_current_link)"
    base="$(zpd_base)"
    if [ "$legacy" = "1" ] && [ ! -L "$current_dir" ]; then
        maint_dir="$base"
    else
        maint_dir="$current_dir"
    fi

    # 1. maintenance mode (against the currently-live app)
    ( cd "$maint_dir" 2>/dev/null && "$ZPD_PHP" artisan down --render="errors::503" 2>/dev/null ) || true

    # 2. pause workers (stop consuming with the old code)
    "$ZPD_SUPERVISORCTL" stop "$(zpd_supervisor_worker_group):*" 2>/dev/null || true

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

    # 4a. Refresh the stable command wrappers from the NEW release. On a legacy
    #     first cutover, snapshot the existing wrappers first so a failed
    #     activation can restore them.
    if declare -F zpw_install_wrappers >/dev/null 2>&1; then
        if [ "$legacy" = "1" ]; then
            zpw_backup_wrappers "$(dep_wrapper_backup_dir)" || true
        fi
        zpw_install_wrappers || { dep_err "activation: command-wrapper install failed"; return 31; }
    fi

    # 4b. First legacy cutover: STRICTLY repoint Nginx/Supervisor/Scheduler at
    #     current/. Any failure fails the activation (→ legacy rollback).
    if [ "$legacy" = "1" ]; then
        dep_cutover_services "$base" || { dep_err "activation: service cutover failed"; return 31; }
    fi

    # 5-7. reload PHP-FPM, validate + reload Nginx
    dep_reload_php_fpm "$fpm" || { dep_err "activation: php-fpm reload failed"; return 31; }
    dep_validate_nginx        || { dep_err "activation: nginx -t failed"; return 31; }
    dep_reload_nginx          || { dep_err "activation: nginx reload failed"; return 31; }

    # 8. restart workers against the new release
    dep_restart_workers || { dep_err "activation: worker restart failed"; return 31; }

    # 9. EXPANDED readiness — HTTP + symlink + SHA + Nginx/Supervisor/Scheduler on
    #    current/ + worker group running + schedule:list + wrappers + PG + Redis.
    if ! dep_verify_release "$base" "$id" "$sha" "$ZPD_HEALTH_URL"; then
        return 31
    fi

    # 10. exit maintenance only after success
    ( cd "$current_dir" 2>/dev/null && "$ZPD_PHP" artisan up 2>/dev/null ) || true
    return 0
}

# dep_first_cutover_rollback BASE PHP_FPM_SVC
#
# Restore the legacy application after a FAILED first legacy→release cutover:
# put back the snapshotted Nginx/Supervisor/scheduler config, drop the `current`
# symlink (so Nginx serves the legacy root again), reload services, and bring the
# legacy app out of maintenance mode. Returns 0 only if the legacy app is healthy.
dep_first_cutover_rollback() {
    local base="$1" fpm="$2"
    zpd_restore_legacy_rollback || dep_warn "no legacy snapshot to restore"
    # Restore the pre-cutover command wrappers so the server keeps whatever
    # zedproxy-* commands it had before the failed migration.
    if declare -F zpw_restore_wrappers >/dev/null 2>&1 && [ -d "$(dep_wrapper_backup_dir)" ]; then
        zpw_restore_wrappers "$(dep_wrapper_backup_dir)" || dep_warn "wrapper restore failed"
    fi
    rm -f "$(zpd_current_link)" 2>/dev/null || true
    dep_reload_php_fpm "$fpm" || true
    dep_reload_nginx          || true
    # Best-effort in the rollback path (the legacy config is already restored);
    # surfaced as warnings rather than hidden, and never `|| true`.
    "$ZPD_SUPERVISORCTL" reread >/dev/null 2>&1 || dep_warn "supervisor reread during rollback failed"
    "$ZPD_SUPERVISORCTL" update >/dev/null 2>&1 || dep_warn "supervisor update during rollback failed"
    dep_restart_workers       || dep_warn "worker restart during rollback failed"
    ( cd "$base" 2>/dev/null && "$ZPD_PHP" artisan up 2>/dev/null ) || true
    dep_health "$ZPD_HEALTH_URL"
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
    local base shared releases lockfile fpm ver legacy=0
    # Load persistent non-secret config for this invocation (explicit env wins).
    zpd_load_deploy_env
    base="$(zpd_base)"; shared="$(zpd_shared_dir)"; releases="$(zpd_releases_dir)"
    lockfile="$(zpd_lock_file)"
    mkdir -p "$(zpd_log_dir)" "$releases" "$shared" 2>/dev/null || true

    ver="$("$ZPD_PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.3)"
    fpm="php${ver}-fpm"

    # Detect a legacy (pre-atomic) single-directory install BEFORE we create
    # shared state, so the first cutover repoints services and keeps a rollback
    # path to the legacy application.
    zpd_is_legacy_layout && legacy=1

    # First release-based deploy on a legacy single-dir install: migrate the
    # existing .env + storage (uploads, encryption key) into shared, once, safely.
    dep_ensure_shared "$shared"

    dep_log "Preflight…"
    dep_preflight "$shared" || return 1

    # ── Resolve repository + ref + exact commit SHA BEFORE any backup/migration.
    # The repository source is resolved by precedence (explicit → deploy.env →
    # active release origin → legacy origin → public default) and can NEVER be
    # "." or the caller's working directory.
    local repo ref sha errfile
    errfile="$(mktemp 2>/dev/null || echo /tmp/zpd-git.$$)"
    repo="$(zpd_resolve_repo_url)" || { dep_err "$(zpd_msg_no_repo)"; rm -f "$errfile"; return 1; }
    ref="${ZPD_REF:-$(zpd_default_ref)}"

    dep_log "Resolving ${ref} from repository…"
    sha="$(zpd_resolve_sha "$repo" "$ref" "$errfile")" || {
        dep_err "$(zpd_msg_git_fetch_failed)"
        zpd_redact_file "$errfile" >&2
        rm -f "$errfile"
        return 1
    }

    local id rel_dir current_before manifest ts_start
    id="$(zpd_release_id "$sha")" || { dep_err "could not derive release id from resolved SHA"; rm -f "$errfile"; return 1; }
    rel_dir="${releases}/${id}"
    current_before="$(zpd_current_release)"
    ts_start="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    manifest="${rel_dir}/RELEASE_MANIFEST.json"

    dep_log "Backing up .env + database…"
    dep_backup_env "${shared}/.env" "$(zpd_backup_dir)/${id}" || { dep_err "env backup failed"; rm -f "$errfile"; return 1; }
    if ! dep_backup_database "$(zpd_backup_dir)/${id}/db.dump" "${shared}/.env"; then
        dep_err "database backup failed — aborting (nothing changed)"
        rm -f "$errfile"
        return 1
    fi

    dep_log "Fetching ${ref} (${sha:0:12}) into ${rel_dir}…"
    if ! zpd_git_clone_ref "$repo" "$ref" "$rel_dir" "$errfile"; then
        dep_err "$(zpd_msg_git_fetch_failed)"
        zpd_redact_file "$errfile" >&2
        rm -rf "$rel_dir"; rm -f "$errfile"
        return 1
    fi

    # Verify the EXACT commit was checked out (the manifest must describe the
    # code that is actually deployed).
    local got; got="$(zpd_git_head_sha "$rel_dir")"
    if [ "$got" != "$sha" ]; then
        dep_err "deployed commit ${got:-<none>} does not match resolved ${sha} — aborting"
        rm -rf "$rel_dir"; rm -f "$errfile"
        return 1
    fi
    rm -f "$errfile"

    zpd_link_shared "$rel_dir" "$shared" || { dep_err "link shared failed"; rm -rf "$rel_dir"; return 1; }

    dep_log "Building release…"
    if ! dep_build "$rel_dir"; then
        dep_err "build failed — marking release failed, current untouched"
        zpd_write_manifest "$manifest" "release_id=${id}" "git_sha=${sha}" "result=failed" "stage=build" "started_at=${ts_start}"
        mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
        return 1
    fi
    dep_smoke "$rel_dir" || { dep_err "smoke test failed"; mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true; return 1; }

    # Record the RESOLVED full commit SHA, the repository, the requested ref, and
    # the exact toolchain versions.
    local -a ver_pairs=()
    mapfile -d '' -t ver_pairs < <(dep_tool_versions "$rel_dir" "$sha")
    zpd_write_manifest "$manifest" \
        "release_id=${id}" "git_sha=${sha}" "git_ref=${ref}" "repo_url=${repo}" \
        "previous_release=${current_before}" "legacy_migration=${legacy}" \
        "started_at=${ts_start}" "result=activating" "migration_status=pending" \
        "${ver_pairs[@]}"

    # Snapshot the legacy operational config so a failed FIRST cutover can restore
    # the legacy application exactly (there is no previous release to fall back on).
    if [ "$legacy" = "1" ] && ! zpd_has_legacy_rollback; then
        zpd_save_legacy_rollback "$base" \
            "$(zpd_nginx_conf_path)" "$(zpd_supervisor_conf_path)" "$(zpd_scheduler_cron_path)"
    fi

    dep_log "Activating ${id}…"
    dep_activate "$id" "$fpm" "$current_before" "$legacy" "$sha"
    local rc=$?

    if [ "$rc" -eq 0 ]; then
        zpd_write_manifest "$manifest" \
            "release_id=${id}" "git_sha=${sha}" "git_ref=${ref}" "repo_url=${repo}" \
            "previous_release=${current_before}" "legacy_migration=${legacy}" \
            "started_at=${ts_start}" "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            "result=success" "migration_status=applied" "health=ok" \
            "${ver_pairs[@]}"
        zpd_write_manifest "$(zpd_state_file)" "active_release=${id}" "previous_release=${current_before}" "result=success"
        dep_log "$(zpd_msg_success)"
        dep_cleanup_releases
        return 0
    fi

    # ── Activation failed. ──
    dep_err "activation failed (rc=${rc})"

    # First legacy→release cutover with no previous release: restore the legacy
    # application (its Nginx/Supervisor/scheduler config + drop `current`).
    if [ "$legacy" = "1" ] && [ -z "$current_before" ]; then
        dep_err "rolling back to the legacy application"
        if dep_first_cutover_rollback "$base" "$fpm"; then
            zpd_write_manifest "$manifest" "release_id=${id}" "git_sha=${sha}" "result=failed" \
                "stage=first_cutover" "rolled_back_to=legacy" \
                "migration_status=$( [ "$rc" -eq 30 ] && echo none || echo applied_not_rolled_back )"
            mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
            dep_log "$(zpd_msg_legacy_restored)"
        else
            dep_err "legacy application could not be restored to a healthy state"
        fi
        return 1
    fi

    # Normal automatic code rollback to the previous release.
    dep_err "rolling back code to ${current_before:-<none>}"
    if dep_rollback_code "$current_before" "$fpm" && dep_health "$ZPD_HEALTH_URL"; then
        if [ "$rc" -eq 30 ]; then
            zpd_write_manifest "$manifest" "release_id=${id}" "git_sha=${sha}" "result=failed" "stage=migrate" \
                "rolled_back_to=${current_before}" "migration_status=none"
            dep_log "$(zpd_msg_rolled_back)"
        else
            zpd_write_manifest "$manifest" "release_id=${id}" "git_sha=${sha}" "result=failed" "stage=activate" \
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
