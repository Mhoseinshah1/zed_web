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
ZPD_NODE="${ZPD_NODE:-node}"

# Health is validated against the internal LOOPBACK vhost by default, never the
# public host (no Cloudflare / public TLS dependency). deploy.env may override.
ZPD_HEALTH_URL="${ZPD_HEALTH_URL:-$(zpd_local_health_url)}"
ZPD_MIN_DISK_MB="${ZPD_MIN_DISK_MB:-1024}"
ZPD_KEEP_RELEASES="${ZPD_KEEP_RELEASES:-5}"

# Bounded, non-interactive probe / stage timeouts (seconds). Metadata is
# informational and must NEVER block the deployment. Service-control commands
# (systemctl / supervisorctl / nginx -t) are also bounded — no production
# external command may wait indefinitely.
ZPD_PROBE_TIMEOUT="${ZPD_PROBE_TIMEOUT:-5}"
ZPD_META_TIMEOUT="${ZPD_META_TIMEOUT:-30}"
ZPD_HEALTH_CLI_TIMEOUT="${ZPD_HEALTH_CLI_TIMEOUT:-20}"
ZPD_SVC_TIMEOUT="${ZPD_SVC_TIMEOUT:-60}"
ZPD_DOCTOR_TIMEOUT="${ZPD_DOCTOR_TIMEOUT:-120}"

