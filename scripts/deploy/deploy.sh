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
# SIGKILL grace: every bounded external command is TERM'd at its deadline and
# KILLed ZPD_KILL_GRACE seconds later — a child that ignores/blocks SIGTERM
# can never outlive its bound (and never holds the deployment lock forever).
ZPD_KILL_GRACE="${ZPD_KILL_GRACE:-10}"
ZPD_PROBE_TIMEOUT="${ZPD_PROBE_TIMEOUT:-5}"
ZPD_META_TIMEOUT="${ZPD_META_TIMEOUT:-30}"
ZPD_HEALTH_CLI_TIMEOUT="${ZPD_HEALTH_CLI_TIMEOUT:-20}"
ZPD_SVC_TIMEOUT="${ZPD_SVC_TIMEOUT:-60}"
ZPD_DOCTOR_TIMEOUT="${ZPD_DOCTOR_TIMEOUT:-120}"
# Long-running stage deadlines (seconds). Each stage has its OWN bound — never
# an umbrella timeout that could kill rollback recovery. Generous defaults for
# legitimately slow work; a wedged command can still never block forever.
ZPD_BACKUP_TIMEOUT="${ZPD_BACKUP_TIMEOUT:-1800}"
ZPD_COMPOSER_TIMEOUT="${ZPD_COMPOSER_TIMEOUT:-1200}"
ZPD_NPM_TIMEOUT="${ZPD_NPM_TIMEOUT:-1200}"
ZPD_BUILD_TIMEOUT="${ZPD_BUILD_TIMEOUT:-1800}"
ZPD_ARTISAN_TIMEOUT="${ZPD_ARTISAN_TIMEOUT:-300}"
ZPD_MIGRATION_TIMEOUT="${ZPD_MIGRATION_TIMEOUT:-1800}"
ZPD_MAINTENANCE_TIMEOUT="${ZPD_MAINTENANCE_TIMEOUT:-60}"

# Every dep_log/warn/err line is ALSO appended to the per-run deployment log
# under the log dir (when writable) so the doctor's diagnostic bundle always
# contains the execution output that explains a failure.
DEP_LOG_FILE="${DEP_LOG_FILE:-}"
_dep_logline() { [ -z "${DEP_LOG_FILE:-}" ] || printf '%s\n' "$1" >> "$DEP_LOG_FILE" 2>/dev/null || true; }
dep_log()  { local m; m="[$(date -u +%H:%M:%S)] $*"; printf '%s\n' "$m"; _dep_logline "$m"; }
dep_warn() { local m; m="[$(date -u +%H:%M:%S)] WARN: $*"; printf '%s\n' "$m" >&2; _dep_logline "$m"; }
dep_err()  { local m; m="[$(date -u +%H:%M:%S)] ERROR: $*"; printf '%s\n' "$m" >&2; _dep_logline "$m"; }
dep_debug(){ [ "${ZPD_DEBUG:-0}" = "1" ] && printf '[%s] DEBUG: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2 || true; }

# dep_svc CMD ARGS... — run a service-control command BOUNDED and non-interactive
# (a hung systemctl/supervisorctl/nginx can never freeze the deploy).
dep_svc() { timeout -k "${ZPD_KILL_GRACE}" "${ZPD_SVC_TIMEOUT}s" "$@" </dev/null; }

# dep_run_bounded SECS STAGE_LABEL CMD ARGS...
#
# The shared bounded runner for every potentially blocking production command:
#   - stdin from /dev/null (never waits for terminal input)
#   - non-interactive environment
#   - TERM at the deadline, KILL 10s later (timeout -k)
#   - distinguishes a TIMEOUT (rc 124/137, logged as such) from an ordinary
#     non-zero failure — both logged with the exact redacted stage label
dep_run_bounded() {
    local secs="$1" label="$2"; shift 2
    local rc=0
    timeout -k 10 "${secs}s" env \
        COMPOSER_NO_INTERACTION=1 GIT_TERMINAL_PROMPT=0 \
        CI=1 DEBIAN_FRONTEND=noninteractive \
        "$@" </dev/null || rc=$?
    if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then
        dep_err "$(printf '%s' "$label" | zpd_mask_secrets): timed out after ${secs}s"
        return 124
    fi
    if [ "$rc" -ne 0 ]; then
        dep_err "$(printf '%s' "$label" | zpd_mask_secrets): failed (rc=${rc})"
    fi
    return "$rc"
}

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

# _dep_report_migration_state STATE RELEASE_ID — HONEST operator guidance after
# a rollback: `applied` → the DB changed (point at the backup); `unknown`
# (timed-out migration) → the DB state is INDETERMINATE — never claim nothing
# ran; direct the operator to verify and to the backup; anything else → no
# migrations ran.
_dep_report_migration_state() {
    local state="$1" id="$2"
    case "$state" in
        applied)
            dep_warn "$(zpd_msg_migrate_applied)"
            dep_warn "DB backup: $(zpd_backup_dir)/${id}/db.dump"
            ;;
        unknown)
            dep_warn "migration state UNKNOWN: the migration timed out mid-run — the database may be partially migrated"
            dep_warn "verify the schema manually; DB backup: $(zpd_backup_dir)/${id}/db.dump"
            ;;
        *)
            dep_log "$(zpd_msg_migrate_none)"
            ;;
    esac
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

    # Bounded: a wedged database connection cannot hang the deploy. On ANY
    # failure (incl. timeout) the INCOMPLETE dump file is removed — a partial
    # dump must never be mistaken for a usable backup. PGPASSWORD is injected
    # via the ENVIRONMENT (shell prefix assignment), never as an argv word
    # under timeout/env where /proc/*/cmdline would expose it.
    if ! PGPASSWORD="$pass" dep_run_bounded "$ZPD_BACKUP_TIMEOUT" "backup: pg_dump" \
            "$ZPD_PG_DUMP" -h "$host" -p "$port" -U "$user" -d "$db" -Fc -f "$out"; then
        rm -f "$out" 2>/dev/null || true
        return 1
    fi
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
      # Every step is BOUNDED via dep_run_bounded (its own deadline, TERM→KILL,
      # timeout distinguished from ordinary failure, stage logged). A build
      # timeout surfaces as a non-zero build → the release is finalized failed.
      dep_run_bounded "$ZPD_COMPOSER_TIMEOUT" "build: composer validate" \
          "$ZPD_COMPOSER" validate --strict --no-check-publish --no-interaction || exit 8
      dep_run_bounded "$ZPD_COMPOSER_TIMEOUT" "build: composer install" \
          "$ZPD_COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction || exit 10
      dep_run_bounded "$ZPD_NPM_TIMEOUT" "build: npm ci" "$ZPD_NPM" ci || exit 11
      dep_run_bounded "$ZPD_BUILD_TIMEOUT" "build: npm run build" "$ZPD_NPM" run build || exit 12
      dep_run_bounded "$ZPD_ARTISAN_TIMEOUT" "build: optimize:clear" "$ZPD_PHP" artisan optimize:clear || exit 13
      dep_run_bounded "$ZPD_ARTISAN_TIMEOUT" "build: config:cache"   "$ZPD_PHP" artisan config:cache || exit 14
      dep_run_bounded "$ZPD_ARTISAN_TIMEOUT" "build: route:cache"    "$ZPD_PHP" artisan route:cache  || exit 15
      dep_run_bounded "$ZPD_ARTISAN_TIMEOUT" "build: view:cache"     "$ZPD_PHP" artisan view:cache   || exit 16
    )
}

# dep_probe_version CMD [ARGS...]
#
# Run an external version command SAFELY for informational metadata:
#   - bounded by `timeout -k` (TERM then KILL — a hung Composer can never block)
#   - stdin from /dev/null (never reads the terminal → never "waits for input")
#   - non-interactive env (COMPOSER_NO_INTERACTION=1, GIT_TERMINAL_PROMPT=0, …)
#   - COMPOSER_ALLOW_SUPERUSER is deliberately NOT set: a metadata-only probe
#     must never enable Composer plugins as root — Composer's root safety
#     defaults stay in force, and any resulting failure simply yields
#     "unknown" (metadata never blocks activation). The superuser override
#     exists ONLY inside the build subshell where plugins are actually needed.
# Prints the first version-like token (e.g. 2.8.1) or "unknown" on any
# failure/timeout/malformed output. NEVER fails, blocks, or exposes secrets.
dep_probe_version() {
    local out
    out="$(timeout -k "${ZPD_KILL_GRACE}" "${ZPD_PROBE_TIMEOUT}s" env \
            COMPOSER_NO_INTERACTION=1 \
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
    tag="$(timeout -k "${ZPD_KILL_GRACE}" "${ZPD_PROBE_TIMEOUT}s" "$ZPD_GIT" -C "$rel" describe --tags --exact-match "$sha" </dev/null 2>/dev/null || true)"
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
      timeout -k "${ZPD_KILL_GRACE}" "${ZPD_HEALTH_CLI_TIMEOUT}s" "$ZPD_PHP" artisan schedule:list </dev/null >/dev/null 2>&1 || exit 1
    )
}

# ── Maintenance-mode helpers (framework-compatible; no translated text) ──────

# dep_is_in_maintenance APP_DIR — 0 when the app is in maintenance mode.
dep_is_in_maintenance() { zpd_is_in_maintenance "$1"; }

# dep_bring_down APP_DIR — enter maintenance. BOUNDED and returning the real
# result: required activation fencing must never be best-effort (a hung
# `artisan down` would previously block the deploy forever, and a failed fence
# would let the old release keep serving mid-migration). Callers that use it
# for OPTIONAL re-fencing append `|| true` explicitly.
dep_bring_down() {
    ( cd "$1" 2>/dev/null && timeout -k 5 "${ZPD_MAINTENANCE_TIMEOUT}s" \
        "$ZPD_PHP" artisan down --render="errors::503" </dev/null >/dev/null 2>&1 )
}

# dep_bring_up APP_DIR — REQUIRED exit from maintenance. `artisan up` must succeed
# AND the maintenance flag must actually be cleared. Returns 1 otherwise (never
# `|| true`), so a stuck maintenance state fails activation/rollback.
dep_bring_up() {
    local d="$1"
    if ! ( cd "$d" 2>/dev/null && timeout -k 5 "${ZPD_MAINTENANCE_TIMEOUT}s" \
            "$ZPD_PHP" artisan up </dev/null >/dev/null 2>&1 ); then
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
    out="$( cd "$rel" 2>/dev/null && timeout -k 10 "${ZPD_MIGRATION_TIMEOUT}s" \
        "$ZPD_PHP" artisan migrate --force --no-interaction </dev/null 2>&1 )"; rc=$?
    if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then
        # Timed out MID-MIGRATION: the DB state is INDETERMINATE — record
        # `unknown`, never a confident `failed`/`applied`. The symlink has not
        # switched at this stage, so the caller's rc-30 path rolls back safely.
        DEP_MIGRATION_STATUS="unknown"
        dep_err "migrate: timed out after ${ZPD_MIGRATION_TIMEOUT}s — database state is UNKNOWN"
        printf '%s\n' "$out" | zpd_mask_secrets | tail -n 8 >&2
        return 1
    fi
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

# dep_seed_required_defaults RELEASE_DIR — ensure the records the application
# REQUIRES to behave correctly (terms/privacy/about CMS pages behind the 301
# aliases, login/register noindex SEO records) via the targeted artisan
# command. The command runs EXACTLY two firstOrCreate seeders — administrator
# edits are never overwritten and re-running is idempotent. Bounded and
# non-interactive; a failure stops activation BEFORE the symlink switch.
dep_seed_required_defaults() {
    local rel="$1"
    ( cd "$rel" 2>/dev/null || exit 1
      dep_run_bounded "$ZPD_ARTISAN_TIMEOUT" "required_defaults: seed" \
          "$ZPD_PHP" artisan zedproxy:seed-required-defaults --no-interaction )
}

# ── Bounded CLI health + internal resource checks ────────────────────────────

# dep_cli_health CURRENT_DIR — `php artisan zedproxy:health --json`, bounded by a
# timeout. A timeout OR non-zero exit fails internal readiness; the failing
# component is shown (redacted). Never `|| true`.
dep_cli_health() {
    local cur="$1" out rc
    out="$( cd "$cur" 2>/dev/null && timeout -k "${ZPD_KILL_GRACE}" "${ZPD_HEALTH_CLI_TIMEOUT}s" "$ZPD_PHP" artisan zedproxy:health --json </dev/null 2>&1 )"; rc=$?
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
    # Bounded: a stalled PostgreSQL client must not hang readiness or the
    # doctor. PGPASSWORD travels via the environment, never in argv.
    PGPASSWORD="$pass" timeout -k "${ZPD_KILL_GRACE}" "${ZPD_DB_PROBE_TIMEOUT:-15}s" \
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
        out="$(timeout -k "${ZPD_KILL_GRACE}" "${ZPD_DB_PROBE_TIMEOUT:-15}s" "$ZPD_REDIS_CLI" -h "$host" -p "$port" -a "$pass" ping </dev/null 2>/dev/null)"
    else
        out="$(timeout -k "${ZPD_KILL_GRACE}" "${ZPD_DB_PROBE_TIMEOUT:-15}s" "$ZPD_REDIS_CLI" -h "$host" -p "$port" ping </dev/null 2>/dev/null)"
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

# _dep_workers_stopped GROUP — 0 only when every managed worker process is
# POSITIVELY verified STOPPED/EXITED, or the group is POSITIVELY absent.
# Fail-closed on every unverified case:
#   - a status timeout is never "stopped"
#   - an unexpected exit status (anything other than 0, or supervisorctl's
#     documented rc 3 accompanied by parseable group lines) is failure
#   - empty output is failure
#   - a malformed group line (no recognizable state token) is failure
#   - "group absent" is accepted ONLY from supervisorctl's explicit
#     "no such group/process" response for OUR group — unrelated program
#     output never proves the worker group absent
_dep_workers_stopped() {
    local group="$1" out rc lines bad
    out="$(dep_svc "$ZPD_SUPERVISORCTL" status "${group}:*" 2>/dev/null)"; rc=$?
    if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then return 1; fi   # timeout → NOT verified
    [ -n "$out" ] || return 1                                       # empty → NOT verified
    lines="$(printf '%s\n' "$out" | grep -F "${group}:" | grep -viE 'no such (group|process)' || true)"
    if [ -z "$lines" ]; then
        # POSITIVE absence only: supervisord explicitly reports our group as
        # not existing. Anything else (unrelated programs, error text) is
        # unverified.
        printf '%s\n' "$out" | grep -qiE "(${group}[^[:space:]]*:?[[:space:]]*)?ERROR \(no such (group|process)\)|no such (group|process): ?${group}" \
            && return 0
        return 1
    fi
    # Every managed line must carry a positively recognized stopped state.
    bad="$(printf '%s\n' "$lines" | awk '{print ($2 == "" ? "MALFORMED" : $2)}' | grep -vcE '^(STOPPED|EXITED)$' || true)"
    [ "${bad:-1}" -eq 0 ] || return 1
    # supervisorctl exits 0, or 3 when reporting non-running processes — both
    # are expected here; any other exit status is an unverified daemon state.
    [ "$rc" -eq 0 ] || [ "$rc" -eq 3 ]
}

# dep_stop_workers — REQUIRED worker fencing before migrations. The stop
# command must succeed AND every managed worker must be positively verified
# STOPPED/EXITED (or absent) within a bounded polling deadline. Anything else
# (RUNNING, STARTING, BACKOFF, FATAL, timeout, malformed status) fails —
# migrations must never run while old-code workers may still consume jobs.
dep_stop_workers() {
    local group waited=0 max="${ZPD_WORKER_STOP_TIMEOUT:-60}"
    group="$(zpd_supervisor_worker_group)"
    # Prior VERIFIED configuration fact: with no managed Supervisor worker
    # config on this host there is no worker group to fence.
    if [ ! -f "$(zpd_supervisor_conf_path)" ]; then
        dep_log "worker fencing: no managed Supervisor worker config — nothing to fence"
        return 0
    fi
    if ! dep_svc "$ZPD_SUPERVISORCTL" stop "${group}:*" >/dev/null 2>&1; then
        dep_err "worker fencing: supervisorctl stop failed"
        return 1
    fi
    while :; do
        _dep_workers_stopped "$group" && return 0
        waited=$((waited + 2))
        [ "$waited" -ge "$max" ] && break
        sleep 2
    done
    dep_err "worker fencing: worker group not verified STOPPED within ${max}s"
    return 1
}

# ── Strict legacy→current cutover (fail-closed; each step verified) ──────────

# dep_cutover_nginx BASE — backup, rewrite root → current/public, verify, and
# `nginx -t`; restore the previous config and fail on any error.
dep_cutover_nginx() {
    local base="$1" conf; conf="$(zpd_nginx_conf_path)"
    [ -f "$conf" ] || { dep_err "nginx config missing: ${conf}"; return 1; }
    cp -a "$conf" "${conf}.zpd-precutover" 2>/dev/null || true
    zpd_nginx_rewrite_root "$conf" "$base" || { dep_err "$(zpd_msg_nginx_restored)"; cp -a "${conf}.zpd-precutover" "$conf" 2>/dev/null || true; return 1; }
    # robots.txt must reach Laravel (dynamic RobotsController) — same
    # precutover-backup + nginx -t + restore-on-failure flow as the root.
    zpd_nginx_rewrite_robots "$conf" || { dep_err "$(zpd_msg_nginx_restored)"; cp -a "${conf}.zpd-precutover" "$conf" 2>/dev/null || true; return 1; }
    if ! zpd_nginx_root_ok "$conf" "$base" || ! zpd_nginx_robots_ok "$conf" || ! dep_validate_nginx; then
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
# _dep_snap_add DIR COMPONENT PATH — capture one managed resource into the
# snapshot inventory. inventory.tsv columns:
#   component  path  type(file|symlink|absent)  snap-or-target  sha256  mode  uid  gid  existed(0|1)
# A regular file is copied (metadata-preserving), byte-verified, and
# fingerprinted; a symlink records its type and target; an absent path records
# explicit absence so restore can REMOVE what a failed transaction created.
_dep_snap_add() {
    local dir="$1" comp="$2" path="$3" inv snap sha mode uid gid target
    inv="${dir}/inventory.tsv"
    if [ -L "$path" ]; then
        target="$(readlink "$path" 2>/dev/null)" || return 1
        printf '%s\t%s\tsymlink\t%s\t-\t-\t-\t-\t1\n' "$comp" "$path" "$target" >> "$inv" || return 1
        return 0
    fi
    if [ -f "$path" ]; then
        # COMPONENT-derived, DIRECTORY-RELATIVE snapshot name: unique even when
        # two managed paths share a basename, and stable across the snapshot
        # directory's atomic tmp→final rename.
        snap="${dir}/${comp}.snap"
        cp -a "$path" "$snap" 2>/dev/null || return 1
        cmp -s "$path" "$snap"            || return 1
        sha="$(sha256sum "$snap" 2>/dev/null | awk '{print $1}')"
        [ -n "$sha" ] || return 1
        mode="$(stat -c '%a' "$path" 2>/dev/null)" || return 1
        uid="$(stat -c '%u' "$path" 2>/dev/null)"  || return 1
        gid="$(stat -c '%g' "$path" 2>/dev/null)"  || return 1
        printf '%s\t%s\tfile\t%s\t%s\t%s\t%s\t%s\t1\n' "$comp" "$path" "${comp}.snap" "$sha" "$mode" "$uid" "$gid" >> "$inv" || return 1
        return 0
    fi
    printf '%s\t%s\tabsent\t-\t-\t-\t-\t-\t0\n' "$comp" "$path" >> "$inv" || return 1
    return 0
}

# dep_op_snapshot_schema — schema version of the operational-snapshot layout.
dep_op_snapshot_schema() { printf '2'; }

# _dep_op_snapshot_verified DIR — re-verify EVERY inventory record (snapshot
# copy present + SHA-256 matches the recorded fingerprint) and every captured
# scheduler source before the snapshot may be committed or restored from.
_dep_op_snapshot_verified() {
    local dir="$1" comp path type snapref sha mode uid gid existed have
    [ -f "${dir}/inventory.tsv" ] || return 1
    while IFS=$'\t' read -r comp path type snapref sha mode uid gid existed; do
        [ -n "$comp" ] || continue
        [ "$type" = "file" ] || continue
        case "$snapref" in /*) ;; *) snapref="${dir}/${snapref}" ;; esac
        [ -s "$snapref" ] || return 1
        have="$(sha256sum "$snapref" 2>/dev/null | awk '{print $1}')"
        [ -n "$have" ] && [ "$have" = "$sha" ] || return 1
    done < "${dir}/inventory.tsv"
    local idx spath smode sowner sexisted
    if [ -f "${dir}/sched-sources/sources.map" ]; then
        while IFS=$'\t' read -r idx spath smode sowner sexisted; do
            [ -n "$idx" ] || continue
            [ "$sexisted" = "1" ] || continue
            [ -s "${dir}/sched-sources/src-${idx}" ] || return 1
        done < "${dir}/sched-sources/sources.map"
    fi
    return 0
}

dep_snapshot_operational_config() {
    local id="$1" scope="${2:-all}" final dir n=0
    [ "$scope" = "all" ] && scope="nginx supervisor scheduler wrappers hv env"
    final="$(zpd_snapshots_dir)/${id}"
    while [ -e "$final" ] || [ -e "${final}.tmp" ]; do n=$((n + 1)); final="$(zpd_snapshots_dir)/${id}.${n}"; done
    # TRANSACTIONAL: everything is captured into a temporary directory and
    # only an atomically renamed, fully verified snapshot (snapshot_complete=
    # true) is ever returned. Directory protection is REQUIRED, not
    # best-effort (root ownership is asserted only when running as root —
    # the test harness runs unprivileged).
    dir="${final}.tmp"
    mkdir -p "$dir" 2>/dev/null || return 1
    chmod 700 "$dir" 2>/dev/null || { dep_err "snapshot: could not protect ${dir} (chmod 700)"; return 1; }
    if [ "$(id -u)" = "0" ]; then
        chown 0:0 "$dir" 2>/dev/null || { dep_err "snapshot: could not chown ${dir} to root"; return 1; }
    fi
    # MANIFEST-DRIVEN INVENTORY: every managed resource is recorded with its
    # component, absolute path, resource type (file|symlink|absent), snapshot
    # path (or symlink target), SHA-256, mode, uid, and gid. The copies keep
    # their ORIGINAL metadata (`cp -a`) — the restore uses the copy plus the
    # recorded values as the restoration source. Confidentiality comes from
    # the 0700 root-owned snapshot directory, never from mutating the copies.
    # ANY capture error fails the snapshot (never a silent partial snapshot).
    # CAPTURE IS COMPONENT-SCOPED: a targeted repair snapshots only the
    # resources of its selected components, so an unrelated broken resource
    # (e.g. an unreadable wrapper library during a --state repair) can never
    # abort a repair that would not touch it.
    : > "${dir}/inventory.tsv" || return 1
    local _c _p
    for _c in nginx hv supervisor scheduler env; do
        case " $scope " in *" $_c "*) ;; *) continue ;; esac
        case "$_c" in
            nginx)      _p="$(zpd_nginx_conf_path)" ;;
            hv)         _p="$(zpd_local_health_conf_path)" ;;
            supervisor) _p="$(zpd_supervisor_conf_path)" ;;
            scheduler)  _p="$(zpd_scheduler_cron_path)" ;;
            env)        _p="$(zpd_deploy_env_file)" ;;
        esac
        _dep_snap_add "$dir" "$_c" "$_p" \
            || { dep_err "snapshot: could not capture ${_c} (${_p})"; return 1; }
    done
    # Wrapper commands + the wrapper bootstrap library — REQUIRED, verified
    # capture (zpw_backup_wrappers is fail-closed), never best-effort.
    case " $scope " in *" wrappers "*)
        if declare -F zpw_backup_wrappers >/dev/null 2>&1; then
            if ! zpw_backup_wrappers "${dir}/wrappers" >/dev/null 2>&1; then
                dep_err "snapshot: wrapper capture failed"
                return 1
            fi
        fi
        ;;
    esac
    # Discover + capture every scheduler source reconciliation may touch.
    # sources.map: <index> TAB <absolute path> TAB <mode> TAB <uid:gid> TAB <existed 0|1>
    local base src map n_src=0
    base="$(zpd_base)"
    case " $scope " in *" scheduler "*) ;; *)
        base=""   # scheduler not selected → no cron-source capture needed
        ;;
    esac
    if [ -n "$base" ]; then
    mkdir -p "${dir}/sched-sources" 2>/dev/null || return 1
    map="${dir}/sched-sources/sources.map"
    : > "$map" || return 1
    chmod 600 "$map" 2>/dev/null || { dep_err "snapshot: could not protect the scheduler map (chmod 600)"; return 1; }
    while IFS= read -r src; do
        [ -n "$src" ] || continue
        n_src=$((n_src + 1))
        if [ -f "$src" ]; then
            cp -a "$src" "${dir}/sched-sources/src-${n_src}" 2>/dev/null || return 1
            printf '%s\t%s\t%s\t%s\t1\n' "$n_src" "$src" \
                "$(stat -c '%a' "$src" 2>/dev/null)" "$(stat -c '%u:%g' "$src" 2>/dev/null)" >> "$map" || return 1
        else
            printf '%s\t%s\t\t\t0\n' "$n_src" "$src" >> "$map" || return 1
        fi
    done < <(dep_scheduler_scan "$base" | sed -n 's/^OURS //p' | sort -u)
    fi
    # REQUIRED manifest: snapshot_complete=false first, then re-verify every
    # artifact/hash record, then flip to snapshot_complete=true and COMMIT via
    # atomic rename. A failed manifest write can never yield "success".
    zpd_write_manifest "${dir}/SNAPSHOT.json" \
        "release_id=${id}" "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "snapshot_schema_version=$(dep_op_snapshot_schema)" "snapshot_complete=false" \
        "nginx_conf=$(zpd_nginx_conf_path)" "local_health_conf=$(zpd_local_health_conf_path)" \
        "supervisor_conf=$(zpd_supervisor_conf_path)" "scheduler_cron=$(zpd_scheduler_cron_path)" \
        "deploy_env=$(zpd_deploy_env_file)" "scheduler_sources=${n_src}" \
        || { dep_err "snapshot: could not write SNAPSHOT.json"; return 1; }
    _dep_op_snapshot_verified "$dir" \
        || { dep_err "snapshot: captured artifacts failed verification"; return 1; }
    zpd_write_manifest "${dir}/SNAPSHOT.json" \
        "release_id=${id}" "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "snapshot_schema_version=$(dep_op_snapshot_schema)" "snapshot_complete=true" \
        "nginx_conf=$(zpd_nginx_conf_path)" "local_health_conf=$(zpd_local_health_conf_path)" \
        "supervisor_conf=$(zpd_supervisor_conf_path)" "scheduler_cron=$(zpd_scheduler_cron_path)" \
        "deploy_env=$(zpd_deploy_env_file)" "scheduler_sources=${n_src}" \
        || { dep_err "snapshot: could not commit SNAPSHOT.json"; return 1; }
    mv -T "$dir" "$final" 2>/dev/null || { dep_err "snapshot: atomic commit failed"; return 1; }
    # Atomic pointer to the most recent COMMITTED activation snapshot — a
    # MANUAL legacy rollback in a fresh process (zedproxy-rollback) reads this
    # to restore the cron sources the reconciliation stripped.
    printf '%s\n' "$final" > "$(zpd_snapshots_dir)/last-op-snapshot.ptr.tmp" \
        || { dep_err "snapshot: could not record the snapshot pointer"; return 1; }
    mv -f "$(zpd_snapshots_dir)/last-op-snapshot.ptr.tmp" "$(zpd_snapshots_dir)/last-op-snapshot.ptr" \
        || { dep_err "snapshot: could not commit the snapshot pointer"; return 1; }
    printf '%s' "$final"
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
    local dir="$1" scope="${2:-all}" rc=0
    [ -d "$dir" ] || { dep_err "restore: snapshot ${dir} missing"; return 1; }
    # Only a COMMITTED, current-schema snapshot may drive a restore: an
    # interrupted `.tmp` directory, a snapshot without its committed manifest,
    # snapshot_complete!=true, or an old/unknown schema version is refused.
    case "$dir" in *.tmp) dep_err "restore: refusing an uncommitted (.tmp) snapshot"; return 1 ;; esac
    local snapman="${dir}/SNAPSHOT.json"
    [ -f "$snapman" ] \
        || { dep_err "restore: snapshot manifest missing — refusing an unverified snapshot"; return 1; }
    [ "$(zpd_manifest_get "$snapman" snapshot_complete 2>/dev/null)" = "true" ] \
        || { dep_err "restore: refusing an incomplete snapshot (snapshot_complete != true)"; return 1; }
    [ "$(zpd_manifest_get "$snapman" snapshot_schema_version 2>/dev/null)" = "$(dep_op_snapshot_schema)" ] \
        || { dep_err "restore: refusing an old/unknown snapshot schema"; return 1; }
    # SCOPE is a space-separated component list (nginx, supervisor, scheduler,
    # wrappers, hv) or `all`. Only the selected components are restored and
    # only their services reloaded — a wrappers-only or scheduler-only failure
    # must never rewrite unrelated configs or restart unrelated live services.
    [ "$scope" = "all" ] && scope="nginx supervisor scheduler wrappers hv env"
    # INVENTORY-DRIVEN managed-resource restore: every record whose component
    # is in scope is restored EXACTLY — bytes (cmp + SHA-256 against the
    # recorded fingerprint), mode/uid/gid to the recorded values, symlink
    # type/target recreated, and explicitly-absent resources removed (verified
    # gone). deploy.env is a managed resource like any other.
    local inv="${dir}/inventory.tsv" comp path type snapref sha mode uid gid existed have
    [ -f "$inv" ] || { dep_err "restore: snapshot inventory missing — refusing"; return 1; }
    {
        while IFS=$'\t' read -r comp path type snapref sha mode uid gid existed; do
            [ -n "$comp" ] && [ -n "$path" ] || continue
            case " $scope " in *" $comp "*) ;; *) continue ;; esac
            case "$type" in
                file)
                    case "$snapref" in /*) ;; *) snapref="${dir}/${snapref}" ;; esac
                    if ! cp -a "$snapref" "$path" 2>/dev/null || ! cmp -s "$snapref" "$path"; then
                        dep_err "restore: could not restore ${path} exactly"; rc=1; continue
                    fi
                    have="$(sha256sum "$path" 2>/dev/null | awk '{print $1}')"
                    [ "$have" = "$sha" ] || { dep_err "restore: ${path} SHA-256 differs from the recorded fingerprint"; rc=1; }
                    chmod "$mode" "$path" 2>/dev/null || { dep_err "restore: chmod ${path} failed"; rc=1; }
                    chown "${uid}:${gid}" "$path" 2>/dev/null || { dep_err "restore: chown ${path} failed"; rc=1; }
                    [ "$(stat -c '%a %u %g' "$path" 2>/dev/null)" = "${mode} ${uid} ${gid}" ] \
                        || { dep_err "restore: ${path} mode/ownership differ from the recorded values"; rc=1; }
                    ;;
                symlink)
                    rm -f "$path" 2>/dev/null || { dep_err "restore: could not replace ${path}"; rc=1; continue; }
                    ln -s "$snapref" "$path" 2>/dev/null || { dep_err "restore: could not recreate symlink ${path}"; rc=1; continue; }
                    [ "$(readlink "$path" 2>/dev/null)" = "$snapref" ] \
                        || { dep_err "restore: ${path} symlink target differs from the recorded value"; rc=1; }
                    ;;
                absent)
                    rm -f "$path" 2>/dev/null || { dep_err "restore: could not remove ${path}"; rc=1; }
                    [ -e "$path" ] && { dep_err "restore: ${path} still exists (was created by the failed transaction)"; rc=1; }
                    ;;
                *)
                    dep_err "restore: unrecognized inventory record for ${path}"; rc=1
                    ;;
            esac
        done < "$inv"
    }
    case " $scope " in *" wrappers "*)
        if declare -F zpw_restore_wrappers >/dev/null 2>&1 && [ -d "${dir}/wrappers" ]; then
            zpw_restore_wrappers "${dir}/wrappers" || { dep_err "restore: wrapper restore failed"; rc=1; }
            # BEHAVIORAL wrapper verification of the restored set: when the
            # pre-snapshot state HAD the wrapper system (bootstrap recorded as
            # existing) and a release is active, the restored wrappers must
            # still resolve through current/. A restored pre-wrapper (legacy)
            # state is intentionally not held to the modern contract.
            if declare -F zpw_verify_wrappers >/dev/null 2>&1 && [ -L "$(zpd_current_link)" ] \
               && grep -qE 'bootstrap\.sh[[:space:]]+1$' "${dir}/wrappers/wrappers.map" 2>/dev/null; then
                zpw_verify_wrappers "$(zpd_base)" >/dev/null 2>&1 \
                    || { dep_err "restore: restored wrappers failed verification"; rc=1; }
            fi
        fi
        ;;
    esac
    # Restore every scheduler SOURCE the transaction may have modified —
    # content, mode, and ownership exactly (verified against the snapshot);
    # files that did not exist before the transaction are removed. This is what
    # puts a stripped /etc/crontab entry back when a later step fails.
    local map="${dir}/sched-sources/sources.map" idx path mode owner existed
    case " $scope " in *" scheduler "*) ;; *) map=/dev/null ;; esac
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
                [ -n "$mode" ]  && { chmod "$mode" "$path" 2>/dev/null || { dep_err "restore: chmod ${path} failed"; rc=1; }; }
                [ -n "$owner" ] && { chown "$owner" "$path" 2>/dev/null || { dep_err "restore: chown ${path} failed"; rc=1; }; }
                if [ -n "$mode" ] && [ "$(stat -c '%a' "$path" 2>/dev/null)" != "$mode" ]; then
                    dep_err "restore: ${path} mode differs from the recorded value"; rc=1
                fi
                if [ -n "$owner" ] && [ "$(stat -c '%u:%g' "$path" 2>/dev/null)" != "$owner" ]; then
                    dep_err "restore: ${path} ownership differs from the recorded value"; rc=1
                fi
            else
                rm -f "$path" 2>/dev/null || { dep_err "restore: could not remove ${path}"; rc=1; }
                [ -e "$path" ] && { dep_err "restore: ${path} still exists (created by the failed transaction)"; rc=1; }
            fi
        done < "$map"
    fi
    # Reload ONLY the services whose configuration was in scope.
    case " $scope " in *" nginx "*|*" hv "*)
        dep_validate_nginx || { dep_err "restore: nginx -t failed on restored config"; rc=1; }
        dep_reload_nginx   || { dep_err "restore: nginx reload failed"; rc=1; }
        ;;
    esac
    case " $scope " in *" supervisor "*)
        dep_svc "$ZPD_SUPERVISORCTL" reread >/dev/null 2>&1 || { dep_err "restore: supervisor reread failed"; rc=1; }
        dep_svc "$ZPD_SUPERVISORCTL" update >/dev/null 2>&1 || { dep_err "restore: supervisor update failed"; rc=1; }
        dep_restart_workers || { dep_err "restore: worker restart failed"; rc=1; }
        dep_supervisor_group_running || { dep_err "restore: worker group not RUNNING after restore"; rc=1; }
        ;;
    esac
    # Bounded functional scheduler verification of the RESTORED state.
    case " $scope " in *" scheduler "*)
        if [ -L "$(zpd_current_link)" ]; then
            dep_verify_scheduler "$(zpd_current_link)" \
                || { dep_err "restore: schedule:list failed on the restored state"; rc=1; }
        fi
        ;;
    esac
    return "$rc"
}

# dep_reconcile_nginx BASE — ensure the Nginx root serves <BASE>/current/public.
# Correct config is left untouched; a legacy/stale root is rewritten, verified,
# and `nginx -t`-validated with restore-on-failure (dep_cutover_nginx).
dep_reconcile_nginx() {
    local base="$1" conf; conf="$(zpd_nginx_conf_path)"
    if [ -f "$conf" ] && zpd_nginx_root_ok "$conf" "$base" && zpd_nginx_robots_ok "$conf"; then return 0; fi
    dep_log "Reconciling Nginx (root → current/public, dynamic robots.txt)…"
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

# _dep_sched_text_is_ours OURS_RE ESC_BASE — does the unit/stanza text on
# STDIN execute OUR scheduler? Matches either the single-line form (artisan
# path under the base) or the standard SPLIT form: a WorkingDirectory=<base>
# (or directory=<base>) declaration combined with a RELATIVE
# `php artisan schedule:run` ExecStart/command.
_dep_sched_text_is_ours() {
    local re="$1" esc="$2" text
    text="$(cat)"
    if printf '%s\n' "$text" | grep -E 'artisan[[:space:]]+schedule:run' \
        | grep -vE '^[[:space:]]*#' | grep -E "$re" >/dev/null; then
        return 0
    fi
    printf '%s\n' "$text" \
        | grep -qE "^[[:space:]]*(WorkingDirectory|directory)[[:space:]]*=[[:space:]]*${esc}/?[[:space:]]*\$" \
        || return 1
    printf '%s\n' "$text" | grep -E '^[[:space:]]*(ExecStart[^=]*|command)[[:space:]]*=' \
        | grep -vE '^[[:space:]]*#' \
        | grep -qE '(^|[[:space:]=/])php[[:space:]]+artisan[[:space:]]+schedule:run'
}

# _dep_systemd_unit_state UNIT — print "enabled-state/active-state" for a unit
# (both queries bounded; unknown when systemctl is unavailable).
_dep_systemd_unit_state() {
    local unit="$1" en ac
    en="$(dep_svc "$ZPD_SYSTEMCTL" is-enabled "$unit" 2>/dev/null | head -n1 || true)"
    ac="$(dep_svc "$ZPD_SYSTEMCTL" is-active "$unit" 2>/dev/null | head -n1 || true)"
    printf '%s/%s' "${en:-unknown}" "${ac:-unknown}"
}

# _dep_supervisor_effective_files — the EFFECTIVE Supervisor config set: the
# main supervisord config plus every file matched by its [include] globs
# (relative globs resolve against the main config's directory, per supervisord
# semantics), plus the conventional scan directory as a safety net. Never
# assumes a single conf.d. One absolute path per line, deduplicated.
_dep_supervisor_effective_files() {
    local main d g f
    main="$(zpd_supervisord_conf)"
    {
        if [ -f "$main" ]; then
            printf '%s\n' "$main"
            d="$(dirname "$main")"
            while IFS= read -r g; do
                [ -n "$g" ] || continue
                case "$g" in /*) ;; *) g="${d}/${g}" ;; esac
                # shellcheck disable=SC2231  # intentional glob expansion of the include pattern
                for f in $g; do [ -f "$f" ] && printf '%s\n' "$f"; done
            done < <(awk '
                /^\[include\]/ { inc=1; next }
                /^\[/          { inc=0 }
                inc && /^[ \t]*files[ \t]*=/ {
                    sub(/^[ \t]*files[ \t]*=[ \t]*/, "");
                    n = split($0, a, /[ \t]+/);
                    for (i = 1; i <= n; i++) if (a[i] != "") print a[i]
                }' "$main" 2>/dev/null)
        fi
        if [ -d "$(zpd_supervisor_scan_dir)" ]; then
            for f in "$(zpd_supervisor_scan_dir)"/*.conf; do
                [ -f "$f" ] && printf '%s\n' "$f"
            done
        fi
    } | sort -u
    return 0
}

# dep_scheduler_scan_noncron BASE — bounded, READ-ONLY discovery of ZedProxy
# scheduler invocations OUTSIDE cron. Covers EVERY systemd unit load tree
# (admin /etc, runtime /run, vendor /usr/local/lib, /usr/lib, /lib), drop-in
# overrides (<unit>.d/*.conf), symlinked aliases (grep follows the symlink),
# units systemd itself reports (`systemctl list-unit-files` +
# `systemctl list-timers --all`, each rendered via `systemctl cat` so
# generator-produced or aliased units outside the scanned trees are seen), and
# the EFFECTIVE Supervisor program set (main config + [include] globs). These
# are UNMANAGED homes — the reconciler never edits them automatically. Prints
# classified lines:
#   SYSTEMD <unit-file-or-name> (<enabled-state>/<active-state>)
#   SUPERVISOR <conf-file>
dep_scheduler_scan_noncron() {
    local base="$1" re esc
    re="$(zpd_scheduler_ours_re "$base")"
    esc="$(printf '%s' "$base" | sed 's/[][\.*^$(){}?+|]/\\&/g')"
    {
        local d f unit
        # 1. Every systemd unit load tree, including drop-ins.
        while IFS= read -r d; do
            [ -d "$d" ] || continue
            for f in "$d"/*.timer "$d"/*.service "$d"/*.timer.d/*.conf "$d"/*.service.d/*.conf; do
                [ -f "$f" ] || continue
                if _dep_sched_text_is_ours "$re" "$esc" < "$f"; then
                    unit="$(basename "$f")"
                    case "$f" in
                        *.d/*.conf) unit="$(basename "$(dirname "$f")")"; unit="${unit%.d}" ;;
                    esac
                    printf 'SYSTEMD %s (%s)\n' "$f" "$(_dep_systemd_unit_state "$unit")"
                fi
            done
        done < <(zpd_systemd_unit_dirs)
        # 2. Units systemd itself knows about (generators, aliases, masked
        #    overrides) — with FAIL-CLOSED discovery status. Every systemctl
        #    query's exit status is captured DIRECTLY (never lost inside a
        #    pipeline or process substitution): a timeout, an unavailable
        #    systemctl, an unexpected failure, or malformed output emits a
        #    blocking `DISCOVERY_ERROR <kind> <detail>` record instead of
        #    silently shrinking the discovered set. The reconciler treats any
        #    DISCOVERY_ERROR as an active conflict and stops BEFORE writing
        #    the canonical cron.
        local luf_out luf_rc lt_out lt_rc units cat_out cat_rc bad
        luf_out="$(dep_svc "$ZPD_SYSTEMCTL" list-unit-files --type=timer --type=service --no-legend --no-pager 2>/dev/null)"; luf_rc=$?
        case "$luf_rc" in
            0) ;;
            124|137) printf 'DISCOVERY_ERROR timed_out systemctl-list-unit-files\n' ;;
            126|127) printf 'DISCOVERY_ERROR unavailable systemctl\n' ;;
            *)       printf 'DISCOVERY_ERROR failed systemctl-list-unit-files rc=%s\n' "$luf_rc" ;;
        esac
        # With --type filters + --no-legend every non-empty line's first token
        # must name a .timer/.service unit — anything else is malformed.
        bad="$(printf '%s\n' "$luf_out" | awk 'NF > 0 {print $1}' | grep -vcE '\.(timer|service)$' || true)"
        [ "${bad:-0}" -ne 0 ] && printf 'DISCOVERY_ERROR malformed systemctl-list-unit-files\n'
        lt_out="$(dep_svc "$ZPD_SYSTEMCTL" list-timers --all --no-legend --no-pager 2>/dev/null)"; lt_rc=$?
        case "$lt_rc" in
            0) ;;
            124|137) printf 'DISCOVERY_ERROR timed_out systemctl-list-timers\n' ;;
            126|127) printf 'DISCOVERY_ERROR unavailable systemctl\n' ;;
            *)       printf 'DISCOVERY_ERROR failed systemctl-list-timers rc=%s\n' "$lt_rc" ;;
        esac
        units="$( { printf '%s\n' "$luf_out" | awk 'NF > 0 {print $1}'; \
                    printf '%s\n' "$lt_out" | awk '{for (i = 1; i <= NF; i++) if ($i ~ /\.timer$/) print $i}'; } \
                  | grep -E '\.(timer|service)$' | sort -u )"
        while IFS= read -r unit; do
            [ -n "$unit" ] || continue
            cat_out="$(dep_svc "$ZPD_SYSTEMCTL" cat "$unit" 2>/dev/null)"; cat_rc=$?
            case "$cat_rc" in
                0) ;;
                124|137) printf 'DISCOVERY_ERROR timed_out systemctl-cat-%s\n' "$unit"; continue ;;
                *)       printf 'DISCOVERY_ERROR failed systemctl-cat-%s rc=%s\n' "$unit" "$cat_rc"; continue ;;
            esac
            if printf '%s\n' "$cat_out" | _dep_sched_text_is_ours "$re" "$esc"; then
                printf 'SYSTEMD %s (%s)\n' "$unit" "$(_dep_systemd_unit_state "$unit")"
            fi
        done <<< "$units"
        # 3. Effective Supervisor programs: each [program:*] STANZA is
        #    evaluated independently — the stanza whose command executes our
        #    scheduler is reported with ITS OWN program name and autostart
        #    setting (an unrelated program's autostart=false earlier in the
        #    same file must not mask an autostarted scheduler stanza). Lines:
        #    SUPERVISOR <conf-file> (<program>/<autostart>)
        local prog auto stanzas awk_rc
        while IFS= read -r f; do
            [ -f "$f" ] || continue
            # Per-file stanza extraction with its exit status captured — a
            # failed parse is a blocking discovery error, never an empty set.
            stanzas="$(ZPD_OURS_RE="$re" ZPD_BASE_RE="$esc" awk '
                function flush() { if (m || (dirok && relm)) print name "\t" auto; m = 0; dirok = 0; relm = 0 }
                /^\[program:/ { flush(); name = $0; sub(/^\[program:/, "", name); sub(/\].*/, "", name); auto = "true"; next }
                /^\[/         { flush(); name = "" }
                name != "" && /^[ \t]*autostart[ \t]*=[ \t]*[Ff][Aa][Ll][Ss][Ee]/ { auto = "false" }
                name != "" && /^[ \t]*directory[ \t]*=/ {
                    if ($0 ~ ("^[ \t]*directory[ \t]*=[ \t]*" ENVIRON["ZPD_BASE_RE"] "/?[ \t]*$")) dirok = 1
                }
                name != "" && /^[ \t]*command[ \t]*=/ {
                    if ($0 ~ ENVIRON["ZPD_OURS_RE"]) m = 1
                    else if ($0 ~ /^[ \t]*command[ \t]*=[ \t]*php[ \t]+artisan[ \t]+schedule:run/) relm = 1
                }
                END { flush() }' "$f" 2>/dev/null)"; awk_rc=$?
            if [ "$awk_rc" -ne 0 ]; then
                printf 'DISCOVERY_ERROR failed supervisor-parse-%s rc=%s\n' "$f" "$awk_rc"
                continue
            fi
            while IFS=$'\t' read -r prog auto; do
                [ -n "$prog" ] || continue
                printf 'SUPERVISOR %s (%s/%s)\n' "$f" "$prog" "$auto"
            done <<< "$stanzas"
        done < <(_dep_supervisor_effective_files)
    } | sort -u
    return 0
}