dep_log()  { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
dep_warn() { printf '[%s] WARN: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
dep_err()  { printf '[%s] ERROR: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
dep_debug(){ [ "${ZPD_DEBUG:-0}" = "1" ] && printf '[%s] DEBUG: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2 || true; }

# dep_svc CMD ARGS... — run a service-control command BOUNDED and non-interactive
# (a hung systemctl/supervisorctl/nginx can never freeze the deploy).
dep_svc() { timeout "${ZPD_SVC_TIMEOUT}s" "$@" </dev/null; }

# ── Deployment stage model ───────────────────────────────────────────────────
#
# Every stage transition is logged with UTC timestamp, release, previous
# release, and the duration of the stage that just ended. DEP_STAGE is also what
# the interrupt handler and failure finalization report. No secrets are logged.
DEP_STAGE="init"
DEP_STAGE_TS=0
DEP_STAGE_RELEASE=""
DEP_STAGE_PREVIOUS=""

# dep_stage NAME — enter deployment stage NAME (logs the transition).
dep_stage() {
    local now dur=""
    now="$(date +%s 2>/dev/null || echo 0)"
    [ "$DEP_STAGE_TS" -gt 0 ] 2>/dev/null && dur=" (+$((now - DEP_STAGE_TS))s)"
    dep_log "STAGE ${DEP_STAGE} -> ${1} release=${DEP_STAGE_RELEASE:-<none>} previous=${DEP_STAGE_PREVIOUS:-<none>}${dur}"
    DEP_STAGE="$1"
    DEP_STAGE_TS="$now"
}

# ── Immutable ORIGINAL-failure context ───────────────────────────────────────
#
# DEP_STAGE keeps moving during rollback (rollback_switch → rollback_readiness),
# so the finalizer must never read it to describe WHAT FAILED. The FIRST failure
# recorded here is immutable: a Scheduler/internal-readiness failure remains the
# original failure even after a rollback has run through every rollback stage.
DEP_ORIG_FAILURE_STAGE=""
DEP_ORIG_FAILURE_REASON=""
DEP_ORIG_FAILURE_INVARIANT=""
DEP_ORIG_FAILURE_MESSAGE=""

# dep_record_failure STAGE REASON_CODE INVARIANT MESSAGE — record the ORIGINAL
# failure once; later calls (e.g. during rollback) never overwrite it.
dep_record_failure() {
    [ -n "$DEP_ORIG_FAILURE_STAGE" ] && return 0
    DEP_ORIG_FAILURE_STAGE="$1"
    DEP_ORIG_FAILURE_REASON="$2"
    DEP_ORIG_FAILURE_INVARIANT="$3"
    DEP_ORIG_FAILURE_MESSAGE="$4"
}

# dep_fail STAGE REASON_CODE MESSAGE — shared bounded failure recorder for EVERY
# meaningful stage (preflight, resolve, backup, clone, SHA verification,
# link_shared, build, smoke, metadata, manifest preparation, reconciliation,
# activation, rollback). Records the immutable original failure, writes a SAFE
# central failure event (works BEFORE any release id exists), and produces a
# bounded redacted diagnostic bundle when possible — never hiding the original
# error. Always returns 1 so callers can `dep_fail … ; return 1` or `|| return 1`.
dep_fail() {
    local stage="$1" reason="$2" msg="$3"
    dep_record_failure "$stage" "$reason" "$stage" "$msg"
    dep_err "$msg"
    zpd_write_manifest "$(zpd_shared_dir)/deploy/last-failure.json" \
        "stage=${stage}" "reason_code=${reason}" "message=${msg}" \
        "release_id=${DEP_STAGE_RELEASE:-none}" \
        "previous_release=${DEP_STAGE_PREVIOUS:-none}" \
        "occurred_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        || dep_warn "could not write the central failure event"
    dep_failure_bundle
    return 1
}

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
#
# Composer root policy (documented, deterministic): the deployer runs as root, so
# COMPOSER_ALLOW_SUPERUSER=1 is exported ONLY inside this build subshell — never
# globally — so required Composer plugins/scripts behave identically in CI and
# production instead of being silently disabled with a warning. Build commands
# read stdin from /dev/null so they can never wait for terminal input.
dep_build() {
    local rel="$1"
    ( cd "$rel" || exit 1
      export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
      # Reproducible build: both lock files must be present; never composer
      # update / npm install; never --ignore-platform-reqs.
      if command -v zsc_require_lockfiles >/dev/null 2>&1; then
          zsc_require_lockfiles "$rel" >/dev/null || exit 9
      else
          [ -f composer.lock ] && [ -f package-lock.json ] || exit 9
      fi
      "$ZPD_COMPOSER" validate --strict --no-check-publish --no-interaction </dev/null || exit 8
      "$ZPD_COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction </dev/null || exit 10
      "$ZPD_NPM" ci </dev/null || exit 11
      "$ZPD_NPM" run build </dev/null || exit 12
      "$ZPD_PHP" artisan optimize:clear </dev/null || exit 13
      "$ZPD_PHP" artisan config:cache </dev/null || exit 14
      "$ZPD_PHP" artisan route:cache  </dev/null || exit 15
      "$ZPD_PHP" artisan view:cache   </dev/null || exit 16
    )
}

# dep_probe_version CMD [ARGS...]
#
# Run an external version command SAFELY for informational metadata:
#   - bounded by `timeout ${ZPD_PROBE_TIMEOUT}s` (a hung Composer can never block)
#   - stdin from /dev/null (never reads the terminal → never "waits for input")
#   - non-interactive env (COMPOSER_NO_INTERACTION=1, GIT_TERMINAL_PROMPT=0, …)
# Prints the first version-like token (e.g. 2.8.1) or "unknown" on any
# failure/timeout/malformed output. NEVER fails, blocks, or exposes secrets.
dep_probe_version() {
    local out
    out="$(timeout "${ZPD_PROBE_TIMEOUT}s" env \
            COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1 \
            GIT_TERMINAL_PROMPT=0 CI=1 DEBIAN_FRONTEND=noninteractive \
            "$@" </dev/null 2>/dev/null)" || { printf 'unknown'; return 0; }
    out="$(printf '%s' "$out" | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n1)"
    [ -n "$out" ] || out="unknown"
    printf '%s' "$out"
}

# dep_wait_bounded PID SECS — wait up to SECS for PID; TERM then KILL on timeout.
# Returns 0 if PID exited on its own, 124 if it had to be killed.
dep_wait_bounded() {
    local pid="$1" secs="$2" i=0
    while kill -0 "$pid" 2>/dev/null; do
        if [ "$i" -ge "$secs" ]; then
            kill -TERM "$pid" 2>/dev/null || true
            sleep 1
            kill -KILL "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true
            return 124
        fi
        sleep 1; i=$((i + 1))
    done
    wait "$pid" 2>/dev/null || true
    return 0
}

# dep_tool_versions RELEASE_DIR SHA — NUL-delimited "k=v" toolchain metadata.
# Every probe is individually bounded + non-interactive; the whole stage is also
# protected by a global deadline at the call site (dep_collect_metadata). A
# missing/slow version yields "unknown" and never aborts the deployment.
dep_tool_versions() {
    local rel="$1" sha="$2" tag php cv node npm
    tag="$(timeout "${ZPD_PROBE_TIMEOUT}s" "$ZPD_GIT" -C "$rel" describe --tags --exact-match "$sha" </dev/null 2>/dev/null || true)"
    php="$(dep_probe_version "$ZPD_PHP" -r 'echo PHP_VERSION;')"
    cv="$(dep_probe_version "$ZPD_COMPOSER" --version --no-ansi)"
    node="$(dep_probe_version "$ZPD_NODE" --version)"
    npm="$(dep_probe_version "$ZPD_NPM" --version)"
    printf 'git_tag=%s\0php_version=%s\0composer_version=%s\0node_version=%s\0npm_version=%s\0' \
        "$tag" "$php" "$cv" "$node" "$npm"
}

# dep_collect_metadata RELEASE_DIR SHA OUT_FILE
#
# Collect release metadata into OUT_FILE, logging the stage explicitly and
# enforcing a GLOBAL deadline (ZPD_META_TIMEOUT) so the terminal can never appear
# frozen after the Blade cache output. On timeout the partial file is used and a
# warning is logged — metadata is informational and never blocks activation.
dep_collect_metadata() {
    local rel="$1" sha="$2" out="$3"
    dep_log "Collecting release metadata..."
    : > "$out"
    dep_tool_versions "$rel" "$sha" > "$out" &
    local pid=$!
    if dep_wait_bounded "$pid" "$ZPD_META_TIMEOUT"; then
        dep_log "Release metadata collected."
    else
        dep_warn "metadata collection exceeded ${ZPD_META_TIMEOUT}s — continuing with partial metadata"
    fi
}

# dep_smoke RELEASE_DIR — application smoke test before activation. Uses the
# SAME bounded CLI-health helper as readiness (timeout + stdin from /dev/null) —
# never an unbounded `artisan zedproxy:health`. A timeout or health failure
# fails the build BEFORE activation, and the (redacted) cause is shown instead
# of being silently discarded.
dep_smoke() {
    local rel="$1"
    dep_log "Smoke-testing the new release (bounded CLI health)…"
    ( cd "$rel" || exit 1; "$ZPD_PHP" -v </dev/null >/dev/null 2>&1 ) \
        || { dep_err "smoke: php cannot execute in the new release"; return 1; }
    dep_cli_health "$rel" || { dep_err "smoke: CLI health failed in the new release"; return 1; }
    return 0
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

# dep_verify_scheduler CURRENT_DIR — bounded `schedule:list` probe. A Laravel
# bootstrap that hangs must never freeze the deployer, the repair (which holds
# the deployment lock), or the doctor.
dep_verify_scheduler() {
    local cur="$1"
    ( cd "$cur" 2>/dev/null || exit 1
      timeout "${ZPD_HEALTH_CLI_TIMEOUT}s" "$ZPD_PHP" artisan schedule:list </dev/null >/dev/null 2>&1 || exit 1
    )
}

# ── Maintenance-mode helpers (framework-compatible; no translated text) ──────

# dep_is_in_maintenance APP_DIR — 0 when the app is in maintenance mode.
dep_is_in_maintenance() { zpd_is_in_maintenance "$1"; }

# dep_bring_down APP_DIR — enter maintenance (best-effort; used to fence the app).
dep_bring_down() {
    ( cd "$1" 2>/dev/null && "$ZPD_PHP" artisan down --render="errors::503" </dev/null >/dev/null 2>&1 ) || true
}

# dep_bring_up APP_DIR — REQUIRED exit from maintenance. `artisan up` must succeed
# AND the maintenance flag must actually be cleared. Returns 1 otherwise (never
# `|| true`), so a stuck maintenance state fails activation/rollback.
dep_bring_up() {
    local d="$1"
    if ! ( cd "$d" 2>/dev/null && "$ZPD_PHP" artisan up </dev/null >/dev/null 2>&1 ); then
        dep_err "$(zpd_msg_up_failed)"
        return 1
    fi
    if dep_is_in_maintenance "$d"; then
        dep_err "$(zpd_msg_up_failed) (maintenance flag still present)"
        return 1
    fi
    return 0
}

# ── Migration execution with honest state tracking ───────────────────────────
# Sets the global DEP_MIGRATION_STATUS to one of:
#   not_run | none_pending | applied | failed
DEP_MIGRATION_STATUS="not_run"

# dep_run_migrations RELEASE_DIR — run migrate --force from the new release and
# record whether the database actually changed. Artisan's migrate output
# ("Nothing to migrate." / "Migrating:" / "Migrated:" / "DONE") is NOT localized,
# so parsing it is reliable. Returns non-zero only on an actual migration failure.
dep_run_migrations() {
    local rel="$1" out rc
    out="$( cd "$rel" 2>/dev/null && "$ZPD_PHP" artisan migrate --force --no-interaction </dev/null 2>&1 )"; rc=$?
    if [ "$rc" -ne 0 ]; then
        DEP_MIGRATION_STATUS="failed"
        # Show the tail of the (redacted) output so operators see the cause.
        printf '%s\n' "$out" | zpd_mask_secrets | tail -n 8 >&2
        return 1
    fi
    if printf '%s' "$out" | grep -qiE 'Nothing to migrate|No migrations? to run'; then
        DEP_MIGRATION_STATUS="none_pending"
    elif printf '%s' "$out" | grep -qiE 'Migrating:|Migrated:|DONE|[0-9]+ms'; then
        DEP_MIGRATION_STATUS="applied"
    else
        # Succeeded but produced no recognisable "ran" markers → nothing changed.
        DEP_MIGRATION_STATUS="none_pending"
    fi
    return 0
}

# ── Bounded CLI health + internal resource checks ────────────────────────────

# dep_cli_health CURRENT_DIR — `php artisan zedproxy:health --json`, bounded by a
# timeout. A timeout OR non-zero exit fails internal readiness; the failing
# component is shown (redacted). Never `|| true`.
dep_cli_health() {
    local cur="$1" out rc
    out="$( cd "$cur" 2>/dev/null && timeout "${ZPD_HEALTH_CLI_TIMEOUT}s" "$ZPD_PHP" artisan zedproxy:health --json </dev/null 2>&1 )"; rc=$?
    if [ "$rc" -eq 124 ]; then
        dep_err "internal health: zedproxy:health timed out after ${ZPD_HEALTH_CLI_TIMEOUT}s"
        return 1
    fi
    if [ "$rc" -ne 0 ]; then
        dep_err "internal health: zedproxy:health reported a failure"
        printf '%s\n' "$out" | zpd_mask_secrets | tail -n 8 >&2
        return 1
    fi
    return 0
}

# dep_check_shared_writable SHARED_DIR — shared storage exists and is writable.
dep_check_shared_writable() {
    local shared="$1" probe
    [ -d "${shared}/storage" ] || { dep_err "readiness: shared storage missing"; return 1; }
    probe="${shared}/storage/.zpd-write-test.$$"
    if ( : > "$probe" ) 2>/dev/null; then rm -f "$probe"; return 0; fi
    dep_err "readiness: shared storage not writable"
    return 1
}

# dep_check_release_files CURRENT_DIR — required application + Vite build files.
dep_check_release_files() {
    local cur="$1"
    [ -f "${cur}/artisan" ]                 || { dep_err "readiness: artisan missing"; return 1; }
    [ -f "${cur}/public/index.php" ]        || { dep_err "readiness: public/index.php missing"; return 1; }
    [ -f "${cur}/public/build/manifest.json" ] || { dep_err "readiness: Vite manifest missing"; return 1; }
    return 0
}

# dep_check_shared_links DIR — .env, storage, and public/storage must be
# SYMLINKS resolving into the shared tree. A release with a private regular
# .env or local storage would run with stale credentials, a different APP_KEY,
# or non-shared uploads — it must never be adopted or activated via the
# compatibility path.
dep_check_shared_links() {
    local d="$1" shared; shared="$(zpd_shared_dir)"
    [ -L "${d}/.env" ] || return 1
    [ "$(readlink -f "${d}/.env" 2>/dev/null)" = "$(readlink -f "${shared}/.env" 2>/dev/null)" ] || return 1
    [ -L "${d}/storage" ] || return 1
    [ "$(readlink -f "${d}/storage" 2>/dev/null)" = "$(readlink -f "${shared}/storage" 2>/dev/null)" ] || return 1
    [ -L "${d}/public/storage" ] || return 1
    [ "$(readlink -f "${d}/public/storage" 2>/dev/null)" = "$(readlink -f "${shared}/storage/app/public" 2>/dev/null)" ] || return 1
    return 0
}

# ── Local loopback health vhost management (install + repair) ─────────────────

# dep_fpm_socket — the PHP-FPM socket path (derived from the running PHP version;
# overridable via ZPD_FPM_SOCK).
dep_fpm_socket() {
    if [ -n "${ZPD_FPM_SOCK:-}" ]; then printf '%s' "$ZPD_FPM_SOCK"; return 0; fi
    local ver; ver="$("$ZPD_PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' </dev/null 2>/dev/null || echo 8.3)"
    printf '/run/php/php%s-fpm-zedproxy.sock' "$ver"
}

# dep_ensure_local_health BASE — create or REPAIR the loopback-only health vhost
# (127.0.0.1:<port> → current/public). Validates with `nginx -t`; restores the
# previous config and returns 1 on validation failure. Idempotent — a config that
# is already correct is left untouched (no reload).
dep_ensure_local_health() {
    local base="$1" root="${2:-${1}/current/public}" conf port sock want
    conf="$(zpd_local_health_conf_path)"
    port="$(zpd_local_health_port)"
    sock="$(dep_fpm_socket)"

    # "Already correct" requires the CURRENT PHP-FPM socket too — a vhost whose
    # listen/root match but whose fastcgi_pass targets an obsolete socket would
    # 502 forever while looking healthy to the validator.
    if [ -f "$conf" ] && zpd_local_health_conf_ok "$conf" "$base" "$port" "$root" \
        && grep -q "fastcgi_pass unix:${sock};" "$conf"; then
        return 0   # already correct
    fi

    dep_log "Repairing local health vhost (${conf} → 127.0.0.1:${port}, root ${root})…"
    [ -f "$conf" ] && cp -a "$conf" "${conf}.zpd-prev" 2>/dev/null || true
    want="$(zpd_local_health_conf_content "$base" "$sock" "$root")"
    mkdir -p "$(dirname "$conf")" 2>/dev/null || true
    printf '%s\n' "$want" > "$conf" 2>/dev/null || { dep_err "could not write ${conf}"; return 1; }
    if ! zpd_local_health_conf_ok "$conf" "$base" "$port" "$root" || ! dep_validate_nginx; then
        dep_err "local health vhost invalid — restoring previous config"
        if [ -f "${conf}.zpd-prev" ]; then cp -a "${conf}.zpd-prev" "$conf" 2>/dev/null || true; else rm -f "$conf" 2>/dev/null || true; fi
        return 1
    fi
    dep_reload_nginx || { dep_err "nginx reload failed after local-health repair"; return 1; }
    return 0
}

# ── Split readiness: internal (maintenance-safe) vs public HTTP ──────────────

# dep_release_verify_mode RELEASE_ID — how strictly this release's identity can
# be verified. Prints one of:
#   strict     — modern manifest with a real git_sha → SHA equality is REQUIRED
#   adopted    — historical release backfilled from observed facts (result=adopted)
#   historical — no usable manifest identity (pre-manifest release)
# A manifest that carries manifest_schema_version (i.e. was written by the
# modern deployer) but LACKS a usable SHA is still STRICT — a modern release
# must never be accepted with a missing SHA.
dep_release_verify_mode() {
    local id="$1" manifest mansha result
    manifest="$(zpd_releases_dir)/${id}/RELEASE_MANIFEST.json"
    if ! zpd_manifest_valid "$manifest"; then printf 'historical'; return 0; fi
    result="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
    mansha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
    if [ "$result" = "adopted" ]; then printf 'adopted'; return 0; fi
    if [ -n "$mansha" ] && [ "$mansha" != "unknown" ]; then printf 'strict'; return 0; fi
    if [ -n "$(zpd_manifest_get "$manifest" manifest_schema_version 2>/dev/null)" ]; then
        printf 'strict'; return 0    # modern manifest missing its SHA → must FAIL strict checks
    fi
    printf 'historical'
}

# dep_verify_internal_release BASE RELEASE_ID SHA
#
# Phase A — everything that does NOT depend on a public HTTP application
# response, so it is safe to run while the app is still in maintenance mode.
#
# Release-identity policy:
#   - NEW activations (SHA argument non-empty) are ALWAYS strict: manifest SHA
#     and expected SHA must both equal the deployed Git HEAD.
#   - Rollback/verification of an EXISTING release (SHA argument empty) uses
#     dep_release_verify_mode: modern manifests stay strict; adopted/historical
#     releases are verified through the compatibility path (directory, files,
#     links, services, health) and never fail SOLELY because a pre-manifest
#     release has no trusted manifest SHA.
dep_verify_internal_release() {
    local base="$1" id="$2" sha="$3"
    local cur manifest headsha mansha mode
    cur="$(zpd_current_link)"
    manifest="$(zpd_releases_dir)/${id}/RELEASE_MANIFEST.json"

    [ -L "$cur" ]                             || { dep_err "internal: current symlink missing"; return 1; }
    [ "$(zpd_current_release)" = "$id" ]      || { dep_err "internal: current != ${id}"; return 1; }
    headsha="$(zpd_git_head_sha "$cur" 2>/dev/null)"
    mode="$(dep_release_verify_mode "$id")"
    if [ -n "$sha" ]; then mode="strict"; fi   # a NEW activation is never relaxed
    case "$mode" in
        strict)
            mansha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
            [ -n "$headsha" ] && [ "$mansha" = "$headsha" ] || { dep_err "internal: manifest SHA != deployed HEAD"; return 1; }
            [ -z "$sha" ] || [ "$sha" = "$headsha" ]  || { dep_err "internal: deployed SHA mismatch"; return 1; }
            ;;
        adopted|historical)
            dep_warn "internal: ${id} verified via ${mode} compatibility path (pre-manifest release)"
            # The compat path must never activate a release whose .env/storage
            # are not links into shared (stale credentials / different APP_KEY).
            dep_check_shared_links "$cur" \
                || { dep_err "internal: ${id} .env/storage are not links into shared/"; return 1; }
            # An ADOPTED manifest records the OBSERVED HEAD at adoption time —
            # if git data exists now and disagrees, the release was tampered
            # with after adoption → fail. A pre-manifest release with no
            # recorded SHA cannot be SHA-verified; identity mismatches between
            # the release id and the observed HEAD are surfaced as warnings
            # (the compat path's protection is files/links/services/health).
            mansha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
            if [ -n "$mansha" ] && [ "$mansha" != "unknown" ] && [ -n "$headsha" ] && [ "$mansha" != "$headsha" ]; then
                dep_err "internal: adopted manifest SHA no longer matches deployed HEAD"; return 1
            fi
            local idsha="${id##*-}"
            if [ -n "$headsha" ] && printf '%s' "$idsha" | grep -qE '^[0-9a-f]{12}$'; then
                case "$headsha" in
                    "$idsha"*) ;;
                    *) dep_warn "internal: release id ${id} does not match deployed HEAD (historical id)" ;;
                esac
            fi
            ;;
    esac

    dep_validate_nginx                        || { dep_err "internal: nginx -t failed"; return 1; }
    zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base"        || { dep_err "internal: Nginx root not on current/"; return 1; }
    zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base"   || { dep_err "internal: Supervisor not on current/"; return 1; }
    dep_supervisor_group_running              || { dep_err "internal: worker group not running"; return 1; }
    zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" || { dep_err "internal: Scheduler not on current/"; return 1; }
    dep_verify_scheduler "$cur"               || { dep_err "internal: schedule:list failed"; return 1; }

    if declare -F zpw_verify_wrappers >/dev/null 2>&1; then
        zpw_verify_wrappers "$base"           || { dep_err "internal: command wrappers not resolving current/"; return 1; }
    fi

    dep_check_pg "$(zpd_shared_dir)/.env"     || { dep_err "internal: PostgreSQL unreachable"; return 1; }
    dep_check_redis "$(zpd_shared_dir)/.env"  || { dep_err "internal: Redis unreachable"; return 1; }
    dep_check_shared_writable "$(zpd_shared_dir)" || return 1
    dep_check_release_files "$cur"            || return 1
    dep_cli_health "$cur"                     || return 1
    return 0
}

# dep_verify_http_release [URL] — Phase B — the public HTTP readiness checks. Must
# only be called AFTER `artisan up` and after maintenance mode is confirmed off.
dep_verify_http_release() {
    dep_health "${1:-$ZPD_HEALTH_URL}"
}

# ── Service reloads ──────────────────────────────────────────────────────────

# All service-control operations run through dep_svc (bounded + stdin from
# /dev/null): a hung systemctl / nginx -t / supervisorctl can never freeze a
# deployment or a rollback.
dep_reload_php_fpm() {
    local svc="$1"
    dep_svc "$ZPD_SYSTEMCTL" reload "$svc" 2>/dev/null || dep_svc "$ZPD_SYSTEMCTL" restart "$svc"
}

dep_validate_nginx() { dep_svc "$ZPD_NGINX" -t; }
dep_reload_nginx()   { dep_svc "$ZPD_SYSTEMCTL" reload nginx; }

dep_restart_workers() {
    dep_svc "$ZPD_SUPERVISORCTL" restart "$(zpd_supervisor_worker_group):*"
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
    # Bounded: a stalled PostgreSQL client must not hang readiness or the doctor.
    timeout "${ZPD_DB_PROBE_TIMEOUT:-15}s" env PGPASSWORD="$pass" \
        "$ZPD_PSQL" -h "$host" -p "$port" -U "$user" -d "$db" -tAc 'SELECT 1' </dev/null >/dev/null 2>&1
}

# dep_check_redis ENV_FILE — Redis connectivity via `redis-cli ping` → PONG.
dep_check_redis() {
    local env_file="$1" host port pass out
    [ -f "$env_file" ] || return 1
    host="$(_dep_env_get "$env_file" REDIS_HOST)"; host="${host:-127.0.0.1}"
    port="$(_dep_env_get "$env_file" REDIS_PORT)"; port="${port:-6379}"
    pass="$(_dep_env_get "$env_file" REDIS_PASSWORD)"
    # Bounded: a stalled Redis client must not hang readiness or the doctor.
    if [ -n "$pass" ] && [ "$pass" != "null" ]; then
        out="$(timeout "${ZPD_DB_PROBE_TIMEOUT:-15}s" "$ZPD_REDIS_CLI" -h "$host" -p "$port" -a "$pass" ping </dev/null 2>/dev/null)"
    else
        out="$(timeout "${ZPD_DB_PROBE_TIMEOUT:-15}s" "$ZPD_REDIS_CLI" -h "$host" -p "$port" ping </dev/null 2>/dev/null)"
    fi
    printf '%s' "$out" | grep -qi 'PONG'
}

# dep_supervisor_group_running — the worker group exists AND every process is
# RUNNING (no FATAL/BACKOFF/STOPPED/EXITED). Uses `supervisorctl status`.
dep_supervisor_group_running() {
    local group out; group="$(zpd_supervisor_worker_group)"
    out="$(dep_svc "$ZPD_SUPERVISORCTL" status "${group}:*" 2>/dev/null)"
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
    zpd_supervisor_ok "$conf" "$base"           || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    dep_svc "$ZPD_SUPERVISORCTL" reread         || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
    dep_svc "$ZPD_SUPERVISORCTL" update         || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
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

# ═════════════════════════════════════════════════════════════════════════════
# Operational-configuration reconciliation (self-healing; EVERY deployment)
# ═════════════════════════════════════════════════════════════════════════════
#
# The existence of a `current` symlink does NOT mean the operational
# configuration is correct: an earlier failed migration can leave the Scheduler,
# Supervisor, Nginx root, wrappers, or health vhost missing/legacy/stale. The
# old flow repaired them only when legacy=1, then VALIDATED them on every later
# update — failing repeatedly with no way to self-heal. This layer reconciles
# every managed component to the canonical atomic layout on every activation:
#
#   snapshot → detect drift → reconcile → validate → reload → verify
#
# and restores the per-release snapshot if reconciliation itself fails.

# dep_snapshot_operational_config RELEASE_ID — snapshot every managed
# operational file under a RELEASE-SCOPED directory (never overwriting another
# deployment's snapshot; a repeated id gets a unique suffix). Root-owned,
# dir 700, files 600. Prints the snapshot dir. No secrets are copied — these are
# service configs, not credential files.
#
# TRANSACTIONAL SCHEDULER SOURCES: reconciliation may modify or delete
# arbitrary discovered cron files (/etc/crontab, other /etc/cron.d/* files) —
# not only the canonical scheduler. EVERY such file is discovered up front,
# deduplicated, and captured here with its exact content, mode, ownership, and
# existence state in a source-to-snapshot map, so a failed transaction can
# restore all of them precisely (and remove files that did not exist). Sources
# are only modified after this complete snapshot has succeeded.
dep_snapshot_operational_config() {
    local id="$1" dir n=0
    dir="$(zpd_snapshots_dir)/${id}"
    while [ -e "$dir" ]; do n=$((n + 1)); dir="$(zpd_snapshots_dir)/${id}.${n}"; done
    mkdir -p "$dir" 2>/dev/null || return 1
    chmod 700 "$dir" 2>/dev/null || true
    chown 0:0 "$dir" 2>/dev/null || true
    local f
    for f in "$(zpd_nginx_conf_path)" "$(zpd_local_health_conf_path)" \
             "$(zpd_supervisor_conf_path)" "$(zpd_scheduler_cron_path)" \
             "$(zpd_deploy_env_file)"; do
        if [ -f "$f" ]; then
            cp -a "$f" "${dir}/$(basename "$f").snap" 2>/dev/null || return 1
            chmod 600 "${dir}/$(basename "$f").snap" 2>/dev/null || true
        fi
    done
    if declare -F zpw_backup_wrappers >/dev/null 2>&1; then
        zpw_backup_wrappers "${dir}/wrappers" >/dev/null 2>&1 || true
    fi
    # Discover + capture every scheduler source reconciliation may touch.
    # sources.map: <index> TAB <absolute path> TAB <mode> TAB <uid:gid> TAB <existed 0|1>
    local base src map n_src=0
    base="$(zpd_base)"
    mkdir -p "${dir}/sched-sources" 2>/dev/null || return 1
    map="${dir}/sched-sources/sources.map"
    : > "$map" || return 1
    chmod 600 "$map" 2>/dev/null || true
    while IFS= read -r src; do
        [ -n "$src" ] || continue
        n_src=$((n_src + 1))
        if [ -f "$src" ]; then
            cp -a "$src" "${dir}/sched-sources/src-${n_src}" 2>/dev/null || return 1
            chmod 600 "${dir}/sched-sources/src-${n_src}" 2>/dev/null || true
            printf '%s\t%s\t%s\t%s\t1\n' "$n_src" "$src" \
                "$(stat -c '%a' "$src" 2>/dev/null)" "$(stat -c '%u:%g' "$src" 2>/dev/null)" >> "$map" || return 1
        else
            printf '%s\t%s\t\t\t0\n' "$n_src" "$src" >> "$map" || return 1
        fi
    done < <(dep_scheduler_scan "$base" | sed -n 's/^OURS //p' | sort -u)
    zpd_write_manifest "${dir}/SNAPSHOT.json" \
        "release_id=${id}" "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "nginx_conf=$(zpd_nginx_conf_path)" "local_health_conf=$(zpd_local_health_conf_path)" \
        "supervisor_conf=$(zpd_supervisor_conf_path)" "scheduler_cron=$(zpd_scheduler_cron_path)" \
        "deploy_env=$(zpd_deploy_env_file)" "scheduler_sources=${n_src}"
    printf '%s' "$dir"
}

# dep_restore_operational_snapshot SNAP_DIR — put back every snapshotted file
# (removing managed files that did not exist at snapshot time), then validate,
# reload Nginx, reread/update Supervisor, and restart the workers so the
# restored configuration is actually in effect.
#
# FAIL-CLOSED: every required restore operation is accumulated — file
# restoration, Nginx validation + reload, Supervisor reread + update, worker
# restart + group RUNNING, scheduler-source restoration (content verified
# against the snapshot), and a bounded schedule:list where an active release
# exists. The function returns non-zero when ANY of them failed, so a caller
# can never report "restored and validated" over services still running a
# partially modified configuration.
dep_restore_operational_snapshot() {
    local dir="$1" rc=0
    [ -d "$dir" ] || { dep_err "restore: snapshot ${dir} missing"; return 1; }
    local f dest
    for dest in "$(zpd_nginx_conf_path)" "$(zpd_local_health_conf_path)" \
                "$(zpd_supervisor_conf_path)" "$(zpd_scheduler_cron_path)"; do
        f="${dir}/$(basename "$dest").snap"
        if [ -f "$f" ]; then
            cp -a "$f" "$dest" 2>/dev/null || { dep_err "restore: could not restore ${dest}"; rc=1; }
        else
            rm -f "$dest" 2>/dev/null || true   # did not exist pre-deploy
        fi
    done
    if declare -F zpw_restore_wrappers >/dev/null 2>&1 && [ -d "${dir}/wrappers" ]; then
        zpw_restore_wrappers "${dir}/wrappers" || { dep_err "restore: wrapper restore failed"; rc=1; }
    fi
    # Restore every scheduler SOURCE the transaction may have modified —
    # content, mode, and ownership exactly (verified against the snapshot);
    # files that did not exist before the transaction are removed. This is what
    # puts a stripped /etc/crontab entry back when a later step fails.
    local map="${dir}/sched-sources/sources.map" idx path mode owner existed
    if [ -f "$map" ]; then
        while IFS=$'\t' read -r idx path mode owner existed; do
            [ -n "$path" ] || continue
            if [ "$existed" = "1" ]; then
                if ! cp "${dir}/sched-sources/src-${idx}" "$path" 2>/dev/null \
                   || ! cmp -s "${dir}/sched-sources/src-${idx}" "$path"; then
                    dep_err "restore: scheduler source ${path} was not restored exactly"
                    rc=1
                    continue
                fi
                [ -n "$mode" ]  && chmod "$mode" "$path" 2>/dev/null || true
                [ -n "$owner" ] && chown "$owner" "$path" 2>/dev/null || true
            else
                rm -f "$path" 2>/dev/null || true
            fi
        done < "$map"
    fi
    dep_validate_nginx || { dep_err "restore: nginx -t failed on restored config"; rc=1; }
    dep_reload_nginx   || { dep_err "restore: nginx reload failed"; rc=1; }
    dep_svc "$ZPD_SUPERVISORCTL" reread >/dev/null 2>&1 || { dep_err "restore: supervisor reread failed"; rc=1; }
    dep_svc "$ZPD_SUPERVISORCTL" update >/dev/null 2>&1 || { dep_err "restore: supervisor update failed"; rc=1; }
    dep_restart_workers || { dep_err "restore: worker restart failed"; rc=1; }
    dep_supervisor_group_running || { dep_err "restore: worker group not RUNNING after restore"; rc=1; }
    # Bounded functional scheduler verification of the RESTORED state.
    if [ -L "$(zpd_current_link)" ]; then
        dep_verify_scheduler "$(zpd_current_link)" \
            || { dep_err "restore: schedule:list failed on the restored state"; rc=1; }
    fi
    return "$rc"
}

# dep_reconcile_nginx BASE — ensure the Nginx root serves <BASE>/current/public.
# Correct config is left untouched; a legacy/stale root is rewritten, verified,
# and `nginx -t`-validated with restore-on-failure (dep_cutover_nginx).
dep_reconcile_nginx() {
    local base="$1" conf; conf="$(zpd_nginx_conf_path)"
    if [ -f "$conf" ] && zpd_nginx_root_ok "$conf" "$base"; then return 0; fi
    dep_log "Reconciling Nginx root → current/public…"
    dep_cutover_nginx "$base"
}

# dep_reconcile_supervisor BASE — ensure the worker program runs
# current/artisan AND that the daemon has actually loaded it. Missing config is
# created; legacy/stale config rewritten. `reread` + `update` run EVEN when the
# file already looks correct — a previous failed reread/update can leave a
# correct file that supervisord never loaded (the worker group then "does not
# exist"), and skipping the reload would make that state permanently
# unrepairable.
dep_reconcile_supervisor() {
    local base="$1" conf; conf="$(zpd_supervisor_conf_path)"
    if [ -f "$conf" ] && zpd_supervisor_ok "$conf" "$base"; then
        dep_svc "$ZPD_SUPERVISORCTL" reread || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
        dep_svc "$ZPD_SUPERVISORCTL" update || { dep_err "$(zpd_msg_supervisor_failed)"; return 1; }
        return 0
    fi
    dep_log "Reconciling Supervisor worker config → current/artisan…"
    dep_cutover_supervisor "$base"
}

# dep_scheduler_scan BASE — inspect EVERY cron source for Laravel scheduler
# entries. Prints classified, DEDUPLICATED lines (a file with multiple matching
# scheduler lines appears exactly once per classification, so each source is
# processed only once):
#   OURS <file>        — a ZedProxy schedule:run entry in a non-canonical file
#   FOREIGN <file>     — a schedule:run entry NOT executing BASE (unrelated app)
# The canonical file itself is not printed (its content is reconciled directly).
dep_scheduler_scan() {
    local base="$1" src line
    {
        while IFS= read -r src; do
            [ -n "$src" ] || continue
            [ "$src" = "$(zpd_scheduler_cron_path)" ] && continue
            while IFS= read -r line; do
                [ -n "$line" ] || continue
                if zpd_scheduler_line_is_ours "$line" "$base"; then
                    printf 'OURS %s\n' "$src"
                else
                    printf 'FOREIGN %s\n' "$src"
                fi
            done < <(zpd_scheduler_lines "$src")
        done < <(zpd_scheduler_sources)
    } | sort -u
    return 0
}

# dep_scheduler_scan_noncron BASE — bounded, READ-ONLY discovery of ZedProxy
# scheduler invocations OUTSIDE cron: systemd .timer/.service units and
# Supervisor program definitions. These are UNMANAGED homes — the reconciler
# never edits them automatically; any hit is a conflict that must fail BEFORE
# modification. Prints classified lines:
#   SYSTEMD <unit-file> (<enabled-state>/<active-state>)
#   SUPERVISOR <conf-file>
dep_scheduler_scan_noncron() {
    local base="$1" f re en ac
    re="$(zpd_scheduler_ours_re "$base")"
    # systemd units (.timer + .service): ExecStart / command lines.
    if [ -d "$(zpd_systemd_unit_dir)" ]; then
        for f in "$(zpd_systemd_unit_dir)"/*.timer "$(zpd_systemd_unit_dir)"/*.service; do
            [ -f "$f" ] || continue
            if grep -E 'artisan[[:space:]]+schedule:run' "$f" 2>/dev/null | grep -vE '^[[:space:]]*#' | grep -qE "$re"; then
                en="$(dep_svc "$ZPD_SYSTEMCTL" is-enabled "$(basename "$f")" 2>/dev/null | head -n1 || true)"
                ac="$(dep_svc "$ZPD_SYSTEMCTL" is-active "$(basename "$f")" 2>/dev/null | head -n1 || true)"
                printf 'SYSTEMD %s (%s/%s)\n' "$f" "${en:-unknown}" "${ac:-unknown}"
            fi
        done
    fi
    # Supervisor programs (any command= executing our schedule:run).
    if [ -d "$(zpd_supervisor_scan_dir)" ]; then
        for f in "$(zpd_supervisor_scan_dir)"/*.conf; do
            [ -f "$f" ] || continue
            if grep -E '^[[:space:]]*command[[:space:]]*=' "$f" 2>/dev/null | grep -qE "$re"; then
                printf 'SUPERVISOR %s\n' "$f"
            fi
        done
    fi
    return 0
}

# dep_scheduler_single_source_ok BASE — after reconciliation EXACTLY ONE active
# ZedProxy scheduler source may exist: the canonical cron file. Any remaining
# cron duplicate or non-cron (systemd/supervisor) source fails.
dep_scheduler_single_source_ok() {
    local base="$1"
    zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" || return 1
    dep_scheduler_scan "$base" | grep -q '^OURS ' && return 1
    [ -n "$(dep_scheduler_scan_noncron "$base")" ] && return 1
    return 0
}

# dep_reconcile_scheduler BASE — make /etc/cron.d/zedproxy-scheduler the ONLY
# source executing the ZedProxy scheduler:
#   1. Discover every schedule:run entry across cron.d, /etc/crontab, and the
#      user spool crontabs.
#   2. A ZedProxy entry inside a USER SPOOL crontab is unmanaged and cannot be
#      edited safely as a file → FAIL with a clear conflict diagnostic instead
#      of blindly deleting user content.
#   3. Remove ZedProxy schedule:run lines from other managed system files
#      (backing each up; unrelated cron jobs are preserved line-by-line).
#   4. Write the canonical single-entry cron file atomically (root:root, 644).
#   5. Verify the file AND `php current/artisan schedule:list` (bounded).
# Never leaves two sources executing the same jobs simultaneously.
dep_reconcile_scheduler() {
    local base="$1" entry kind src spool
    spool="$(zpd_cron_spool_dir)"

    # 1+2: conflict scan BEFORE any modification. ZedProxy entries in user
    # spool crontabs, systemd .timer/.service units, or Supervisor programs are
    # UNMANAGED/AMBIGUOUS homes — abort with a clear diagnostic instead of
    # editing them (or leaving a second active scheduler running).
    local conflict
    conflict="$(dep_scheduler_scan_noncron "$base")"
    if [ -n "$conflict" ]; then
        printf '%s\n' "$conflict" | while IFS= read -r entry; do
            dep_err "scheduler: unmanaged scheduler source found: ${entry}"
        done
        dep_err "$(zpd_msg_sched_conflict)"
        return 1
    fi
    while IFS= read -r entry; do
        [ -n "$entry" ] || continue
        kind="${entry%% *}"; src="${entry#* }"
        if [ "$kind" = "OURS" ]; then
            case "$src" in
                "$spool"/*)
                    dep_err "scheduler: unmanaged ZedProxy scheduler entry in user crontab ${src}"
                    dep_err "$(zpd_msg_sched_conflict)"
                    return 1
                    ;;
            esac
        fi
    done < <(dep_scheduler_scan "$base")

    # 3: strip our schedule:run lines from other SYSTEM cron files. Each source
    #    file is processed exactly ONCE (the scan is deduplicated) even when it
    #    contains multiple matching lines; every unrelated line is preserved.
    #    The per-deployment snapshot (sched-sources map) is the TRANSACTIONAL
    #    backup — no unmanaged side files are created. The removal pattern is
    #    the SAME ERE the classifier uses, passed via the environment so awk
    #    never reinterprets backslashes.
    local ours_re; ours_re="$(zpd_scheduler_ours_re "$base")"
    while IFS= read -r src; do
        [ -n "$src" ] || continue
        case "$src" in "$spool"/*) continue ;; esac
        dep_log "Removing duplicate ZedProxy scheduler entr(y/ies) from ${src}…"
        local tmp; tmp="$(mktemp)" || return 1
        ZPD_OURS_RE="$ours_re" awk '!($0 ~ ENVIRON["ZPD_OURS_RE"] && $0 !~ /^[ \t]*#/)' \
            "$src" > "$tmp" || { rm -f "$tmp"; return 1; }
        if [ -s "$tmp" ] || [ "$src" = "$(zpd_etc_crontab)" ]; then
            chmod --reference="$src" "$tmp" 2>/dev/null || chmod 644 "$tmp" 2>/dev/null || true
            mv -f "$tmp" "$src" || { rm -f "$tmp"; return 1; }
        else
            # File contained ONLY our entries → remove it entirely.
            rm -f "$tmp"
            rm -f "$src" 2>/dev/null || true
        fi
    done < <(dep_scheduler_scan "$base" | sed -n 's/^OURS //p' | sort -u)

    # 4: canonical single-entry file (atomic write + strict verification).
    dep_cutover_scheduler "$base" || return 1

    # 5: bounded functional verification from the active release.
    if [ -L "$(zpd_current_link)" ]; then
        dep_verify_scheduler "$(zpd_current_link)" \
            || { dep_err "scheduler: schedule:list failed after reconciliation"; return 1; }
    fi

    # 6: EXACTLY ONE active ZedProxy scheduler source must remain.
    dep_scheduler_single_source_ok "$base" \
        || { dep_err "scheduler: more than one active scheduler source remains"; return 1; }
    return 0
}

# dep_reconcile_wrappers BASE — (re)install the stable zedproxy-* wrappers and
# verify they resolve through current/. Self-healing for missing/old wrappers.
dep_reconcile_wrappers() {
    local base="$1"
    declare -F zpw_install_wrappers >/dev/null 2>&1 || return 0
    zpw_install_wrappers || { dep_err "wrappers: install failed"; return 1; }
    if [ -L "$(zpd_current_link)" ]; then
        zpw_verify_wrappers "$base" || { dep_err "wrappers: verification failed"; return 1; }
    fi
    return 0
}

# dep_verify_operational_config BASE — confirm the EFFECTIVE state of every
# managed component after reconciliation (files + runtime), without any public
# HTTP dependency.
dep_verify_operational_config() {
    local base="$1"
    zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base"         || { dep_err "verify: nginx root not on current/"; return 1; }
    zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base"    || { dep_err "verify: supervisor not on current/"; return 1; }
    zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" || { dep_err "verify: scheduler cron not canonical"; return 1; }
    dep_validate_nginx                                         || { dep_err "verify: nginx -t failed"; return 1; }
    return 0
}

# dep_reconcile_operational BASE RELEASE_ID
#
# The unified reconciliation entry point, run on EVERY activation (legacy,
# partially migrated, and fully atomic installs alike). Snapshots first; if any
# component cannot be reconciled the snapshot is restored so the server keeps
# its pre-deployment operational state. Sets DEP_SNAPSHOT_DIR for the caller.
DEP_SNAPSHOT_DIR=""
dep_reconcile_operational() {
    local base="$1" id="$2"
    DEP_SNAPSHOT_DIR="$(dep_snapshot_operational_config "$id")" \
        || { dep_err "reconcile: could not snapshot operational config"; return 1; }
    dep_debug "operational snapshot: ${DEP_SNAPSHOT_DIR}"
    if ! dep_reconcile_nginx "$base" \
        || ! dep_reconcile_supervisor "$base" \
        || ! dep_reconcile_scheduler "$base" \
        || ! dep_reconcile_wrappers "$base" \
        || ! dep_ensure_local_health "$base" \
        || ! dep_verify_operational_config "$base"; then
        dep_err "reconcile: operational reconciliation failed — restoring snapshot"
        dep_restore_operational_snapshot "$DEP_SNAPSHOT_DIR" \
            || dep_err "reconcile: snapshot restore also failed (manual review: ${DEP_SNAPSHOT_DIR})"
        return 1
    fi
    return 0
}

# ── Wrapper backup location (for first-cutover rollback) ─────────────────────
dep_wrapper_backup_dir() { printf '%s/shared/deploy/wrappers.precutover' "$(zpd_base)"; }

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
    dep_stage "maintenance"
    dep_bring_down "$maint_dir"

    # 2. pause workers (stop consuming with the old code)
    dep_svc "$ZPD_SUPERVISORCTL" stop "$(zpd_supervisor_worker_group):*" 2>/dev/null || true

    # 3. migrations (run from the NEW release, which is linked to shared .env).
    #    Records DEP_MIGRATION_STATUS = none_pending|applied|failed so the manifest
    #    and any warning reflect whether the DB actually changed.
    dep_stage "migrate"
    if ! dep_run_migrations "$(zpd_releases_dir)/${id}"; then
        dep_record_failure "$DEP_STAGE" "migration_failed" "dep_run_migrations" "activation: migrations failed"
        dep_err "activation: migrations failed"
        return 30
    fi

    # 4. atomic symlink switch
    dep_stage "switch"
    if ! zpd_switch_current "$id"; then
        dep_record_failure "$DEP_STAGE" "switch_failed" "zpd_switch_current" "activation: symlink switch failed"
        dep_err "activation: symlink switch failed"
        return 31
    fi

    # 4a. On a legacy first cutover, snapshot the pre-existing wrappers so a
    #     failed activation can restore the legacy command set exactly.
    if [ "$legacy" = "1" ] && declare -F zpw_backup_wrappers >/dev/null 2>&1; then
        zpw_backup_wrappers "$(dep_wrapper_backup_dir)" || true
    fi

    # 4b. SELF-HEALING operational reconciliation — runs on EVERY activation
    #     (never gated on legacy=1). A partially migrated install with a
    #     missing/legacy Scheduler cron, stale Supervisor config, wrong Nginx
    #     root, or old wrappers is repaired here instead of failing readiness
    #     forever. On reconciliation failure the per-release snapshot is
    #     restored and the activation fails (→ rollback).
    dep_stage "operational_reconcile"
    dep_reconcile_operational "$base" "$id" \
        || { dep_record_failure "$DEP_STAGE" "reconcile_failed" "dep_reconcile_operational" "activation: operational reconciliation failed"
             dep_err "activation: operational reconciliation failed"; return 31; }

    # 5-7. reload PHP-FPM, validate + reload Nginx
    dep_stage "service_reload"
    dep_reload_php_fpm "$fpm" || { dep_record_failure "$DEP_STAGE" "service_reload_failed" "dep_reload_php_fpm" "activation: php-fpm reload failed"
                                   dep_err "activation: php-fpm reload failed"; return 31; }
    dep_validate_nginx        || { dep_record_failure "$DEP_STAGE" "service_reload_failed" "dep_validate_nginx" "activation: nginx -t failed"
                                   dep_err "activation: nginx -t failed"; return 31; }
    dep_reload_nginx          || { dep_record_failure "$DEP_STAGE" "service_reload_failed" "dep_reload_nginx" "activation: nginx reload failed"
                                   dep_err "activation: nginx reload failed"; return 31; }

    # 8. restart workers against the new release
    dep_restart_workers || { dep_record_failure "$DEP_STAGE" "worker_restart_failed" "dep_restart_workers" "activation: worker restart failed"
                             dep_err "activation: worker restart failed"; return 31; }

    # ── Phase A — INTERNAL readiness (still in maintenance mode) ─────────────
    # Only checks that do NOT depend on a public HTTP application response, so a
    # maintenance 503 can never be mistaken for an unhealthy release.
    dep_stage "internal_readiness"
    dep_log "Verifying internal readiness (maintenance mode)…"
    if ! dep_verify_internal_release "$base" "$id" "$sha"; then
        dep_record_failure "$DEP_STAGE" "internal_readiness_failed" "dep_verify_internal_release" "activation: internal readiness failed"
        return 31
    fi

    # ── Bring the new release online, then verify maintenance is actually off ─
    dep_stage "bring_up"
    dep_log "Bringing the new release online…"
    if ! dep_bring_up "$current_dir"; then
        dep_record_failure "$DEP_STAGE" "maintenance_exit_failed" "dep_bring_up" "activation: release could not exit maintenance"
        return 31
    fi

    # ── Phase B — PUBLIC HTTP readiness (application is now live) ────────────
    dep_stage "http_readiness"
    dep_log "Verifying public HTTP readiness…"
    if ! dep_verify_http_release "$ZPD_HEALTH_URL"; then
        dep_record_failure "$DEP_STAGE" "http_readiness_failed" "dep_verify_http_release" "activation: public HTTP readiness failed"
        # Fence the failed release again so it does not keep serving during the
        # rollback the caller is about to perform.
        dep_bring_down "$current_dir"
        return 31
    fi
    return 0
}

# dep_first_cutover_rollback BASE PHP_FPM_SVC
#
# Restore the legacy application after a FAILED first legacy→release cutover:
# put back the snapshotted Nginx/Supervisor/scheduler/local-health config, drop
# the `current` symlink (so Nginx serves the legacy root again), and bring the
# legacy app out of maintenance. FAIL-CLOSED: every requirement — Nginx
# validation + reload, Supervisor reread/update (bounded), worker restart +
# group RUNNING, maintenance actually off, CLI health, and HTTP readiness over
# a LEGACY loopback health target — must pass, or the rollback returns
# non-zero. The loopback vhost is repointed at <BASE>/public BEFORE any HTTP
# check: after `current` is removed, a current/public health root would be a
# dangling target and the check would be meaningless.
dep_first_cutover_rollback() {
    local base="$1" fpm="$2"
    zpd_restore_legacy_rollback || dep_warn "no legacy snapshot to restore"
    # Restore the pre-cutover command wrappers so the server keeps whatever
    # zedproxy-* commands it had before the failed migration.
    if declare -F zpw_restore_wrappers >/dev/null 2>&1 && [ -d "$(dep_wrapper_backup_dir)" ]; then
        zpw_restore_wrappers "$(dep_wrapper_backup_dir)" || dep_warn "wrapper restore failed"
    fi
    rm -f "$(zpd_current_link)" 2>/dev/null || true
    # Verified loopback health target for the RESTORED LEGACY app (root
    # <base>/public — never current/public once `current` is gone). If the
    # legacy snapshot restored its own (legacy-rooted) vhost this is a no-op.
    if ! zpd_local_health_conf_ok "$(zpd_local_health_conf_path)" "$base" "$(zpd_local_health_port)" "${base}/public"; then
        dep_ensure_local_health "$base" "${base}/public" \
            || { dep_err "rollback: could not provide a legacy loopback health target"; return 1; }
    fi
    dep_reload_php_fpm "$fpm"   || { dep_err "rollback: php-fpm reload failed"; return 1; }
    dep_validate_nginx          || { dep_err "rollback: nginx -t failed on restored config"; return 1; }
    dep_reload_nginx            || { dep_err "rollback: nginx reload failed"; return 1; }
    dep_svc "$ZPD_SUPERVISORCTL" reread >/dev/null 2>&1 \
                                || { dep_err "rollback: supervisor reread failed"; return 1; }
    dep_svc "$ZPD_SUPERVISORCTL" update >/dev/null 2>&1 \
                                || { dep_err "rollback: supervisor update failed"; return 1; }
    dep_restart_workers         || { dep_err "rollback: worker restart failed"; return 1; }
    dep_supervisor_group_running || { dep_err "rollback: worker group not RUNNING"; return 1; }
    # Bring the legacy app up BEFORE any HTTP check, then verify maintenance is
    # actually off (a rollback is not healthy just because the symlink dropped).
    dep_bring_up "$base"        || { dep_err "rollback: legacy app could not exit maintenance"; return 1; }
    dep_cli_health "$base"      || { dep_err "rollback: legacy CLI health failed"; return 1; }
    dep_health "$ZPD_HEALTH_URL"
}

# ── Automatic rollback ───────────────────────────────────────────────────────

# dep_rollback_code PREV_ID PHP_FPM_SVC — restore the previous release and verify
# it is ACTUALLY healthy. Correct order: switch → reload → restart workers →
# `artisan up` (required) → confirm maintenance off → INTERNAL readiness → HTTP
# readiness. Returns 0 only when the previous release passes every check — never
# just because the symlink switched.
#
# The SWITCH and the READINESS outcomes are recorded SEPARATELY (a symlink that
# switched back with failed readiness is a materially different state than a
# switch that never happened) so failure finalization can report the truth.
DEP_ROLLBACK_SWITCH="not_available"
DEP_ROLLBACK_RECONCILE="not_available"
DEP_ROLLBACK_READY="not_available"
dep_rollback_code() {
    local prev="$1" fpm="$2" base; base="$(zpd_base)"
    DEP_ROLLBACK_SWITCH="not_available"; DEP_ROLLBACK_RECONCILE="not_available"; DEP_ROLLBACK_READY="not_available"
    [ -n "$prev" ] || { dep_err "rollback: no previous release"; return 1; }
    dep_stage "rollback_switch"
    zpd_switch_current "$prev" || { DEP_ROLLBACK_SWITCH="failed"; dep_err "rollback: switch back failed"; return 1; }
    DEP_ROLLBACK_SWITCH="success"
    dep_stage "rollback_reconcile"
    DEP_ROLLBACK_RECONCILE="success"
    dep_reload_php_fpm "$fpm" || { DEP_ROLLBACK_RECONCILE="failed"; dep_warn "rollback: php-fpm reload failed"; }
    dep_reload_nginx          || { DEP_ROLLBACK_RECONCILE="failed"; dep_warn "rollback: nginx reload failed"; }
    dep_restart_workers       || { DEP_ROLLBACK_RECONCILE="failed"; dep_warn "rollback: worker restart failed"; }
    dep_stage "rollback_readiness"
    DEP_ROLLBACK_READY="failed"
    dep_bring_up "$(zpd_current_link)" || { dep_err "rollback: previous release could not exit maintenance"; return 1; }
    dep_verify_internal_release "$base" "$prev" "" || { dep_err "rollback: previous release failed internal readiness"; return 1; }
    dep_verify_http_release "$ZPD_HEALTH_URL"      || { dep_err "rollback: previous release failed HTTP readiness"; return 1; }
    DEP_ROLLBACK_READY="success"
    return 0
}

# ═════════════════════════════════════════════════════════════════════════════
# Historical-release adoption, stale finalization, and state reconciliation
# ═════════════════════════════════════════════════════════════════════════════

# dep_adopt_current_release — backfill an `adopted` manifest for the ACTIVE
# release when its manifest is missing/incomplete (a release that predates the
# manifest system). Facts are OBSERVED, never invented:
#   - the directory must resolve inside releases/ and contain the Laravel files
#   - .env/storage must link into shared
#   - git HEAD/ref/origin are read where available; a SHA is NEVER fabricated —
#     without git metadata the manifest records git_sha=unknown
#   - the running application must pass bounded CLI health (an unhealthy release
#     is never marked adopted)
# A valid modern manifest is never overwritten. Idempotent; best-effort (a
# failed adoption warns and leaves compatibility verification to handle it).
dep_adopt_current_release() {
    local cur id dir manifest
    cur="$(zpd_current_link)"
    [ -L "$cur" ] || return 0
    id="$(zpd_current_release)"; [ -n "$id" ] || return 0
    dir="$(zpd_releases_dir)/${id}"
    manifest="${dir}/RELEASE_MANIFEST.json"

    # Only adopt when there is no usable modern identity.
    local mode; mode="$(dep_release_verify_mode "$id")"
    [ "$mode" = "historical" ] || return 0

    # Canonicalize the ACTIVE SYMLINK ITSELF (not a reconstructed path with the
    # same basename): the resolved `current` target must be exactly the
    # selected directory under releases/. Otherwise metadata could be adopted
    # for code that is not actually running.
    local canon releases_canon
    canon="$(readlink -f "$cur" 2>/dev/null)" || { dep_warn "adopt: current target unreadable"; return 0; }
    releases_canon="$(cd "$(zpd_releases_dir)" 2>/dev/null && pwd -P)" || return 0
    [ "$canon" = "${releases_canon}/${id}" ] \
        || { dep_warn "adopt: current resolves to ${canon}, not releases/${id} — not adopting"; return 0; }

    [ -f "${dir}/artisan" ] && [ -f "${dir}/public/index.php" ] \
        || { dep_warn "adopt: ${id} lacks required Laravel files"; return 0; }
    # .env / storage / public/storage must be SYMLINKS resolving into shared/ —
    # a private regular .env (different APP_KEY, stale credentials) or local
    # storage tree disqualifies the release from adoption.
    dep_check_shared_links "$dir" \
        || { dep_warn "adopt: ${id} .env/storage are not links into shared/ — not adopting"; return 0; }

    # Never mark an unhealthy release adopted.
    dep_cli_health "$dir" >/dev/null 2>&1 \
        || { dep_warn "adopt: ${id} failed CLI health — not adopting"; return 0; }

    # Observe git facts (never invent). The release-id suffix is used only when
    # it unambiguously matches the observed HEAD.
    local headsha ref origin idsha
    headsha="$(zpd_git_head_sha "$dir" 2>/dev/null)"
    ref="$(timeout "${ZPD_PROBE_TIMEOUT}s" "$ZPD_GIT" -C "$dir" rev-parse --abbrev-ref HEAD </dev/null 2>/dev/null || true)"
    [ "$ref" = "HEAD" ] && ref=""   # detached — no branch fact to record
    origin="$(zpd_git_origin_of "$dir" 2>/dev/null || true)"
    idsha="${id##*-}"
    if [ -z "$headsha" ] && printf '%s' "$idsha" | grep -qE '^[0-9a-f]{12}$'; then
        # No object database — a 12-char id prefix alone cannot be verified, so
        # record unknown rather than inventing a SHA.
        headsha=""
    fi
    if [ -n "$headsha" ] && printf '%s' "$idsha" | grep -qE '^[0-9a-f]{12}$'; then
        case "$headsha" in
            "$idsha"*) ;;
            *) dep_warn "adopt: ${id} git HEAD does not match its release id — recording observed HEAD"; ;;
        esac
    fi

    zpd_write_manifest "$manifest" \
        "release_id=${id}" \
        "git_sha=${headsha:-unknown}" \
        "git_ref=${ref:-unknown}" \
        "repo_url=${origin:-unknown}" \
        "result=adopted" \
        "health=ok" \
        "migration_status=unknown" \
        "adopted_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "manifest_schema_version=$(zpd_manifest_schema_version)" \
        || { dep_warn "adopt: could not write adopted manifest for ${id}"; return 0; }
    dep_log "$(zpd_msg_adopted)"
    return 0
}

# dep_finalize_stale_releases — any NON-ACTIVE release still marked `activating`
# is a deployment that died before finalization (e.g. rollback verification
# failed and the old code returned without writing failure metadata). Finalize
# it as failed/stale so no attempted release stays `activating` forever. The
# directory, logs, and manifest are preserved for diagnostics.
dep_finalize_stale_releases() {
    local active rel manifest result
    active="$(zpd_current_release)"
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        [ "$rel" = "$active" ] && continue
        manifest="$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json"
        result="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
        [ "$result" = "activating" ] || continue
        dep_warn "finalizing stale attempted release ${rel} (was left 'activating')"
        zpd_write_manifest "$manifest" \
            "release_id=${rel}" \
            "git_sha=$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)" \
            "result=failed" \
            "failure_stage=stale_interrupted" \
            "failure_reason_code=finalized_by_reconciliation" \
            "migration_status=$(zpd_manifest_get "$manifest" migration_status 2>/dev/null || echo unknown)" \
            "active_release_after_failure=${active}" \
            "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            "manifest_schema_version=$(zpd_manifest_schema_version)" \
            || dep_warn "could not finalize stale release ${rel}"
    done < <(zpd_list_releases)
    return 0
}

# dep_reconcile_state [RESULT] — repair the central deployment state file from
# the PRIMARY fact (the `current` symlink) plus the active release's manifest,
# falling back to observed git metadata for historical releases. Never claims
# an attempted failed release is active. RESULT overrides the recorded result
# (e.g. `success` after activation, `failed` after a failed attempt); without
# it the manifest's own result is used, or `recovered` when reconstructing.
dep_reconcile_state() {
    local override="${1:-}"
    local active manifest sha ref repo result mig source="manifest"
    active="$(zpd_current_release)"
    if [ -z "$active" ]; then
        zpd_write_manifest "$(zpd_state_file)" \
            "active_release=none" "result=${override:-unknown}" \
            "state_repaired_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        return 0
    fi
    manifest="$(zpd_releases_dir)/${active}/RELEASE_MANIFEST.json"
    sha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
    ref="$(zpd_manifest_get "$manifest" git_ref 2>/dev/null)"
    repo="$(zpd_manifest_get "$manifest" repo_url 2>/dev/null)"
    result="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
    mig="$(zpd_manifest_get "$manifest" migration_status 2>/dev/null)"
    # Fall back to OBSERVED git facts when the manifest is missing/incomplete.
    if [ -z "$sha" ] || [ "$sha" = "unknown" ]; then
        local observed; observed="$(zpd_git_head_sha "$(zpd_releases_dir)/${active}" 2>/dev/null)"
        if [ -n "$observed" ]; then sha="$observed"; source="observed_git"; fi
    fi
    [ -n "$repo" ] || { repo="$(zpd_git_origin_of "$(zpd_releases_dir)/${active}" 2>/dev/null || true)"; }
    [ -n "$result" ] || { result="recovered"; source="observed_git"; }
    zpd_write_manifest "$(zpd_state_file)" \
        "active_release=${active}" \
        "previous_release=$(zpd_previous_release)" \
        "git_sha=${sha:-unknown}" \
        "git_ref=${ref:-unknown}" \
        "repo_url=${repo:-unknown}" \
        "result=${override:-$result}" \
        "migration_status=${mig:-unknown}" \
        "state_source=${source}" \
        "state_repaired_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

# dep_finalize_failed_release MANIFEST ID SHA RC ROLLBACK_TARGET ROLLBACK_RESULT \
#                             ORIG_STAGE ORIG_REASON ORIG_INVARIANT ORIG_MESSAGE
#
# Write the FINAL manifest for a failed attempt — called on EVERY failure path,
# BEFORE returning, regardless of rollback outcome. A completed failed
# deployment must never remain `activating`. The ORIGINAL failure context is
# passed EXPLICITLY (captured before any rollback) — never read from the
# mutable DEP_STAGE, which has moved on through the rollback stages by now.
dep_finalize_failed_release() {
    local manifest="$1" id="$2" sha="$3" rc="$4" rb_target="$5" rb_result="$6"
    local orig_stage="${7:-unknown}" orig_reason="${8:-}" orig_inv="${9:-}" orig_msg="${10:-}"
    local mig_state="$DEP_MIGRATION_STATUS"
    if [ -z "$orig_reason" ]; then
        case "$rc" in
            30) orig_reason="migration_failed" ;;
            31) orig_reason="readiness_failed" ;;
            *)  orig_reason="activation_failed" ;;
        esac
    fi
    [ "$rc" -eq 30 ] && mig_state="failed"
    zpd_write_manifest "$manifest" \
        "release_id=${id}" \
        "git_sha=${sha}" \
        "result=failed" \
        "failure_stage=${orig_stage}" \
        "failure_reason_code=${orig_reason}" \
        "original_failure_stage=${orig_stage}" \
        "original_failure_reason_code=${orig_reason}" \
        "original_failure_invariant=${orig_inv:-unknown}" \
        "original_failure_message=${orig_msg:-}" \
        "migration_status=${mig_state}" \
        "rollback_target=${rb_target:-none}" \
        "rollback_result=${rb_result}" \
        "rollback_stage=${DEP_STAGE}" \
        "rollback_switch=${DEP_ROLLBACK_SWITCH}" \
        "rollback_reconciliation=${DEP_ROLLBACK_RECONCILE}" \
        "rollback_readiness=${DEP_ROLLBACK_READY}" \
        "active_release_after_failure=$(zpd_current_release)" \
        "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "manifest_schema_version=$(zpd_manifest_schema_version)"
}

# dep_failure_bundle — bounded, best-effort redacted diagnostic bundle after a
# failed deployment. Prints the bundle path. A diagnostics failure only warns —
# it must never replace or mask the original deployment error.
dep_failure_bundle() {
    if ! declare -F zdr_bundle >/dev/null 2>&1; then
        # shellcheck disable=SC1090
        [ -f "${_DEP_DIR}/doctor.sh" ] && source "${_DEP_DIR}/doctor.sh" 2>/dev/null || true
    fi
    declare -F zdr_bundle >/dev/null 2>&1 || { dep_warn "diagnostics: doctor unavailable"; return 0; }
    local out_file; out_file="$(mktemp 2>/dev/null || echo /tmp/zpd-bundle.$$)"
    zdr_bundle > "$out_file" 2>/dev/null &
    local pid=$!
    if dep_wait_bounded "$pid" "$ZPD_DOCTOR_TIMEOUT"; then
        local path; path="$(tail -n1 "$out_file" 2>/dev/null)"
        if [ -n "$path" ] && [ -f "$path" ]; then
            dep_log "$(zpd_msg_doctor_bundle)"
            dep_log "  ${path}"
        else
            dep_warn "diagnostics: bundle was not produced"
        fi
    else
        dep_warn "diagnostics: bundle generation exceeded ${ZPD_DOCTOR_TIMEOUT}s — skipped"
    fi
    rm -f "$out_file"
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

# _dep_on_interrupt — INT/TERM handler: record the stage, bring the active
# release out of maintenance, restart workers, and exit non-zero (never success).
# All recovery actions are individually bounded so cleanup cannot itself hang.
_dep_on_interrupt() {
    local st=$?
    dep_err "deployment interrupted during stage: ${DEP_STAGE}"
    local cur; cur="$(zpd_current_link)"
    if [ -L "$cur" ]; then
        ( cd "$cur" 2>/dev/null && timeout 15s "$ZPD_PHP" artisan up </dev/null >/dev/null 2>&1 ) || dep_warn "interrupt: could not exit maintenance"
    fi
    timeout 30s "$ZPD_SUPERVISORCTL" start "$(zpd_supervisor_worker_group):*" </dev/null >/dev/null 2>&1 || dep_warn "interrupt: could not restart workers"
    [ "$st" -ne 0 ] || st=130
    exit "$st"
}

dep_main() {
    local base shared releases lockfile fpm ver legacy=0
    trap '_dep_on_interrupt' INT TERM
    DEP_STAGE="init"; DEP_STAGE_TS=0
    DEP_MIGRATION_STATUS="not_run"
    DEP_ROLLBACK_SWITCH="not_available"; DEP_ROLLBACK_RECONCILE="not_available"; DEP_ROLLBACK_READY="not_available"
    DEP_ORIG_FAILURE_STAGE=""; DEP_ORIG_FAILURE_REASON=""
    DEP_ORIG_FAILURE_INVARIANT=""; DEP_ORIG_FAILURE_MESSAGE=""
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

    dep_stage "preflight"
    dep_log "Preflight…"
    dep_preflight "$shared" || { dep_fail "preflight" "preflight_failed" "preflight checks failed — nothing changed"; return 1; }

    # ── Historical-state repair BEFORE the new deployment starts ─────────────
    # Adopt the active release if it predates the manifest system (backfilled
    # from observed facts — a rollback to it must be verifiable), finalize any
    # stale release still marked `activating` from a deployment that died
    # unfinalized, and repair the central state file from the current symlink.
    DEP_STAGE_PREVIOUS="$(zpd_current_release)"
    dep_adopt_current_release
    dep_finalize_stale_releases
    dep_reconcile_state >/dev/null 2>&1 || dep_warn "state reconciliation failed (continuing)"

    # ── Resolve repository + ref + exact commit SHA BEFORE any backup/migration.
    # The repository source is resolved by precedence (explicit → deploy.env →
    # active release origin → legacy origin → public default) and can NEVER be
    # "." or the caller's working directory.
    dep_stage "resolve"
    local repo ref sha errfile
    errfile="$(mktemp 2>/dev/null || echo /tmp/zpd-git.$$)"
    repo="$(zpd_resolve_repo_url)" || { rm -f "$errfile"; dep_fail "resolve" "repo_unresolvable" "$(zpd_msg_no_repo)"; return 1; }
    ref="${ZPD_REF:-$(zpd_default_ref)}"

    dep_log "Resolving ${ref} from repository…"
    sha="$(zpd_resolve_sha "$repo" "$ref" "$errfile")" || {
        zpd_redact_file "$errfile" >&2
        rm -f "$errfile"
        dep_fail "resolve" "ref_unresolvable" "$(zpd_msg_git_fetch_failed)"
        return 1
    }

    local id rel_dir current_before manifest ts_start
    id="$(zpd_release_id "$sha")" || { rm -f "$errfile"; dep_fail "resolve" "release_id_failed" "could not derive release id from resolved SHA"; return 1; }
    rel_dir="${releases}/${id}"
    current_before="$(zpd_current_release)"
    ts_start="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    manifest="${rel_dir}/RELEASE_MANIFEST.json"
    DEP_STAGE_RELEASE="$id"
    DEP_STAGE_PREVIOUS="$current_before"

    dep_stage "backup"
    dep_log "Backing up .env + database…"
    dep_backup_env "${shared}/.env" "$(zpd_backup_dir)/${id}" \
        || { rm -f "$errfile"; dep_fail "backup" "env_backup_failed" "env backup failed"; return 1; }
    if ! dep_backup_database "$(zpd_backup_dir)/${id}/db.dump" "${shared}/.env"; then
        rm -f "$errfile"
        dep_fail "backup" "db_backup_failed" "database backup failed — aborting (nothing changed)"
        return 1
    fi

    dep_stage "clone"
    dep_log "Fetching ${ref} (${sha:0:12}) into ${rel_dir}…"
    if ! zpd_git_clone_ref "$repo" "$ref" "$rel_dir" "$errfile"; then
        zpd_redact_file "$errfile" >&2
        rm -rf "$rel_dir"; rm -f "$errfile"
        dep_fail "clone" "clone_failed" "$(zpd_msg_git_fetch_failed)"
        return 1
    fi

    # Verify the EXACT commit was checked out (the manifest must describe the
    # code that is actually deployed).
    local got; got="$(zpd_git_head_sha "$rel_dir")"
    if [ "$got" != "$sha" ]; then
        rm -rf "$rel_dir"; rm -f "$errfile"
        dep_fail "clone" "sha_verification_failed" "deployed commit ${got:-<none>} does not match resolved ${sha} — aborting"
        return 1
    fi
    rm -f "$errfile"

    dep_stage "link_shared"
    zpd_link_shared "$rel_dir" "$shared" \
        || { rm -rf "$rel_dir"; dep_fail "link_shared" "link_shared_failed" "linking shared .env/storage failed"; return 1; }

    dep_stage "build"
    dep_log "Building release…"
    if ! dep_build "$rel_dir"; then
        zpd_write_manifest "$manifest" "release_id=${id}" "git_sha=${sha}" "result=failed" \
            "failure_stage=build" "failure_reason_code=build_failed" "started_at=${ts_start}" \
            "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" "manifest_schema_version=$(zpd_manifest_schema_version)"
        mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
        dep_fail "build" "build_failed" "build failed — release marked failed, current untouched"
        return 1
    fi
    dep_stage "smoke"
    if ! dep_smoke "$rel_dir"; then
        zpd_write_manifest "$manifest" "release_id=${id}" "git_sha=${sha}" "result=failed" \
            "failure_stage=smoke" "failure_reason_code=smoke_failed" "started_at=${ts_start}" \
            "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" "manifest_schema_version=$(zpd_manifest_schema_version)"
        mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
        dep_fail "smoke" "smoke_failed" "smoke test failed — release marked failed, current untouched"
        return 1
    fi

    # Record the RESOLVED full commit SHA, the repository, the requested ref, and
    # the exact toolchain versions. Metadata collection is bounded + logged so it
    # can never make the terminal appear frozen or block activation.
    local -a ver_pairs=()
    local _meta_file; _meta_file="$(mktemp 2>/dev/null || echo "/tmp/zpd-meta.$$")"
    dep_stage "metadata"
    dep_collect_metadata "$rel_dir" "$sha" "$_meta_file"
    mapfile -d '' -t ver_pairs < "$_meta_file" 2>/dev/null || ver_pairs=()
    rm -f "$_meta_file"

    dep_stage "manifest_prepare"
    if ! zpd_write_manifest "$manifest" \
        "release_id=${id}" "git_sha=${sha}" "git_ref=${ref}" "repo_url=${repo}" \
        "previous_release=${current_before}" "legacy_migration=${legacy}" \
        "started_at=${ts_start}" "result=activating" "migration_status=pending" \
        "manifest_schema_version=$(zpd_manifest_schema_version)" \
        "${ver_pairs[@]}"; then
        dep_fail "manifest_prepare" "manifest_write_failed" "could not write the release manifest"
        return 1
    fi

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
        dep_stage "success"
        zpd_write_manifest "$manifest" \
            "release_id=${id}" "git_sha=${sha}" "git_ref=${ref}" "repo_url=${repo}" \
            "previous_release=${current_before}" "legacy_migration=${legacy}" \
            "started_at=${ts_start}" "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            "result=success" "migration_status=${DEP_MIGRATION_STATUS}" "health=ok" \
            "manifest_schema_version=$(zpd_manifest_schema_version)" \
            "${ver_pairs[@]}"
        dep_reconcile_state "success" || dep_warn "state update failed after success"
        # Honest migration reporting — only mention the DB backup when something
        # actually ran.
        case "$DEP_MIGRATION_STATUS" in
            applied) dep_log "$(zpd_msg_migrate_applied)" ;;
            *)       dep_log "$(zpd_msg_migrate_none)" ;;
        esac
        dep_log "$(zpd_msg_success)"
        dep_cleanup_releases
        return 0
    fi

    # ── Activation failed. Capture the IMMUTABLE original failure context NOW,
    # before any rollback moves DEP_STAGE through the rollback stages.
    local orig_stage="${DEP_ORIG_FAILURE_STAGE:-$DEP_STAGE}"
    local orig_reason="${DEP_ORIG_FAILURE_REASON:-}"
    local orig_inv="${DEP_ORIG_FAILURE_INVARIANT:-}"
    local orig_msg="${DEP_ORIG_FAILURE_MESSAGE:-}"
    dep_err "activation failed (rc=${rc}, stage=${orig_stage})"
    zpd_write_manifest "$(zpd_shared_dir)/deploy/last-failure.json" \
        "stage=${orig_stage}" "reason_code=${orig_reason:-rc_${rc}}" "message=${orig_msg}" \
        "release_id=${id}" "previous_release=${current_before:-none}" \
        "occurred_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        || dep_warn "could not write the central failure event"

    # Migration-state for the manifest: on a migrate-stage failure the DB change
    # is `failed`; otherwise it reflects whether Artisan actually applied anything.
    local mig_state="$DEP_MIGRATION_STATUS"
    [ "$rc" -eq 30 ] && mig_state="failed"

    # First legacy→release cutover with no previous release: restore the legacy
    # application (its Nginx/Supervisor/scheduler config + drop `current`).
    if [ "$legacy" = "1" ] && [ -z "$current_before" ]; then
        dep_err "rolling back to the legacy application"
        local rb_result="failed"
        if dep_first_cutover_rollback "$base" "$fpm"; then
            rb_result="success"
            [ "$mig_state" = "applied" ] && { dep_warn "$(zpd_msg_migrate_applied)"; dep_warn "DB backup: $(zpd_backup_dir)/${id}/db.dump"; } || dep_log "$(zpd_msg_migrate_none)"
            dep_log "$(zpd_msg_legacy_restored)"
        else
            dep_err "legacy application could not be restored to a healthy state"
        fi
        # Finalize the attempt REGARDLESS of the rollback outcome. Rename the
        # attempted directory ONLY when it is no longer the active target — a
        # rollback that could not move the symlink must never leave `current`
        # dangling (that would turn a rollback failure into an outage).
        dep_finalize_failed_release "$manifest" "$id" "$sha" "$rc" "legacy" "$rb_result" \
            "$orig_stage" "$orig_reason" "$orig_inv" "$orig_msg" \
            || dep_warn "could not finalize failed-release manifest"
        if [ "$(zpd_current_release)" != "$id" ]; then
            mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
        else
            dep_warn "attempted release is still the active target — directory kept in place"
        fi
        dep_reconcile_state "failed" || dep_warn "state update failed"
        dep_failure_bundle
        return 1
    fi

    # Normal automatic code rollback to the previous release (dep_rollback_code
    # itself brings the previous release up and runs internal + HTTP readiness,
    # so a rollback is reported healthy ONLY when the previous release truly is).
    dep_err "rolling back code to ${current_before:-<none>}"
    local rb_result="not_available"
    if [ -n "$current_before" ]; then
        if dep_rollback_code "$current_before" "$fpm"; then rb_result="success"; else rb_result="failed"; fi
    fi

    # ── Finalize the attempted release BEFORE returning — regardless of the
    # rollback outcome. A completed failed deployment must NEVER stay
    # `activating`, and a rollback whose symlink switched but whose readiness
    # failed is recorded as exactly that. The directory is renamed to .failed
    # ONLY when the symlink no longer points at it — when the rollback switch
    # itself failed, `current` still targets the attempted release and renaming
    # it would leave the live symlink dangling (an instant outage).
    dep_finalize_failed_release "$manifest" "$id" "$sha" "$rc" "${current_before:-none}" "$rb_result" \
        "$orig_stage" "$orig_reason" "$orig_inv" "$orig_msg" \
        || dep_warn "could not finalize failed-release manifest"
    if [ "$(zpd_current_release)" != "$id" ]; then
        mv "$rel_dir" "${rel_dir}.failed" 2>/dev/null || true
    else
        dep_warn "attempted release is still the active target — directory kept in place"
    fi
    dep_reconcile_state "failed" || dep_warn "state update failed"

    if [ "$rb_result" = "success" ]; then
        if [ "$mig_state" = "applied" ]; then
            dep_warn "$(zpd_msg_migrate_applied)"
            dep_warn "DB backup: $(zpd_backup_dir)/${id}/db.dump"
        else
            dep_log "$(zpd_msg_migrate_none)"
        fi
        dep_log "$(zpd_msg_rolled_back)"
        dep_log "$(zpd_msg_previous_active)"
    else
        dep_err "automatic rollback could not restore a healthy previous release"
        dep_err "rollback_switch=${DEP_ROLLBACK_SWITCH} rollback_readiness=${DEP_ROLLBACK_READY}"
    fi

    # Automatic redacted diagnostics — bounded; never masks the original error.
    dep_failure_bundle
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