# _dep_systemd_state_class "en/ac" — classify a recorded enabled/active state
# pair EXPLICITLY as one of: active | inactive | unknown. `active` means the
# unit runs now or will start (running/activating, or enabled at boot).
# `inactive` requires POSITIVE verification on BOTH axes — a recognized
# not-running state AND a recognized will-not-start state. Anything else —
# including the `unknown/unknown` produced when systemctl fails, times out, or
# answers with something unrecognized — is `unknown`, and unknown is NEVER
# silently treated as inactive (the caller must block on it).
_dep_systemd_state_class() {
    local en="${1%%/*}" ac="${1##*/}"
    case "$ac" in
        active|activating|reloading) printf 'active'; return 0 ;;
    esac
    case "$en" in
        enabled|enabled-runtime) printf 'active'; return 0 ;;
    esac
    case "$ac" in
        inactive|failed|dead|exited)
            case "$en" in
                disabled|masked|masked-runtime|static|indirect|linked|linked-runtime|generated|transient)
                    printf 'inactive'; return 0 ;;
            esac
            ;;
    esac
    printf 'unknown'
    return 0
}

# dep_scheduler_filter_active — stdin: scan lines from dep_scheduler_scan_noncron;
# stdout: only the ACTIVE subset. A systemd source is active when its unit is
# running/activating or enabled — or, for a `.service` (the standard
# foo.timer→foo.service arrangement puts `schedule:run` only in the service,
# which reports static/inactive between invocations), when its COMPANION
# `.timer` is enabled/active. A Supervisor STANZA is active unless it declares
# autostart=false AND the daemon does not report that program RUNNING/STARTING
# right now. Everything else is an INACTIVE leftover: a cleanup candidate, not
# a live duplicate.
dep_scheduler_filter_active() {
    local line state f unit prog auto cls tcls out rc st2
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        case "$line" in
            DISCOVERY_ERROR\ *)
                # Discovery itself failed/timed out/was malformed — the source
                # set is UNVERIFIED, which always blocks reconciliation.
                printf '%s\n' "$line"
                ;;
            SUPERVISOR\ *)
                state="${line##*\(}"; state="${state%\)}"
                prog="${state%%/*}"; auto="${state##*/}"
                if [ "$auto" = "true" ]; then
                    printf '%s\n' "$line"
                elif [ "$auto" != "false" ]; then
                    # Unparseable stanza record → UNKNOWN → block.
                    printf '%s\n' "$line"
                else
                    # autostart=false: the stanza is inactive ONLY when the
                    # daemon POSITIVELY reports its exact program STOPPED or
                    # EXITED. RUNNING/STARTING is active; a timeout, empty,
                    # malformed, or any other status (BACKOFF, FATAL, missing)
                    # is UNKNOWN — and unknown blocks.
                    out="$(dep_svc "$ZPD_SUPERVISORCTL" status "$prog" 2>/dev/null)"; rc=$?
                    st2="$(printf '%s\n' "$out" | awk 'NR==1 {print $2}')"
                    if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ] || [ -z "$out" ]; then
                        printf '%s\n' "$line"            # unknown → block
                    else
                        case "$st2" in
                            RUNNING|STARTING) printf '%s\n' "$line" ;;   # active
                            STOPPED|EXITED)   : ;;                        # verified inactive
                            *)                printf '%s\n' "$line" ;;   # unknown → block
                        esac
                    fi
                fi
                ;;
            SYSTEMD\ *)
                state="${line##*\(}"; state="${state%\)}"
                cls="$(_dep_systemd_state_class "$state")"
                if [ "$cls" != "inactive" ]; then
                    # active OR unknown → block (unknown is never assumed safe).
                    printf '%s\n' "$line"
                    continue
                fi
                # Positively inactive .service: its COMPANION .timer must ALSO
                # be positively inactive (an enabled/active timer keeps
                # triggering it; an unknown timer state blocks).
                f="${line#SYSTEMD }"; f="${f% \(*}"
                unit="$(basename "$f")"
                case "$f" in
                    *.d/*.conf) unit="$(basename "$(dirname "$f")")"; unit="${unit%.d}" ;;
                esac
                case "$unit" in
                    *.service)
                        _dep_systemd_timer_exists "${unit%.service}.timer"; rc=$?
                        if [ "$rc" -eq 2 ]; then
                            printf '%s\n' "$line"        # existence unknown → block
                        elif [ "$rc" -eq 0 ]; then
                            tcls="$(_dep_systemd_state_class "$(_dep_systemd_unit_state "${unit%.service}.timer")")"
                            [ "$tcls" != "inactive" ] && printf '%s\n' "$line"
                        fi
                        # rc=1: no companion timer exists → the positively
                        # inactive service stays an inactive leftover.
                        ;;
                esac
                ;;
        esac
    done
    return 0
}

# _dep_systemd_timer_exists TIMER — 0 when systemd lists the unit file, 1 when
# it positively does not exist, 2 when the query timed out (unknown).
_dep_systemd_timer_exists() {
    local t="$1" out rc
    out="$(dep_svc "$ZPD_SYSTEMCTL" list-unit-files "$t" --no-legend --no-pager 2>/dev/null)"; rc=$?
    if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then return 2; fi
    if [ -n "$out" ]; then
        # Listed output with an unexpected exit status is UNKNOWN, not proof.
        [ "$rc" -eq 0 ] || return 2
        return 0
    fi
    # Empty output: only systemctl's RECOGNIZED no-match results (rc 0, or its
    # documented rc 1 for zero matching unit files) positively mean "absent".
    # Any other failure is UNKNOWN — never silently "timer absent".
    if [ "$rc" -le 1 ]; then return 1; fi
    return 2
}

# dep_scheduler_active_conflicts BASE — the ACTIVE non-cron scheduler sources
# only. These are the deployment-blocking duplicates; inactive leftovers are
# reported as warnings by the reconciler instead.
dep_scheduler_active_conflicts() {
    dep_scheduler_scan_noncron "$1" | dep_scheduler_filter_active
    return 0
}

# dep_scheduler_single_source_ok BASE — after reconciliation EXACTLY ONE active
# ZedProxy scheduler source may exist: the canonical cron file. Any remaining
# cron duplicate or ACTIVE non-cron (systemd/supervisor) source fails; an
# inactive leftover unit does not (it executes nothing).
dep_scheduler_single_source_ok() {
    local base="$1"
    zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" || return 1
    dep_scheduler_scan "$base" | grep -q '^OURS ' && return 1
    [ -n "$(dep_scheduler_active_conflicts "$base")" ] && return 1
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
    # UNMANAGED/AMBIGUOUS homes. An ACTIVE source aborts with a clear
    # diagnostic instead of editing it (or leaving a second live scheduler
    # running). An INACTIVE leftover unit executes nothing — it is reported as
    # a cleanup candidate, not a deployment blocker.
    local allscan conflict inactive
    allscan="$(dep_scheduler_scan_noncron "$base")"
    conflict="$(printf '%s\n' "$allscan" | dep_scheduler_filter_active)"
    if [ -n "$conflict" ]; then
        printf '%s\n' "$conflict" | while IFS= read -r entry; do
            dep_err "scheduler: ACTIVE unmanaged scheduler source found: ${entry}"
        done
        dep_err "$(zpd_msg_sched_conflict)"
        return 1
    fi
    inactive="$(comm -23 <(printf '%s\n' "$allscan" | sort -u) <(printf '%s\n' "$conflict" | sort -u) | sed '/^$/d')"
    if [ -n "$inactive" ]; then
        printf '%s\n' "$inactive" | while IFS= read -r entry; do
            dep_warn "scheduler: INACTIVE leftover scheduler source (cleanup candidate, not blocking): ${entry}"
        done
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
    elif [ -L "$current_dir" ]; then
        maint_dir="$current_dir"
    else
        # First deploy onto an already-atomic layout: no live application
        # exists yet, so there is nothing to fence.
        maint_dir=""
    fi

    # 1. maintenance mode (against the currently-live app) — REQUIRED fencing:
    # a hung or failed `artisan down` aborts BEFORE migrations/switch (nothing
    # has changed yet; the caller's rollback re-verifies the previous release).
    dep_stage "maintenance"
    if [ -n "$maint_dir" ] && ! dep_bring_down "$maint_dir"; then
        dep_record_failure "$DEP_STAGE" "maintenance_fence_failed" "dep_bring_down" "activation: could not enter maintenance mode"
        dep_err "activation: could not enter maintenance mode"
        return 31
    fi

    # 2. REQUIRED worker fencing: stop the worker group and POSITIVELY verify
    # every managed worker is STOPPED/EXITED (or absent) before migrations —
    # never best-effort. On failure nothing has switched: clear maintenance,
    # bring the old workers back, and abort with failure_stage=worker_stop.
    dep_stage "worker_stop"
    if ! dep_stop_workers; then
        dep_record_failure "$DEP_STAGE" "worker_stop_failed" "dep_stop_workers" "activation: could not verify the worker group stopped"
        dep_err "activation: could not verify the worker group stopped — aborting before migrations"
        dep_svc "$ZPD_SUPERVISORCTL" start "$(zpd_supervisor_worker_group):*" >/dev/null 2>&1 \
            || dep_warn "worker fencing: could not restart the old workers after the failed fence"
        if [ -n "$maint_dir" ]; then
            dep_bring_up "$maint_dir" || dep_warn "worker fencing: could not exit maintenance after the failed fence"
        fi
        return 31
    fi

    # 3. migrations (run from the NEW release, which is linked to shared .env).
    #    Records DEP_MIGRATION_STATUS = none_pending|applied|failed so the manifest
    #    and any warning reflect whether the DB actually changed.
    dep_stage "migrate"
    if ! dep_run_migrations "$(zpd_releases_dir)/${id}"; then
        dep_record_failure "$DEP_STAGE" "migration_failed" "dep_run_migrations" "activation: migrations failed"
        dep_err "activation: migrations failed"
        return 30
    fi

    # 3b. required default records (idempotent seeders) — run AFTER migrations
    #     and BEFORE the symlink switch, so a failure can never make a release
    #     public without its required CMS/SEO records and never leaves `current`
    #     pointing at the new release.
    dep_stage "required_defaults"
    if ! dep_seed_required_defaults "$(zpd_releases_dir)/${id}"; then
        dep_record_failure "$DEP_STAGE" "required_defaults_failed" "dep_seed_required_defaults" "activation: required default records could not be ensured"
        dep_err "activation: required default records could not be ensured"
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
    #     REQUIRED (verified capture): without it a failed cutover could not
    #     restore the pre-cutover command set — never best-effort.
    if [ "$legacy" = "1" ] && declare -F zpw_backup_wrappers >/dev/null 2>&1; then
        if ! zpw_backup_wrappers "$(dep_wrapper_backup_dir)"; then
            dep_record_failure "$DEP_STAGE" "wrapper_backup_failed" "zpw_backup_wrappers" "activation: pre-cutover wrapper capture failed"
            dep_err "activation: pre-cutover wrapper capture failed"
            return 31
        fi
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
    # EXACT legacy restore is REQUIRED (content verified, modes/ownership
    # re-applied, cutover-created files removed). Only a server with no
    # snapshot at all falls through with a warning — and then the semantic
    # verification below still gates the result.
    if zpd_has_legacy_rollback; then
        zpd_restore_legacy_rollback \
            || { dep_err "rollback: legacy snapshot restore failed"; return 1; }
    else
        dep_warn "no legacy snapshot to restore"
    fi
    # Restore the pre-cutover command wrappers so the server keeps whatever
    # zedproxy-* commands it had before the failed migration. REQUIRED — a
    # partial wrapper restore is a rollback failure, never a warning.
    if declare -F zpw_restore_wrappers >/dev/null 2>&1 && [ -d "$(dep_wrapper_backup_dir)" ]; then
        zpw_restore_wrappers "$(dep_wrapper_backup_dir)" \
            || { dep_err "rollback: pre-cutover wrapper restore failed"; return 1; }
    fi
    rm -f "$(zpd_current_link)" 2>/dev/null || true
    # Reconciliation may have STRIPPED the legacy scheduler entry from a
    # non-canonical cron source (e.g. /etc/crontab) before this failure. Those
    # sources are recorded in the per-activation operational snapshot — put
    # them back (scheduler scope only, after `current` is dropped so no check
    # runs against the failed release) so the restored legacy application
    # never comes back with its scheduler silently disabled. A MANUAL rollback
    # (zedproxy-rollback in a fresh process, DEP_SNAPSHOT_DIR unset) uses the
    # committed last-op-snapshot pointer instead.
    local op_snap="${DEP_SNAPSHOT_DIR:-}"
    if [ -z "$op_snap" ] && [ -f "$(zpd_snapshots_dir)/last-op-snapshot.ptr" ]; then
        op_snap="$(cat "$(zpd_snapshots_dir)/last-op-snapshot.ptr" 2>/dev/null)"
    fi
    if [ -n "$op_snap" ] && [ -d "$op_snap" ]; then
        dep_restore_operational_snapshot "$op_snap" scheduler \
            || { dep_err "rollback: could not restore the pre-cutover scheduler sources"; return 1; }
    fi
    # SEMANTIC verification of the restored configuration — the rollback is
    # never reported healthy just because files came back (or because the
    # internal health vhost was repaired): the public Nginx root must serve
    # the LEGACY application and the Supervisor command must run the LEGACY
    # artisan path.
    if [ -f "$(zpd_nginx_conf_path)" ]; then
        grep -Eq "root[[:space:]]+${base}/public;" "$(zpd_nginx_conf_path)" \
            || { dep_err "rollback: restored Nginx root does not serve the legacy application"; return 1; }
    fi
    if [ -f "$(zpd_supervisor_conf_path)" ]; then
        grep -q "command=php ${base}/artisan" "$(zpd_supervisor_conf_path)" \
            || { dep_err "rollback: restored Supervisor command does not run the legacy artisan"; return 1; }
    fi
    if [ -f "$(zpd_scheduler_cron_path)" ]; then
        grep -Eq "$(zpd_scheduler_ours_re "$base")" "$(zpd_scheduler_cron_path)" \
            && ! grep -q "${base}/current/artisan" "$(zpd_scheduler_cron_path)" \
            || { dep_err "rollback: restored Scheduler source is not the expected legacy source"; return 1; }
    fi
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
    # ── Rollback reconciliation is FAIL-CLOSED: a rollback whose PHP-FPM,
    # Nginx, or workers could not actually be reloaded onto the previous
    # release is NOT a successful rollback, even if HTTP happens to answer.
    dep_stage "rollback_reconcile"
    DEP_ROLLBACK_RECONCILE="failed"
    dep_reload_php_fpm "$fpm"    || { dep_err "rollback: php-fpm reload failed"; return 1; }
    dep_validate_nginx           || { dep_err "rollback: nginx -t failed"; return 1; }
    dep_reload_nginx             || { dep_err "rollback: nginx reload failed"; return 1; }
    dep_restart_workers          || { dep_err "rollback: worker restart failed"; return 1; }
    dep_supervisor_group_running || { dep_err "rollback: worker group not RUNNING"; return 1; }
    DEP_ROLLBACK_RECONCILE="success"
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
    ref="$(timeout -k "${ZPD_KILL_GRACE}" "${ZPD_PROBE_TIMEOUT}s" "$ZPD_GIT" -C "$dir" rev-parse --abbrev-ref HEAD </dev/null 2>/dev/null || true)"
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
    local active rel manifest result rc=0
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
            || { dep_warn "could not finalize stale release ${rel}"; rc=1; }
    done < <(zpd_list_releases)
    return "$rc"
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
    if [ -n "$active" ]; then
        # `current` must resolve EXACTLY to the selected child of releases/ —
        # a symlink pointing outside releases/ (whose basename merely matches
        # a release id) must never drive a state repair.
        local canon releases_canon
        canon="$(readlink -f "$(zpd_current_link)" 2>/dev/null)"             || { dep_warn "state: current target unreadable — not repairing state"; return 1; }
        releases_canon="$(cd "$(zpd_releases_dir)" 2>/dev/null && pwd -P)"             || { dep_warn "state: releases dir unreadable — not repairing state"; return 1; }
        if [ "$canon" != "${releases_canon}/${active}" ]; then
            dep_warn "state: current resolves to ${canon}, not releases/${active} — not repairing state"
            return 1
        fi
    fi
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
    # A migrate-stage failure is `failed` — unless the migration TIMED OUT, in
    # which case the DB state is honestly `unknown` and must stay that way.
    [ "$rc" -eq 30 ] && [ "$mig_state" != "unknown" ] && mig_state="failed"
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
        ( cd "$cur" 2>/dev/null && timeout -k 5 15s "$ZPD_PHP" artisan up </dev/null >/dev/null 2>&1 ) || dep_warn "interrupt: could not exit maintenance"
    fi
    timeout -k 10 30s "$ZPD_SUPERVISORCTL" start "$(zpd_supervisor_worker_group):*" </dev/null >/dev/null 2>&1 || dep_warn "interrupt: could not restart workers"
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
    # Per-run deployment log (600) — the source the diagnostic bundle collects.
    if [ -z "${DEP_LOG_FILE:-}" ]; then
        DEP_LOG_FILE="$(zpd_log_dir)/deploy-$(date -u +%Y%m%d%H%M%S).log"
        if : > "$DEP_LOG_FILE" 2>/dev/null; then
            chmod 600 "$DEP_LOG_FILE" 2>/dev/null || true
        else
            DEP_LOG_FILE=""
        fi
    fi

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
    dep_finalize_stale_releases || dep_warn "could not finalize every stale release manifest (continuing)"
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

    # Snapshot the legacy operational config so a failed FIRST cutover can
    # restore the legacy application exactly (there is no previous release to
    # fall back on). MANDATORY and FRESH PER ATTEMPT: every first-cutover
    # attempt captures its own immutable release-scoped snapshot (temp dir →
    # verify content/SHA-256/mode/ownership/existence → snapshot_complete=true
    # → atomic commit) — an earlier attempt's snapshot is NEVER reused, even
    # when it looks complete, because it may no longer match the current
    # pre-cutover state. A capture failure ABORTS here — before maintenance,
    # migrations, the symlink switch, or any service cutover.
    if [ "$legacy" = "1" ]; then
        if ! zpd_save_legacy_rollback "$base" \
            "$(zpd_nginx_conf_path)" "$(zpd_supervisor_conf_path)" "$(zpd_scheduler_cron_path)" "$id"; then
            dep_fail "legacy_snapshot" "legacy_snapshot_failed" \
                "could not capture a fresh verified legacy rollback snapshot — aborting before any modification"
            return 1
        fi
    fi

    dep_log "Activating ${id}…"
    dep_activate "$id" "$fpm" "$current_before" "$legacy" "$sha"
    local rc=$?

    if [ "$rc" -eq 0 ]; then
        dep_stage "success"
        # The final manifest write is REQUIRED: without `result=success` the
        # release history claims the active code is still `activating` and a
        # later reconciliation would finalize the LIVE release as stale/failed.
        # The code activation itself succeeded and is left in place — this is
        # a metadata-commit failure, reported as such (never silent success).
        if ! zpd_write_manifest "$manifest" \
            "release_id=${id}" "git_sha=${sha}" "git_ref=${ref}" "repo_url=${repo}" \
            "previous_release=${current_before}" "legacy_migration=${legacy}" \
            "started_at=${ts_start}" "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
            "result=success" "migration_status=${DEP_MIGRATION_STATUS}" "health=ok" \
            "manifest_schema_version=$(zpd_manifest_schema_version)" \
            "${ver_pairs[@]}"; then
            dep_err "code activation SUCCEEDED but the final release manifest could not be written"
            dep_err "the new release is live and healthy; its metadata commit failed — fix storage and run zedproxy-deploy-repair"
            zpd_write_manifest "$(zpd_shared_dir)/deploy/last-failure.json" \
                "stage=metadata_commit" "reason_code=success_manifest_write_failed" \
                "message=code activation succeeded but the final manifest write failed" \
                "code_active=true" "release_id=${id}" \
                "previous_release=${current_before:-none}" \
                "occurred_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                || dep_warn "could not write the central failure event"
            dep_failure_bundle
            return 1
        fi
        # Central-state reconciliation after a committed manifest: a failure
        # here is a clearly-recorded DEGRADED success (the release manifest —
        # the primary record — is already durable; the state file is derived
        # and repairable), never a silent one.
        if ! dep_reconcile_state "success"; then
            dep_warn "DEGRADED SUCCESS: deployment succeeded and its manifest is committed, but the central state file could not be reconciled"
            dep_warn "run zedproxy-deploy-repair to rebuild the state file"
            zpd_write_manifest "$(zpd_shared_dir)/deploy/last-failure.json" \
                "stage=state_reconcile" "reason_code=state_reconcile_failed_after_success" \
                "message=deployment succeeded (manifest committed) but central state reconciliation failed" \
                "code_active=true" "release_id=${id}" \
                "previous_release=${current_before:-none}" \
                "occurred_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                || dep_warn "could not write the central failure event"
        fi
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
    # is `failed` — unless the migration TIMED OUT, in which case the DB state
    # is honestly `unknown` and must stay indeterminate everywhere (manifest
    # AND operator guidance).
    local mig_state="$DEP_MIGRATION_STATUS"
    [ "$rc" -eq 30 ] && [ "$mig_state" != "unknown" ] && mig_state="failed"

    # First legacy→release cutover with no previous release: restore the legacy
    # application (its Nginx/Supervisor/scheduler config + drop `current`).
    if [ "$legacy" = "1" ] && [ -z "$current_before" ]; then
        dep_err "rolling back to the legacy application"
        local rb_result="failed"
        if dep_first_cutover_rollback "$base" "$fpm"; then
            rb_result="success"
            _dep_report_migration_state "$mig_state" "$id"
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
        _dep_report_migration_state "$mig_state" "$id"
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
