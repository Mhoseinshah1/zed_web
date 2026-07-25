#!/usr/bin/env bash
# =============================================================================
# Shell tests for the atomic release-deployment system.
#
#   bash tests/deploy/run-tests.sh
#
# Non-git system commands (composer, npm, php, pg_dump, systemctl, nginx,
# supervisorctl, curl, psql, redis-cli, node) are MOCKED with CONFIGURABLE
# behaviour — including mocks that HANG, READ STDIN, EXIT NON-ZERO, DELAY, or
# emit MALFORMED output — so the bounded/non-interactive probes and the split
# maintenance→up→HTTP readiness ordering can be proven. Git is REAL and runs
# against LOCAL temporary repositories (a bare "remote" with a main branch,
# commits, a lightweight tag and an annotated tag) — never the production GitHub
# repository. No root, no network.
#
# Prompt 17 focus: maintenance-mode readiness ordering (internal checks run in
# maintenance; public HTTP only after `artisan up`), honest migration-state
# reporting, bounded non-interactive tool-version probes, the loopback health
# vhost, and process-interrupt safety.
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LIB="${REPO_ROOT}/scripts/lib/deploy-lib.sh"
DEPLOY="${REPO_ROOT}/scripts/deploy/deploy.sh"
ROLLBACK="${REPO_ROOT}/scripts/deploy/rollback.sh"
WRAPPERS="${REPO_ROOT}/scripts/deploy/install-command-wrappers.sh"
UPDATE_SH="${REPO_ROOT}/update.sh"

PASS=0; FAIL=0
ok()  { echo "  ok   - $*"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL - $*"; FAIL=$((FAIL + 1)); }
assert_true()  { if "$@"; then ok "$*"; else bad "$* (expected success)"; fi; }
assert_false() { if "$@"; then bad "$* (expected failure)"; else ok "! $*"; fi; }
assert_eq()    { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
assert_rc()    { local want="$1" got="$2" msg="$3"; if [ "$got" = "$want" ]; then ok "$msg"; else bad "$msg (rc=$got want=$want)"; fi; }

# ── Mocked (non-git) commands ────────────────────────────────────────────────
MOCKBIN="$(mktemp -d)"

cat > "${MOCKBIN}/composer" <<'EOF'
#!/usr/bin/env bash
# Build steps exit MOCK_COMPOSER_RC; the informational --version probe emits a
# realistic banner so dep_probe_version can extract a token. With
# MOCK_COMPOSER_REQUIRE_SUPERUSER=1 it behaves like real Composer running as
# root: it fails unless COMPOSER_ALLOW_SUPERUSER=1 is set in its environment
# (proving the deployer's composer-root policy is deterministic).
# MOCK_COMPOSER_FORBID_SUPERUSER=1 → fail if the caller exported
# COMPOSER_ALLOW_SUPERUSER (proves metadata probes never enable root plugins).
if [ "${MOCK_COMPOSER_FORBID_SUPERUSER:-0}" = "1" ] && [ "${COMPOSER_ALLOW_SUPERUSER:-0}" = "1" ]; then
  echo "COMPOSER_ALLOW_SUPERUSER leaked into a metadata probe" >&2
  exit 1
fi
case "$*" in
  *--version*) echo "Composer version 2.8.1 2024-10-01 12:00:00"; exit 0 ;;
esac
[ "${MOCK_COMPOSER_HANG:-0}" = "1" ] && sleep 60
if [ "${MOCK_COMPOSER_REQUIRE_SUPERUSER:-0}" = "1" ] && [ "${COMPOSER_ALLOW_SUPERUSER:-0}" != "1" ]; then
  echo "Composer plugins have been disabled for safety in this non-interactive session." >&2
  exit 1
fi
exit ${MOCK_COMPOSER_RC:-0}
EOF
cat > "${MOCKBIN}/npm" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  ci)        [ "${MOCK_NPM_HANG:-0}" = "1" ] && sleep 60; exit ${MOCK_NPM_CI_RC:-0} ;;
  run)       if [ "${2:-}" = "build" ]; then [ "${MOCK_NPM_BUILD_HANG:-0}" = "1" ] && sleep 60; exit ${MOCK_NPM_BUILD_RC:-0}; fi; exit 0 ;;
  --version) echo "10.8.2"; exit 0 ;;
  *)         exit 0 ;;
esac
EOF
cat > "${MOCKBIN}/node" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  --version) echo "v22.9.0"; exit 0 ;;
  *)         exit 0 ;;
esac
EOF
# Maintenance-aware php mock.
#   -r/-v            → version banners
#   artisan down     → create storage/framework/maintenance.php (relative to CWD)
#   artisan up       → remove it (exit MOCK_UP_RC; MOCK_UP_STUCK leaves the flag)
#   artisan migrate  → MOCK_MIGRATE_MODE = none|applied|fail
#   zedproxy:health  → MOCK_HEALTH_MODE = ok|fail|hang
#   schedule:list    → MOCK_SCHEDULE_RC
cat > "${MOCKBIN}/php" <<'EOF'
#!/usr/bin/env bash
# Optional invocation log (used to prove repair NEVER runs migrations).
[ -n "${MOCK_LOG:-}" ] && printf '%s\n' "$*" >> "$MOCK_LOG" 2>/dev/null
if [ "${1:-}" = "-r" ]; then echo "${MOCK_PHP_VERSION:-8.3.7}"; exit 0; fi
if [ "${1:-}" = "-v" ]; then echo "PHP ${MOCK_PHP_VERSION:-8.3.7} (cli)"; exit 0; fi
case "$*" in
  *"artisan down"*)
      [ "${MOCK_DOWN_HANG:-0}" = "1" ] && sleep 60
      mkdir -p storage/framework 2>/dev/null || true
      printf '<?php return array();\n' > storage/framework/maintenance.php 2>/dev/null || true
      exit 0 ;;
  *"artisan up"*)
      [ "${MOCK_UP_HANG:-0}" = "1" ] && sleep 60
      rc="${MOCK_UP_RC:-0}"
      if [ "$rc" = "0" ] && [ "${MOCK_UP_STUCK:-0}" != "1" ]; then
          rm -f storage/framework/maintenance.php storage/framework/down 2>/dev/null || true
      fi
      exit "$rc" ;;
  *"artisan migrate"*)
      case "${MOCK_MIGRATE_MODE:-none}" in
        applied) echo "Migrating: 2026_01_01_000000_add_widget"; echo "Migrated:  2026_01_01_000000_add_widget (12.34ms)"; exit 0 ;;
        fail)    echo "   SQLSTATE[42P01]: undefined_table"; exit 1 ;;
        hang)    echo "Migrating: 2026_01_01_000000_add_widget"; sleep 60; exit 0 ;;
        *)       echo "Nothing to migrate."; exit 0 ;;
      esac ;;
  *"zedproxy:seed-required-defaults"*) exit ${MOCK_SEED_RC:-0} ;;
  *"artisan schedule:list"*) exit ${MOCK_SCHEDULE_RC:-0} ;;
  *"zedproxy:health"*)
      case "${MOCK_HEALTH_MODE:-ok}" in
        hang) sleep 60; exit 0 ;;
        fail) echo '{"ok":false,"database":false}'; exit 1 ;;
        *)    echo '{"ok":true}'; exit 0 ;;
      esac ;;
  *) exit ${MOCK_PHP_RC:-0} ;;
esac
EOF
cat > "${MOCKBIN}/pg_dump" <<'EOF'
#!/usr/bin/env bash
# MOCK_PGDUMP_REQUIRE_ENVPASS=1 → behave like real pg_dump on a
# password-protected DB: fail unless PGPASSWORD arrived via the ENVIRONMENT.
if [ "${MOCK_PGDUMP_REQUIRE_ENVPASS:-0}" = "1" ] && [ -z "${PGPASSWORD:-}" ]; then
  echo "fe_sendauth: no password supplied" >&2
  exit 1
fi
out=""; while [ $# -gt 0 ]; do [ "$1" = "-f" ] && { out="$2"; shift; }; shift; done
if [ "${MOCK_PGDUMP_HANG:-0}" = "1" ]; then
  # Writes a PARTIAL dump, then hangs — the bounded runner must kill it and the
  # deployer must REMOVE the incomplete file.
  [ -n "$out" ] && printf 'PGDMP-partial' > "$out"
  sleep 60
  exit 0
fi
[ -n "$out" ] && : > "$out"
exit ${MOCK_PGDUMP_RC:-0}
EOF
cat > "${MOCKBIN}/systemctl" <<'EOF'
#!/usr/bin/env bash
# Per-unit-type states: *.timer queries answer MOCK_TIMER_*, everything else
# answers MOCK_SVC_* (falling back to MOCK_TIMER_*), so a stopped .service with
# an enabled companion .timer can be modelled. MOCK_SYSTEMCTL_HANG hangs EVERY
# query (models an unresponsive systemd → bounded callers must time out).
[ "${MOCK_SYSTEMCTL_HANG:-0}" = "1" ] && sleep 60
case "${1:-}" in
  is-enabled)
    case "${2:-}" in
      *.timer) echo "${MOCK_TIMER_ENABLED:-enabled}" ;;
      *)       echo "${MOCK_SVC_ENABLED:-${MOCK_TIMER_ENABLED:-enabled}}" ;;
    esac
    exit 0 ;;
  is-active)
    case "${2:-}" in
      *.timer) echo "${MOCK_TIMER_ACTIVE:-active}" ;;
      *)       echo "${MOCK_SVC_ACTIVE:-${MOCK_TIMER_ACTIVE:-active}}" ;;
    esac
    exit 0 ;;
  list-unit-files)
    [ "${MOCK_LUF_HANG:-0}" = "1" ] && sleep 60
    [ "${MOCK_LUF_MALFORMED:-0}" = "1" ] && echo "### corrupted table row ###"
    # A companion timer is "known to systemd" only when MOCK_TIMER_LISTED=1.
    case "${2:-}" in
      *.timer)  [ "${MOCK_TIMER_LISTED:-0}" = "1" ] && echo "${2} ${MOCK_TIMER_ENABLED:-enabled}" ;;
      --type*)  [ "${MOCK_TIMER_LISTED:-0}" = "1" ] && echo "zp-sched.timer ${MOCK_TIMER_ENABLED:-enabled}" ;;
    esac
    exit ${MOCK_LUF_RC:-0} ;;
  list-timers)
    exit ${MOCK_LT_RC:-0} ;;
  cat)
    exit ${MOCK_CAT_RC:-0} ;;
esac
exit ${MOCK_SYSTEMCTL_RC:-0}
EOF
cat > "${MOCKBIN}/nginx" <<'EOF'
#!/usr/bin/env bash
[ "${MOCK_NGINX_HANG:-0}" = "1" ] && sleep 60
[ "${1:-}" = "-t" ] && exit ${MOCK_NGINX_RC:-0}
exit 0
EOF
cat > "${MOCKBIN}/supervisorctl" <<'EOF'
#!/usr/bin/env bash
[ "${MOCK_SUP_HANG:-0}" = "1" ] && sleep 60
# Hostile child: IGNORES SIGTERM — only SIGKILL (timeout -k grace) can end it.
if [ "${MOCK_SUP_IGNORE_TERM:-0}" = "1" ]; then
  trap '' TERM
  sleep 60 & wait $!
  exit 0
fi
# Stateful worker group: `stop` moves it to STOPPED, `start`/`restart` back to
# RUNNING — so the required worker fence sees a real stop→verify transition.
# An explicit MOCK_SUP_STATUS always wins (used to model FATAL/refusing states).
statefile="${ZPD_BASE:-/tmp}/.mock-sup-state"
case "${1:-}" in
  reread)  exit ${MOCK_SUP_REREAD_RC:-0} ;;
  update)  exit ${MOCK_SUP_UPDATE_RC:-0} ;;
  restart) rc=${MOCK_SUP_RESTART_RC:-0}; [ "$rc" = "0" ] && echo running > "$statefile" 2>/dev/null; exit $rc ;;
  start)   echo running > "$statefile" 2>/dev/null; exit 0 ;;
  stop)    [ "${MOCK_SUP_STOP_RC:-0}" = "0" ] && echo stopped > "$statefile" 2>/dev/null; exit ${MOCK_SUP_STOP_RC:-0} ;;
  status)
    if [ -n "${MOCK_SUP_STATUS+x}" ]; then
      printf '%s\n' "$MOCK_SUP_STATUS"
    elif [ "$(cat "$statefile" 2>/dev/null)" = "stopped" ]; then
      printf 'zedproxy-worker:zedproxy-worker_00   STOPPED   Jul 25 08:00 AM\n'
    else
      printf 'zedproxy-worker:zedproxy-worker_00   RUNNING   pid 100, uptime 0:00:05\n'
    fi
    exit ${MOCK_SUP_STATUS_RC:-0} ;;
  *)       exit 0 ;;
esac
EOF
# Maintenance-aware curl mock: while the current app is in maintenance mode the
# public endpoints return 503 (exactly the real-world false-negative this system
# must tolerate). Only after `artisan up` clears the flag does it return
# MOCK_HTTP_CODE. It never inspects the host — the loopback URL is irrelevant.
cat > "${MOCKBIN}/curl" <<'EOF'
#!/usr/bin/env bash
base="${ZPD_BASE:-}"
# The live app is served from current/public (storage → shared), so readiness
# reflects the maintenance flag of the CURRENTLY-SERVED release only. A stale
# maintenance file left in a legacy base dir does not make the live app "down".
if [ -n "$base" ] && { [ -f "${base}/current/storage/framework/maintenance.php" ] \
                    || [ -f "${base}/shared/storage/framework/maintenance.php" ]; }; then
  echo "503"; exit 0
fi
echo "${MOCK_HTTP_CODE:-200}"
exit 0
EOF
cat > "${MOCKBIN}/psql" <<'EOF'
#!/usr/bin/env bash
[ "${MOCK_PSQL_HANG:-0}" = "1" ] && sleep 60
exit ${MOCK_PSQL_RC:-0}
EOF
cat > "${MOCKBIN}/redis-cli" <<'EOF'
#!/usr/bin/env bash
echo "${MOCK_REDIS_PONG:-PONG}"
exit ${MOCK_REDIS_RC:-0}
EOF
chmod +x "${MOCKBIN}"/*

export ZPD_COMPOSER="${MOCKBIN}/composer"
export ZPD_NPM="${MOCKBIN}/npm"
export ZPD_NODE="${MOCKBIN}/node"
export ZPD_PHP="${MOCKBIN}/php"
export ZPD_PG_DUMP="${MOCKBIN}/pg_dump"
export ZPD_SYSTEMCTL="${MOCKBIN}/systemctl"
export ZPD_NGINX="${MOCKBIN}/nginx"
export ZPD_SUPERVISORCTL="${MOCKBIN}/supervisorctl"
export ZPD_CURL="${MOCKBIN}/curl"
export ZPD_PSQL="${MOCKBIN}/psql"
export ZPD_REDIS_CLI="${MOCKBIN}/redis-cli"
export ZPD_GIT="git"                 # REAL git against local temp repos
export ZPD_MIN_DISK_MB=1
export ZPD_HEALTH_URL="http://localhost"
export ZPD_ALLOW_LOCAL_REPO=1
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

# Keep doctor sub-checks fast in the suite (each is bounded anyway).
export ZDR_TIMEOUT=3
# Temp fixtures are root-owned; evaluate shared-writability for root.
export ZPD_APP_USER=root
export ZPD_DOCTOR_TIMEOUT=30

# shellcheck disable=SC1090
source "$LIB"
# shellcheck disable=SC1090
source "$WRAPPERS"
# shellcheck disable=SC1090
source "$DEPLOY"
# shellcheck disable=SC1090
source "$ROLLBACK"
# shellcheck disable=SC1090
source "${REPO_ROOT}/scripts/deploy/doctor.sh"
# shellcheck disable=SC1090
source "${REPO_ROOT}/scripts/deploy/repair.sh"
# shellcheck disable=SC1090
source "${REPO_ROOT}/scripts/deploy/deploy-status.sh"

# ── Build a real "remote" bare repo (main + tags + the deploy/updater files) ──
mk_source_repo() {
    local work; work="$(mktemp -d)"
    git -C "$work" init -q -b main
    mkdir -p "$work/app" "$work/bootstrap" "$work/config" "$work/routes" \
             "$work/public/build" "$work/storage/app/public" "$work/scripts/deploy"
    : > "$work/artisan"; : > "$work/composer.json"; : > "$work/package.json"
    : > "$work/composer.lock"; : > "$work/package-lock.json"
    # Vite build output + front controller the readiness gate requires.
    : > "$work/public/index.php"
    printf '{"resources/js/app.js":{"file":"assets/app-abc123.js"}}\n' > "$work/public/build/manifest.json"
    # Files the command wrappers resolve through current/.
    : > "$work/update.sh"
    : > "$work/scripts/deploy/deploy.sh"
    : > "$work/scripts/deploy/rollback.sh"
    : > "$work/scripts/deploy/deploy-status.sh"
    : > "$work/scripts/deploy/doctor.sh"
    : > "$work/scripts/deploy/repair.sh"
    : > "$work/scripts/zedproxy-sanitize-install-log.sh"
    git -C "$work" add -A >/dev/null; git -C "$work" commit -q -m "c1" >/dev/null
    git -C "$work" tag v1.0.0
    git -C "$work" tag -a v2.0.0 -m "annotated" >/dev/null
    echo change >> "$work/artisan"
    git -C "$work" commit -q -am "c2" >/dev/null
    SRC_BARE="$(mktemp -d)/remote.git"
    git clone -q --bare "$work" "$SRC_BARE" >/dev/null
    SRC_MAIN_SHA="$(git -C "$work" rev-parse main)"
    SRC_TAG_SHA="$(git -C "$work" rev-parse v1.0.0)"
    SRC_ATAG_SHA="$(git -C "$work" rev-parse 'v2.0.0^{}')"
    rm -rf "$work"
}

new_base() {
    BASE="$(mktemp -d)"
    export ZPD_BASE="$BASE"
    export ZPD_LOCK_FILE="${BASE}/deploy.lock"
    export ZPD_LOG_DIR="${BASE}/logs"
    export ZPD_STATE_FILE="${BASE}/shared/deploy/state.json"
    export ZPD_BACKUP_DIR="${BASE}/backups"
    export ZPD_NGINX_CONF="${BASE}/nginx.conf"
    export ZPD_SUPERVISOR_CONF="${BASE}/worker.conf"
    # Scheduler discovery scans realistic cron sources — ALL under the temp base.
    export ZPD_CRON_D_DIR="${BASE}/cron.d"
    export ZPD_ETC_CRONTAB="${BASE}/etc-crontab"
    export ZPD_CRON_SPOOL_DIR="${BASE}/cron-spool"
    export ZPD_SCHED_CRON="${BASE}/cron.d/zedproxy-scheduler"
    export ZPD_SCHED_LOG="${BASE}/scheduler.log"
    # Non-cron scheduler homes (systemd units, Supervisor programs) — all temp.
    export ZPD_SYSTEMD_UNIT_DIR="${BASE}/systemd"
    # Full-discovery trees isolated from the host (tests override per-scenario
    # with extra vendor/runtime dirs).
    export ZPD_SYSTEMD_UNIT_DIRS="${BASE}/systemd"
    export ZPD_SUPERVISOR_SCAN_DIR="${BASE}/supervisor.d"
    export ZPD_SUPERVISORD_CONF="${BASE}/supervisord.conf"
    export ZPD_WRAPPER_BIN="${BASE}/wbin"
    export ZPD_WRAPPER_LIB="${BASE}/wlib"
    export ZPD_DEPLOY_ENV="${BASE}/deploy.env"
    # Loopback health vhost written to a writable temp path (never /etc in tests).
    export ZPD_LOCAL_HEALTH_CONF="${BASE}/local-health.conf"
    export ZPD_FPM_SOCK="${BASE}/php-fpm.sock"
    mkdir -p "${BASE}/releases" "${BASE}/shared/storage/app/public" \
             "${BASE}/shared/storage/framework" "${BASE}/wbin" "${BASE}/wlib" \
             "${BASE}/cron.d" "${BASE}/cron-spool" "${BASE}/logs" \
             "${BASE}/systemd" "${BASE}/supervisor.d"
    printf 'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_DATABASE=zed\nDB_USERNAME=zed\nDB_PASSWORD=secret\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\nREDIS_PASSWORD=null\n' > "${BASE}/shared/.env"
}

# Service configs already pointing at current/ (an already-atomic server).
setup_atomic_service_configs() {
    printf 'server {\n  listen 80;\n  root %s/current/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
    zpd_supervisor_conf_content "$BASE" > "$ZPD_SUPERVISOR_CONF"
    zpd_scheduler_cron_content "$BASE" > "$ZPD_SCHED_CRON"; chmod 644 "$ZPD_SCHED_CRON"
}
# Legacy service configs (base/… paths, no current).
setup_legacy_service_configs() {
    printf 'server {\n  listen 80;\n  root %s/public;\n}\nserver {\n  ssl_certificate x;\n  root %s/public;\n}\n' "$BASE" "$BASE" > "$ZPD_NGINX_CONF"
    printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work redis\nstdout_logfile=%s/storage/logs/worker.log\n' "$BASE" "$BASE" > "$ZPD_SUPERVISOR_CONF"
    printf '* * * * * www-data cd %s && php %s/artisan schedule:run >> /var/log/x 2>&1\n' "$BASE" "$BASE" > "$ZPD_SCHED_CRON"
}
# Turn BASE into a legacy single-dir install (artisan + .env at base, no shared).
make_legacy_base() {
    rm -rf "${BASE}/shared"
    : > "${BASE}/artisan"
    printf 'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_DATABASE=zed\nDB_USERNAME=zed\nDB_PASSWORD=secret\nREDIS_PASSWORD=null\n' > "${BASE}/.env"
    mkdir -p "${BASE}/storage/app/public"; echo upload > "${BASE}/storage/app/public/u.txt"
}
mk_release() { local id="$1" result="${2:-success}"; mkdir -p "${BASE}/releases/${id}"; zpd_write_manifest "${BASE}/releases/${id}/RELEASE_MANIFEST.json" "release_id=${id}" "result=${result}"; }

# mk_release_git ID — build a REAL git release with the front controller, Vite
# manifest, the wrapper-resolved scripts, a manifest whose git_sha == HEAD, and
# shared-storage links. Echoes the release HEAD SHA. Does NOT switch `current`.
mk_release_git() {
    local id="$1"
    local rd="${BASE}/releases/${id}" sha
    mkdir -p "$rd/public/build" "$rd/scripts/deploy"
    : > "$rd/artisan"; : > "$rd/public/index.php"
    printf '{"resources/js/app.js":{"file":"assets/app.js"}}\n' > "$rd/public/build/manifest.json"
    : > "$rd/update.sh"
    : > "$rd/scripts/deploy/rollback.sh"; : > "$rd/scripts/deploy/deploy-status.sh"
    : > "$rd/scripts/deploy/deploy.sh"
    : > "$rd/scripts/deploy/doctor.sh"; : > "$rd/scripts/deploy/repair.sh"
    git -C "$rd" init -q -b main
    git -C "$rd" add -A >/dev/null; git -C "$rd" commit -q -m "$id" >/dev/null
    sha="$(git -C "$rd" rev-parse HEAD)"
    zpd_write_manifest "$rd/RELEASE_MANIFEST.json" "release_id=${id}" "git_sha=${sha}" "result=success"
    zpd_link_shared "$rd" "${BASE}/shared" >/dev/null 2>&1 || true
    printf '%s' "$sha"
}

echo "== ZedProxy deployment tests (Prompt 17: maintenance readiness + bounded probes) =="

# ─────────────────────────────────────────────────────────────────────────────
# Bounded, non-interactive tool-version probes (Defect 2)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- bounded non-interactive probes --"

# 1. A well-behaved probe returns the parsed version token.
assert_eq "$(dep_probe_version "$ZPD_COMPOSER" --version --no-ansi)" "2.8.1" "composer probe returns version token"
assert_eq "$(dep_probe_version "$ZPD_NODE" --version)" "22.9.0" "node probe returns version token"

# 2. A HANGING probe cannot block — bounded by ZPD_PROBE_TIMEOUT → 'unknown'.
HANG="$(mktemp -d)"
printf '#!/usr/bin/env bash\nsleep 60\necho 9.9.9\n' > "${HANG}/composer"; chmod +x "${HANG}/composer"
t0=$SECONDS
out="$(ZPD_PROBE_TIMEOUT=1 dep_probe_version "${HANG}/composer" --version)"
elapsed=$((SECONDS - t0))
assert_eq "$out" "unknown" "hanging probe → unknown"
assert_true test "$elapsed" -lt 8

# 3. A probe that READS STDIN must not block — stdin is /dev/null → returns fast.
READER="$(mktemp -d)"
printf '#!/usr/bin/env bash\nread -r line\necho "got=[$line]"\n' > "${READER}/composer"; chmod +x "${READER}/composer"
t0=$SECONDS
out="$(ZPD_PROBE_TIMEOUT=3 dep_probe_version "${READER}/composer" --version)"
elapsed=$((SECONDS - t0))
assert_eq "$out" "unknown" "stdin-reading probe (fed /dev/null) → unknown, no hang"
assert_true test "$elapsed" -lt 3

# 4. A probe that EXITS NON-ZERO → unknown (never aborts the deploy).
FAILP="$(mktemp -d)"
printf '#!/usr/bin/env bash\nexit 3\n' > "${FAILP}/composer"; chmod +x "${FAILP}/composer"
assert_eq "$(dep_probe_version "${FAILP}/composer" --version)" "unknown" "non-zero probe → unknown"

# 5. MALFORMED output (no version token) → unknown.
MAL="$(mktemp -d)"
printf '#!/usr/bin/env bash\necho "not a version at all"\n' > "${MAL}/composer"; chmod +x "${MAL}/composer"
assert_eq "$(dep_probe_version "${MAL}/composer" --version)" "unknown" "malformed probe output → unknown"
rm -rf "$HANG" "$READER" "$FAILP" "$MAL"

# 6. dep_tool_versions yields all keys even when one probe is missing/hangs.
#    (Written to a file — command substitution strips the NUL delimiters.)
new_base
rd="${BASE}/releases/r1"; mkdir -p "$rd"; git -C "$rd" init -q; git -C "$rd" commit -q --allow-empty -m x >/dev/null
sha="$(git -C "$rd" rev-parse HEAD)"
ZPD_PROBE_TIMEOUT=1 ZPD_COMPOSER="/nonexistent/composer" dep_tool_versions "$rd" "$sha" > "${BASE}/tv.bin"
for k in php_version composer_version node_version npm_version git_tag; do
  tr '\0' '\n' < "${BASE}/tv.bin" | grep -q "^${k}=" && ok "dep_tool_versions has ${k}" || bad "dep_tool_versions missing ${k}"
done
rm -rf "$BASE"

# 7. dep_collect_metadata logs the staged messages and completes.
new_base
rd="${BASE}/releases/r1"; mkdir -p "$rd"; git -C "$rd" init -q; git -C "$rd" commit -q --allow-empty -m x >/dev/null
sha="$(git -C "$rd" rev-parse HEAD)"; mf="${BASE}/meta.out"
log="$(dep_collect_metadata "$rd" "$sha" "$mf" 2>&1)"
printf '%s' "$log" | grep -q 'Collecting release metadata' && ok "logs 'Collecting release metadata...'" || bad "missing collect-start log"
printf '%s' "$log" | grep -q 'Release metadata collected' && ok "logs 'Release metadata collected.'" || bad "missing collect-done log"
assert_true test -s "$mf"
rm -rf "$BASE"

# 8. Global metadata deadline: a child that outlives ZPD_META_TIMEOUT is killed
#    and collection returns promptly with a warning (never blocks activation).
new_base
rd="${BASE}/releases/r1"; mkdir -p "$rd"; git -C "$rd" init -q; git -C "$rd" commit -q --allow-empty -m x >/dev/null
sha="$(git -C "$rd" rev-parse HEAD)"; mf="${BASE}/meta.out"
SLOW="$(mktemp -d)"; printf '#!/usr/bin/env bash\nsleep 30\necho 1.2.3\n' > "${SLOW}/composer"; chmod +x "${SLOW}/composer"
t0=$SECONDS
# ZPD_PROBE_TIMEOUT(30) > ZPD_META_TIMEOUT(1): only the GLOBAL deadline can stop
# it. Redirected to a file (not $(...)) so an orphaned probe holding the pipe
# open cannot make the call appear to block.
ZPD_META_TIMEOUT=1 ZPD_PROBE_TIMEOUT=30 ZPD_COMPOSER="${SLOW}/composer" \
    dep_collect_metadata "$rd" "$sha" "$mf" > "${BASE}/mlog" 2>&1
elapsed=$((SECONDS - t0))
assert_true test "$elapsed" -lt 10
grep -q 'exceeded' "${BASE}/mlog" && ok "global metadata deadline warns on overrun" || bad "no overrun warning"
rm -rf "$SLOW" "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Maintenance-state helpers + honest migration reporting (Defect 1 + migrations)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- maintenance + migration state --"

# 9. zpd_is_in_maintenance detects both the modern file and the legacy 'down'.
new_base
appd="${BASE}/app1"; mkdir -p "${appd}/storage/framework"
assert_false zpd_is_in_maintenance "$appd"
: > "${appd}/storage/framework/maintenance.php"
assert_true zpd_is_in_maintenance "$appd"
rm -f "${appd}/storage/framework/maintenance.php"; : > "${appd}/storage/framework/down"
assert_true zpd_is_in_maintenance "$appd"
rm -rf "$BASE"

# 10. dep_bring_down enters, dep_bring_up exits maintenance (real flag toggled).
new_base
appd="${BASE}/app1"; mkdir -p "${appd}/storage/framework"
dep_bring_down "$appd"; assert_true zpd_is_in_maintenance "$appd"
assert_true dep_bring_up "$appd"; assert_false zpd_is_in_maintenance "$appd"
rm -rf "$BASE"

# 11. dep_bring_up FAILS when `artisan up` returns non-zero.
new_base
appd="${BASE}/app1"; mkdir -p "${appd}/storage/framework"; dep_bring_down "$appd"
( MOCK_UP_RC=1 dep_bring_up "$appd" ); assert_rc 1 "$?" "dep_bring_up fails when artisan up errors"
rm -rf "$BASE"

# 12. dep_bring_up FAILS when maintenance stays STUCK (up returns 0 but flag left).
new_base
appd="${BASE}/app1"; mkdir -p "${appd}/storage/framework"; dep_bring_down "$appd"
( MOCK_UP_STUCK=1 dep_bring_up "$appd" ); assert_rc 1 "$?" "dep_bring_up fails when maintenance flag persists"
assert_true zpd_is_in_maintenance "$appd"
rm -rf "$BASE"

# 13/14/15. Migration-state reporting: none_pending | applied | failed.
new_base; rd="${BASE}/releases/r"; mkdir -p "$rd/storage/framework"
DEP_MIGRATION_STATUS=x; ( MOCK_MIGRATE_MODE=none dep_run_migrations "$rd" ); assert_rc 0 "$?" "migrate none → rc0"
MOCK_MIGRATE_MODE=none dep_run_migrations "$rd"; assert_eq "$DEP_MIGRATION_STATUS" "none_pending" "no pending → none_pending"
MOCK_MIGRATE_MODE=applied dep_run_migrations "$rd"; assert_eq "$DEP_MIGRATION_STATUS" "applied" "ran migrations → applied"
( MOCK_MIGRATE_MODE=fail dep_run_migrations "$rd" >/dev/null 2>&1 ); assert_rc 1 "$?" "migrate failure → rc1"
MOCK_MIGRATE_MODE=fail dep_run_migrations "$rd" >/dev/null 2>&1; assert_eq "$DEP_MIGRATION_STATUS" "failed" "migrate failure → failed"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Bounded CLI health + internal resource checks
# ─────────────────────────────────────────────────────────────────────────────
echo "-- bounded CLI health + resource checks --"

# 16. dep_cli_health passes when healthy, fails on component failure, and TIMES
#     OUT on a hang (bounded, never `|| true`).
new_base; cur="${BASE}/app1"; mkdir -p "$cur"
( MOCK_HEALTH_MODE=ok dep_cli_health "$cur" ); assert_rc 0 "$?" "cli health ok → rc0"
( MOCK_HEALTH_MODE=fail dep_cli_health "$cur" >/dev/null 2>&1 ); assert_rc 1 "$?" "cli health component failure → rc1"
t0=$SECONDS
( MOCK_HEALTH_MODE=hang ZPD_HEALTH_CLI_TIMEOUT=1 dep_cli_health "$cur" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "cli health hang → timeout → rc1"
assert_true test "$elapsed" -lt 8
rm -rf "$BASE"

# 17. dep_check_shared_writable + dep_check_release_files.
new_base
assert_true dep_check_shared_writable "${BASE}/shared"
sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
assert_true dep_check_release_files "$(zpd_current_link)"
rm -f "$(zpd_current_link)/public/build/manifest.json"
assert_false dep_check_release_files "$(zpd_current_link)"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Local loopback health vhost (127.0.0.1; never public)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- loopback health vhost --"

# 18. Default health URL + port are loopback.
assert_eq "$(ZPD_LOCAL_HEALTH_PORT=18080 zpd_local_health_url)" "http://127.0.0.1:18080" "default health URL is loopback:18080"

# 19. dep_ensure_local_health creates a loopback-only vhost on current/public.
new_base
assert_true dep_ensure_local_health "$BASE"
assert_true test -f "$ZPD_LOCAL_HEALTH_CONF"
assert_true zpd_local_health_conf_ok "$ZPD_LOCAL_HEALTH_CONF" "$BASE" 18080
assert_true bash -c "grep -q 'listen 127.0.0.1:18080;' '$ZPD_LOCAL_HEALTH_CONF'"
# 20. A config exposing the port publicly is REJECTED by the validator and
#     REPAIRED by dep_ensure_local_health.
printf 'server {\n  listen 0.0.0.0:18080;\n  root %s/current/public;\n}\n' "$BASE" > "$ZPD_LOCAL_HEALTH_CONF"
assert_false zpd_local_health_conf_ok "$ZPD_LOCAL_HEALTH_CONF" "$BASE" 18080
assert_true dep_ensure_local_health "$BASE"                       # repairs it
assert_true zpd_local_health_conf_ok "$ZPD_LOCAL_HEALTH_CONF" "$BASE" 18080
assert_false bash -c "grep -oE 'listen[[:space:]]+[^;]+' '$ZPD_LOCAL_HEALTH_CONF' | grep -qE '0\.0\.0\.0'"
# 21. A one-line public listen is also caught (position-independent validator).
printf 'server { listen 0.0.0.0:18080; root %s/current/public; }\n' "$BASE" > "${BASE}/oneline.conf"
assert_false zpd_local_health_conf_ok "${BASE}/oneline.conf" "$BASE" 18080
# 22. A validation failure (nginx -t) restores the previous config, returns 1.
setup_atomic_service_configs
printf 'server {\n  listen 127.0.0.1:18080;\n  root %s/current/public;\n}\n' "$BASE" > "$ZPD_LOCAL_HEALTH_CONF"  # already-correct
cp -a "$ZPD_LOCAL_HEALTH_CONF" "${BASE}/good.conf"
# Force a stale config so ensure rewrites, but make nginx -t fail.
printf 'server {\n  listen 127.0.0.1:9999;\n}\n' > "$ZPD_LOCAL_HEALTH_CONF"
( MOCK_NGINX_RC=1 dep_ensure_local_health "$BASE" ); assert_rc 1 "$?" "nginx -t failure fails local-health repair"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Split readiness: internal runs IN maintenance; public HTTP only AFTER `up`
# ─────────────────────────────────────────────────────────────────────────────
echo "-- split readiness (internal vs public HTTP) --"

# 23. Internal readiness passes WHILE the app is in maintenance (no HTTP check).
new_base
sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
dep_bring_down "$(zpd_current_link)"        # app IS in maintenance
assert_true zpd_is_in_maintenance "$(zpd_current_link)"
# Even with the public endpoint returning 503 (maintenance), internal passes.
( MOCK_HTTP_CODE=503 dep_verify_internal_release "$BASE" "20260101000000-aaaaaaaaaaaa" "$sha" ); \
  assert_rc 0 "$?" "internal readiness passes during maintenance (ignores public 503)"
# 24. Internal readiness FAILS when the CLI health check fails.
( MOCK_HEALTH_MODE=fail dep_verify_internal_release "$BASE" "20260101000000-aaaaaaaaaaaa" "$sha" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "internal readiness fails on CLI health failure"
dep_bring_up "$(zpd_current_link)" >/dev/null 2>&1
rm -rf "$BASE"

# 25. Full activation: internal (maintenance) → up → public HTTP → success, and
#     maintenance is actually cleared at the end.
new_base
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( MOCK_HTTP_CODE=200 dep_activate 20260102000000-bbbbbbbbbbbb php8.3-fpm 20260101000000-aaaaaaaaaaaa 0 "$nsha" ); \
  assert_rc 0 "$?" "activation succeeds: internal→up→HTTP"
assert_eq "$(zpd_current_release)" "20260102000000-bbbbbbbbbbbb" "current switched to the new release"
assert_false zpd_is_in_maintenance "$(zpd_current_link)"    # up cleared maintenance
rm -rf "$BASE"

# 26. `up` before HTTP: when exit-maintenance is STUCK, activation fails at the
#     bring-up step (31) — the public HTTP check is NEVER the decider (proven by
#     leaving MOCK_HTTP_CODE=200 which would otherwise pass), and the release is
#     left fenced in maintenance.
new_base
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( MOCK_HTTP_CODE=200 MOCK_UP_STUCK=1 dep_activate 20260102000000-bbbbbbbbbbbb php8.3-fpm 20260101000000-aaaaaaaaaaaa 0 "$nsha" ); \
  assert_rc 31 "$?" "stuck maintenance fails activation at bring-up (HTTP not the decider)"
assert_true zpd_is_in_maintenance "$(zpd_current_link)"     # still fenced
rm -rf "$BASE"

# 27. Public HTTP failure AFTER up → activation 31, and the failed release is
#     put BACK into maintenance so it does not keep serving during rollback.
new_base
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( MOCK_HTTP_CODE=500 dep_activate 20260102000000-bbbbbbbbbbbb php8.3-fpm 20260101000000-aaaaaaaaaaaa 0 "$nsha" ); \
  assert_rc 31 "$?" "public HTTP failure fails activation"
assert_true zpd_is_in_maintenance "$(zpd_current_link)"     # re-fenced for rollback
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Rollback ordering: previous release is brought UP before its HTTP check
# ─────────────────────────────────────────────────────────────────────────────
echo "-- rollback ordering --"

# 28. dep_rollback_code brings the previous release up, then verifies it — a
#     rollback is reported healthy ONLY when the previous release truly is.
new_base
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"; zpd_switch_current 20260102000000-bbbbbbbbbbbb
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( MOCK_HTTP_CODE=200 dep_rollback_code 20260101000000-aaaaaaaaaaaa php8.3-fpm ); \
  assert_rc 0 "$?" "rollback restores + verifies the previous release"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "rolled back to previous release"
assert_false zpd_is_in_maintenance "$(zpd_current_link)"
rm -rf "$BASE"

# 29. Rollback does NOT report success while the previous release is still in
#     maintenance (no false rollback-success): up is stuck → rc1.
new_base
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"; zpd_switch_current 20260102000000-bbbbbbbbbbbb
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
: > "${BASE}/shared/storage/framework/maintenance.php"     # previous is down
( MOCK_HTTP_CODE=200 MOCK_UP_STUCK=1 dep_rollback_code 20260101000000-aaaaaaaaaaaa php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "rollback fails when previous release cannot exit maintenance"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Honest end-to-end reporting: no false DB-restore warning when nothing migrated
# ─────────────────────────────────────────────────────────────────────────────
echo "-- honest migration reporting (full dep_main) --"

# 30. Full deploy, nothing to migrate → success, and the log does NOT warn about
#     the database backup (nothing changed).
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
log="$(MOCK_HTTP_CODE=200 MOCK_MIGRATE_MODE=none dep_main 2>&1)"; rc=$?
assert_rc 0 "$rc" "deploy succeeds (nothing to migrate)"
printf '%s' "$log" | grep -q "$(zpd_msg_migrate_none)" && ok "reports 'no migrations ran'" || bad "missing none-migrated message"
printf '%s' "$log" | grep -q "$(zpd_msg_migrate_applied)" && bad "false DB-restore warning when nothing migrated" || ok "no false DB warning when nothing migrated"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# 31. Full deploy that DID migrate → success message references applied migrations.
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
log="$(MOCK_HTTP_CODE=200 MOCK_MIGRATE_MODE=applied dep_main 2>&1)"; rc=$?
assert_rc 0 "$rc" "deploy succeeds (migrations applied)"
man="${BASE}/releases/$(zpd_current_release)/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" migration_status)" "applied" "manifest records migration_status=applied"
printf '%s' "$log" | grep -q "$(zpd_msg_migrate_applied)" && ok "reports applied-migration guidance" || bad "missing applied-migration message"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Process/interrupt safety
# ─────────────────────────────────────────────────────────────────────────────
echo "-- interrupt safety --"

# 32. An interrupted activation brings the active app OUT of maintenance and
#     exits NON-ZERO (never reports success).
new_base
sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
dep_bring_down "$(zpd_current_link)"; assert_true zpd_is_in_maintenance "$(zpd_current_link)"
( DEP_STAGE="activate"; false; _dep_on_interrupt >/dev/null 2>&1 ); rc=$?
assert_true test "$rc" -ne 0
assert_false zpd_is_in_maintenance "$(zpd_current_link)"   # recovery brought it up
rm -rf "$BASE"

# 33. Deploy lock is released on interruption (a subsequent deploy is not blocked).
new_base
( exec 9>"$ZPD_LOCK_FILE"; flock 9; sleep 3 ) & holder=$!
sleep 0.4
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 200 "$?" "second deploy sees the lock busy"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 0 "$?" "lock is free again after the holder exits"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# update.sh — strict ref handling (retained)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- update.sh strict ref --"
mk_update_repo() {
    local work; work="$(mktemp -d)"; mkdir -p "$work/scripts/deploy"
    : > "$work/update.sh"
    cat > "$work/scripts/deploy/deploy.sh" <<'MARK'
#!/usr/bin/env bash
echo "RAN" > "${1:-/dev/null}"
MARK
    git -C "$work" init -q -b main; git -C "$work" add -A >/dev/null; git -C "$work" commit -q -m c1 >/dev/null
    git -C "$work" tag v9.9.9
    UPD_BARE="$(mktemp -d)/remote.git"; git clone -q --bare "$work" "$UPD_BARE" >/dev/null
    UPD_MAIN_SHA="$(git -C "$work" rev-parse main)"
    rm -rf "$work"
}
mk_update_repo
MARKER="$(mktemp -u)"
NOENV="$(mktemp -u).env"
( ZPD_DEPLOY_ENV="$NOENV" ZPD_REPO_URL="$UPD_BARE" ZPD_REF=main bash "$UPDATE_SH" "$MARKER" >/dev/null 2>&1 )
assert_eq "$(cat "$MARKER" 2>/dev/null)" "RAN" "update.sh runs deploy for a valid ref"
rm -f "$MARKER"
( ZPD_DEPLOY_ENV="$NOENV" ZPD_REPO_URL="$UPD_BARE" ZPD_REF=does-not-exist bash "$UPDATE_SH" "$MARKER" >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "update.sh fails on an invalid ref"
assert_false test -f "$MARKER"
rm -f "$MARKER"
GITMOCK="$(mktemp -d)"
cat > "${GITMOCK}/git" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  ls-remote) echo "1111111111111111111111111111111111111111	refs/heads/main"; exit 0 ;;
  clone)     shift; for a in "$@"; do d="$a"; done; mkdir -p "$d/scripts/deploy"; : > "$d/scripts/deploy/deploy.sh"; exit 0 ;;
  -C)        sub="$3"; if [ "$sub" = "checkout" ]; then exit 1; fi; exit 0 ;;
  *)         exit 0 ;;
esac
EOF
chmod +x "${GITMOCK}/git"
( ZPD_DEPLOY_ENV="$NOENV" ZPD_GIT="${GITMOCK}/git" ZPD_REPO_URL="https://example.invalid/x.git" ZPD_REF=main bash "$UPDATE_SH" "$MARKER" >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "update.sh stops when checkout fails (not ignored)"
assert_false test -f "$MARKER"
rm -rf "$GITMOCK"; rm -f "$MARKER"
rm -rf "$(dirname "$UPD_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Command-wrapper installer (retained)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- command wrappers --"
new_base
old="${ZPD_WRAPPER_BIN}/zedproxy-update"
printf '#!/usr/bin/env bash\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "$old"; chmod +x "$old"
assert_true bash -c "grep -q '${BASE}/scripts/deploy/deploy.sh' '$old'"
assert_true zpw_install_wrappers
assert_false bash -c "grep -q '${BASE}/scripts/deploy/deploy.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_true bash -c "grep -q 'zpd_resolve_script update.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_eq "$(stat -c '%a' "${ZPD_WRAPPER_BIN}/zedproxy-update")" "755" "update wrapper is 0755"
assert_eq "$(stat -c '%a' "${ZPD_WRAPPER_LIB}/bootstrap.sh")" "644" "bootstrap.sh is 0644"
mkdir -p "${BASE}/current/scripts/deploy"; : > "${BASE}/current/update.sh"
: > "${BASE}/current/scripts/deploy/rollback.sh"; : > "${BASE}/current/scripts/deploy/deploy-status.sh"
assert_true zpw_verify_wrappers "$BASE"
resolved="$( cd /root 2>/dev/null || cd /; ZPD_BASE="$BASE"; . "${ZPD_WRAPPER_LIB}/bootstrap.sh"; zpd_resolve_script update.sh )"
assert_eq "$resolved" "${BASE}/current/update.sh" "wrapper resolves current/update.sh from another CWD"
bdir="${BASE}/wbackup"
assert_true zpw_backup_wrappers "$bdir"
printf 'MUTATED' > "${ZPD_WRAPPER_BIN}/zedproxy-update"
assert_true zpw_restore_wrappers "$bdir"
assert_false bash -c "grep -q MUTATED '${ZPD_WRAPPER_BIN}/zedproxy-update'"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Strict Supervisor / scheduler cutover (retained)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- strict cutovers --"
new_base; rm -f "$ZPD_SUPERVISOR_CONF"
assert_true dep_cutover_supervisor "$BASE"
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
rm -rf "$BASE"
new_base
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"
assert_false zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
rm -rf "$BASE"
new_base; setup_legacy_service_configs
( MOCK_SUP_REREAD_RC=1 dep_cutover_supervisor "$BASE" ); assert_rc 1 "$?" "supervisor reread failure fails cutover"
rm -rf "$BASE"
new_base; setup_legacy_service_configs
( MOCK_SUP_UPDATE_RC=1 dep_cutover_supervisor "$BASE" ); assert_rc 1 "$?" "supervisor update failure fails cutover"
rm -rf "$BASE"
new_base
( MOCK_SUP_STATUS="zedproxy-worker:zedproxy-worker_00   FATAL   Exited too quickly" dep_supervisor_group_running ); assert_rc 1 "$?" "FATAL worker group is not running"
( dep_supervisor_group_running ); assert_rc 0 "$?" "RUNNING worker group passes"
rm -rf "$BASE"
new_base; rm -f "$ZPD_SCHED_CRON"
assert_true dep_cutover_scheduler "$BASE"
assert_eq "$(grep -c 'schedule:run' "$ZPD_SCHED_CRON")" "1" "exactly one schedule:run entry"
assert_eq "$(stat -c '%a' "$ZPD_SCHED_CRON")" "644" "cron mode 0644"
rm -rf "$BASE"
new_base
printf '* * * * * www-data php %s/artisan schedule:run\n' "$BASE" > "$ZPD_SCHED_CRON"
assert_false zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Dynamic robots.txt nginx reconciliation (predicate + mutator)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- nginx robots.txt reconciliation --"

# RB1. DECISIVE legacy fixture: an already-installed server whose robots block
#      has NO try_files (static-only quiet 404), plus certbot-managed SSL that
#      the mutator must never touch.
new_base
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    server_name example.com;
    root ${BASE}/current/public;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;
}
server {
    listen 443 ssl; # managed by Certbot
    server_name example.com;
    root ${BASE}/current/public;
    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem; # managed by Certbot
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem; # managed by Certbot
    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot

    location = /robots.txt  { access_log off; log_not_found off; }
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
}
EOF
ssl_before="$(grep 'Certbot\|ssl_certificate\|listen 443' "$ZPD_NGINX_CONF")"
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_eq "$(grep 'Certbot\|ssl_certificate\|listen 443' "$ZPD_NGINX_CONF")" "$ssl_before" "certbot SSL lines byte-identical after repair"
assert_eq "$(grep -c 'location = /robots.txt' "$ZPD_NGINX_CONF")" "2" "both server blocks keep exactly one robots location each"
assert_true dep_validate_nginx
# Idempotent: a second run must not change a single byte.
robots_fixed="$(cat "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$robots_fixed" "second rewrite run is byte-identical (idempotent)"
rm -rf "$BASE"

# RB2. Robots location ABSENT → a fresh block is inserted after the favicon
#      location; already-correct configs are untouched.
new_base
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    root ${BASE}/current/public;
    location = /favicon.ico { access_log off; log_not_found off; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
}
EOF
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_eq "$(grep -c 'location = /robots.txt' "$ZPD_NGINX_CONF")" "1" "exactly one robots location inserted"
assert_true bash -c "grep -A1 'favicon.ico' '$ZPD_NGINX_CONF' | grep -q 'robots.txt'"
robots_fixed="$(cat "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$robots_fixed" "insertion is idempotent (no duplicate block)"
rm -rf "$BASE"

# RB3. Minimal config (no favicon anchor — the deploy-suite fixture shape):
#      the block is inserted after the root directive so reconcile still works.
new_base; setup_atomic_service_configs
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"   # root untouched
rm -rf "$BASE"

# RB4. A MULTI-LINE robots block lacking try_files is patched in place
#      (fallback appended inside the block, not a duplicate block).
new_base
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    root ${BASE}/current/public;
    location = /robots.txt {
        access_log off;
        log_not_found off;
    }
}
EOF
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_eq "$(grep -c 'location = /robots.txt' "$ZPD_NGINX_CONF")" "1" "multi-line block patched, not duplicated"
assert_eq "$(grep -c 'try_files' "$ZPD_NGINX_CONF")" "1" "exactly one fallback added"
rm -rf "$BASE"

# RB5. dep_reconcile_nginx repairs a config whose ROOT is already correct but
#      whose robots block still 404s — and is a no-op once both are correct.
new_base
printf 'server {\n  listen 80;\n  root %s/current/public;\n  location = /robots.txt  { access_log off; log_not_found off; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
( dep_reconcile_nginx "$BASE" >/dev/null 2>&1 ); assert_rc 0 "$?" "reconcile repairs the robots-only drift"
assert_true zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"
robots_fixed="$(cat "$ZPD_NGINX_CONF")"
( dep_reconcile_nginx "$BASE" >/dev/null 2>&1 ); assert_rc 0 "$?" "reconcile is a no-op when already correct"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$robots_fixed" "no-op reconcile changed nothing"
rm -rf "$BASE"

# RB6. A failed nginx -t after the robots rewrite restores the previous config.
new_base
printf 'server {\n  listen 80;\n  root %s/current/public;\n  location = /robots.txt  { access_log off; log_not_found off; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
nginx_before="$(cat "$ZPD_NGINX_CONF")"
( MOCK_NGINX_RC=1 dep_cutover_nginx "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "nginx -t failure fails the robots cutover"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "config restored after failed validation"
rm -rf "$BASE"

# RB8. `try_files $uri =404;` (and any wrong fallback) is NORMALIZED in place,
#      not failed — this was the PR #72 defect where reconciliation failed
#      permanently instead of healing.
new_base
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    root ${BASE}/current/public;
    location = /robots.txt {
        access_log off;
        try_files \$uri =404;
    }
}
EOF
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_eq "$(grep -c 'try_files' "$ZPD_NGINX_CONF")" "1" "wrong fallback replaced, not duplicated"
assert_true bash -c "grep -q 'try_files \$uri /index.php?\$query_string;' '$ZPD_NGINX_CONF'"
assert_false bash -c "grep -q '=404' '$ZPD_NGINX_CONF'"
rm -rf "$BASE"

# RB9. Single-line block with a wrong /index.php fallback is normalized too.
new_base
printf 'server {\n  listen 80;\n  root %s/current/public;\n  location = /robots.txt  { access_log off; try_files $uri /index.php; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
rm -rf "$BASE"

# RB10. A MULTI-LINE favicon block: the fresh robots block must be inserted
#       AFTER its closing brace, never inside it.
new_base
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    root ${BASE}/current/public;
    location = /favicon.ico {
        access_log off;
        log_not_found off;
    }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
}
EOF
assert_true zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_true zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
# The favicon block is intact and closed BEFORE the robots block starts.
fav_close="$(grep -n '^    }' "$ZPD_NGINX_CONF" | head -1 | cut -d: -f1)"
robots_open="$(grep -n 'location = /robots.txt' "$ZPD_NGINX_CONF" | cut -d: -f1)"
assert_true test "$fav_close" -lt "$robots_open"
assert_eq "$(awk '/location = \/favicon\.ico/,/}/' "$ZPD_NGINX_CONF" | grep -c 'robots')" "0" "robots block NOT inserted inside the favicon block"
rm -rf "$BASE"

# RB11. An UNCLOSED robots block is rejected by predicate AND mutator, without
#       mutation (PR #72 predicate wrongly returned success here).
new_base
printf 'server {\n  listen 80;\n  root %s/current/public;\n  location = /robots.txt {\n      access_log off;\n' "$BASE" > "$ZPD_NGINX_CONF"
broken="$(cat "$ZPD_NGINX_CONF")"
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
( zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF" >/dev/null 2>&1 ); assert_rc 1 "$?" "unclosed robots block refused"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$broken" "unclosed block: file NOT mutated"
rm -rf "$BASE"

# RB12. DUPLICATE exact-match robots blocks in ONE server block (an nginx
#       config error) are rejected fail-closed; a regex robots location is
#       never silently modified either.
new_base
printf 'server {\n  root %s/current/public;\n  location = /robots.txt  { access_log off; }\n  location = /robots.txt  { access_log off; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
dup_before="$(cat "$ZPD_NGINX_CONF")"
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
( zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF" >/dev/null 2>&1 ); assert_rc 1 "$?" "duplicate robots blocks refused"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$dup_before" "duplicate blocks: file NOT mutated"
printf 'server {\n  root %s/current/public;\n  location ~ ^/robots { deny all; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
regex_before="$(cat "$ZPD_NGINX_CONF")"
out="$(zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF" 2>&1)"; rc=$?
assert_rc 1 "$rc" "regex robots location refused"
printf '%s' "$out" | grep -q 'unsupported regex' && ok "regex refusal is diagnosed" || bad "regex refusal not diagnosed"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$regex_before" "regex layout: file NOT mutated"
rm -rf "$BASE"

# RB13. Mode AND ownership are preserved through a repair (fail-closed
#       metadata handling, same-directory atomic temp file).
new_base
printf 'server {\n  listen 80;\n  root %s/current/public;\n  location = /robots.txt  { access_log off; log_not_found off; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
chmod 640 "$ZPD_NGINX_CONF"
meta_before="$(stat -c '%a %u:%g' "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF"
assert_eq "$(stat -c '%a %u:%g' "$ZPD_NGINX_CONF")" "$meta_before" "mode and ownership preserved after repair"
assert_eq "$(ls "$(dirname "$ZPD_NGINX_CONF")"/.zpd-nginx.* 2>/dev/null | wc -l)" "0" "no temp file left behind"
rm -rf "$BASE"

# RB14. A temp-file or metadata failure aborts WITHOUT touching the original
#       (fail-closed commit; tests run as root, so simulate via overrides).
new_base
printf 'server {\n  root %s/current/public;\n  location = /robots.txt  { access_log off; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
ro_before="$(cat "$ZPD_NGINX_CONF")"
( zpd_nginx_mktemp() { return 1; }; zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF" >/dev/null 2>&1 ); assert_rc 1 "$?" "temp-file creation failure → repair fails"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$ro_before" "original untouched when the temp file cannot be created"
( zpd_nginx_commit_file() { rm -f "$2"; return 1; }; zpd_nginx_rewrite_robots "$ZPD_NGINX_CONF" >/dev/null 2>&1 ); assert_rc 1 "$?" "metadata/commit failure → repair fails"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$ro_before" "original untouched when metadata preservation fails"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Canonical www→apex routing (predicate + mutator, ACME-exempt, SAN-gated 443)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- nginx www canonical routing --"

cat > "${MOCKBIN}/openssl-match" <<'EOF'
#!/usr/bin/env bash
echo "Hostname www.example.com does match certificate"
EOF
cat > "${MOCKBIN}/openssl-nomatch" <<'EOF'
#!/usr/bin/env bash
echo "Hostname www.example.com does NOT match certificate"
EOF
chmod +x "${MOCKBIN}/openssl-match" "${MOCKBIN}/openssl-nomatch"

# WW1. The historical combined `server_name domain www.domain;` is reconciled:
#      the app block keeps ONLY the apex; a managed www redirect block appears
#      with the ACME exemption BEFORE the catch-all 301 to the literal apex.
new_base
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    server_name example.com www.example.com;
    root ${BASE}/current/public;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
}
EOF
assert_false zpd_nginx_www_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_www "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_www_ok "$ZPD_NGINX_CONF"
assert_false bash -c "grep -E '^[[:space:]]*server_name' '$ZPD_NGINX_CONF' | head -1 | grep -q 'www'"
assert_true bash -c "grep -q 'server_name www.example.com;' '$ZPD_NGINX_CONF'"
assert_true bash -c "grep -q 'return 301 https://example.com\$request_uri;' '$ZPD_NGINX_CONF'"
acme_line="$(grep -n 'acme-challenge' "$ZPD_NGINX_CONF" | cut -d: -f1)"
redir_line="$(grep -n 'return 301 https://example.com' "$ZPD_NGINX_CONF" | cut -d: -f1)"
assert_true test "$acme_line" -lt "$redir_line"      # ACME exemption BEFORE the 301
assert_eq "$(grep -c 'listen 443' "$ZPD_NGINX_CONF")" "0" "no 443 www block without a certificate"
# The app block itself never redirects (no self-redirect loop).
assert_eq "$(awk '/^server/{n++} n==1' "$ZPD_NGINX_CONF" | grep -c 'return 301')" "0" "canonical host does not redirect"
www_fixed="$(cat "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_rewrite_www "$ZPD_NGINX_CONF"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$www_fixed" "second www reconciliation is byte-identical"
rm -rf "$BASE"

# WW2. Certbot server: the 443 www redirect appears ONLY when the ACTUAL
#      certificate covers www; certbot SSL lines stay byte-identical.
new_base
certfile="${BASE}/fullchain.pem"; echo dummy > "$certfile"
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    server_name example.com www.example.com;
    root ${BASE}/current/public;
}
server {
    listen 443 ssl; # managed by Certbot
    server_name example.com www.example.com;
    root ${BASE}/current/public;
    ssl_certificate ${certfile}; # managed by Certbot
    ssl_certificate_key ${BASE}/privkey.pem; # managed by Certbot
    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot
}
EOF
ssl_before="$(grep 'managed by Certbot' "$ZPD_NGINX_CONF")"
( ZPD_OPENSSL="${MOCKBIN}/openssl-match" zpd_nginx_rewrite_www "$ZPD_NGINX_CONF" ); assert_rc 0 "$?" "www rewrite with covering cert succeeds"
assert_true zpd_nginx_www_ok "$ZPD_NGINX_CONF"
assert_eq "$(grep 'managed by Certbot' "$ZPD_NGINX_CONF")" "$ssl_before" "certbot lines byte-identical"
assert_true bash -c "awk '/ZPD-WWW-REDIRECT-BEGIN/,/ZPD-WWW-REDIRECT-END/' '$ZPD_NGINX_CONF' | grep -q 'listen 443 ssl'"
assert_true bash -c "awk '/ZPD-WWW-REDIRECT-BEGIN/,/ZPD-WWW-REDIRECT-END/' '$ZPD_NGINX_CONF' | grep -q 'ssl_certificate ${certfile}'"
rm -rf "$BASE"

# WW3. Same fixture but the certificate does NOT cover www → NO 443 www block
#      (a redirect cannot repair a failed TLS handshake), 80 block still added.
new_base
certfile="${BASE}/fullchain.pem"; echo dummy > "$certfile"
cat > "$ZPD_NGINX_CONF" <<EOF
server {
    listen 80;
    server_name example.com www.example.com;
    root ${BASE}/current/public;
    ssl_certificate ${certfile};
}
EOF
( ZPD_OPENSSL="${MOCKBIN}/openssl-nomatch" zpd_nginx_rewrite_www "$ZPD_NGINX_CONF" ); assert_rc 0 "$?" "www rewrite with non-covering cert succeeds"
assert_true zpd_nginx_www_ok "$ZPD_NGINX_CONF"
assert_eq "$(awk '/ZPD-WWW-REDIRECT-BEGIN/,/ZPD-WWW-REDIRECT-END/' "$ZPD_NGINX_CONF" | grep -c 'listen 443')" "0" "no invalid 443 www block"
assert_true bash -c "awk '/ZPD-WWW-REDIRECT-BEGIN/,/ZPD-WWW-REDIRECT-END/' '$ZPD_NGINX_CONF' | grep -q 'listen 80'"
rm -rf "$BASE"

# WW4. No www anywhere (minimal fixtures) → predicate ok, mutator no-op.
new_base; setup_atomic_service_configs
assert_true zpd_nginx_www_ok "$ZPD_NGINX_CONF"
plain_before="$(cat "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_rewrite_www "$ZPD_NGINX_CONF"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$plain_before" "no-www config untouched"
rm -rf "$BASE"

# WW5. nginx -t failure after a www rewrite restores the original config.
new_base
printf 'server {\n  listen 80;\n  server_name example.com www.example.com;\n  root %s/current/public;\n  location = /robots.txt { access_log off; log_not_found off; try_files $uri /index.php?$query_string; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
nginx_before="$(cat "$ZPD_NGINX_CONF")"
( MOCK_NGINX_RC=1 dep_cutover_nginx "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "nginx -t failure fails the www cutover"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "config restored after failed validation"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Managed gzip segment (predicate + mutator)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- nginx gzip reconciliation --"

# GZ1. Missing gzip entirely → managed segment inserted into the app block:
#      correct types, gzip_vary on, no text/html, no compressed formats.
new_base; setup_atomic_service_configs
assert_false zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_gzip "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"
assert_true bash -c "grep -q 'gzip_vary on;' '$ZPD_NGINX_CONF'"
assert_true bash -c "grep -q 'application/javascript' '$ZPD_NGINX_CONF'"
assert_false bash -c "grep 'gzip_types' '$ZPD_NGINX_CONF' | grep -q 'text/html'"
assert_false bash -c "grep 'gzip_types' '$ZPD_NGINX_CONF' | grep -q 'woff2'"
gzip_fixed="$(cat "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_rewrite_gzip "$ZPD_NGINX_CONF"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$gzip_fixed" "second gzip run is byte-identical"
rm -rf "$BASE"

# GZ2. An INCOMPLETE managed segment is normalized back to canonical.
new_base; setup_atomic_service_configs
zpd_nginx_rewrite_gzip "$ZPD_NGINX_CONF" >/dev/null
sed -i '/gzip_vary on;/d' "$ZPD_NGINX_CONF"                      # simulate drift
assert_false zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_rewrite_gzip "$ZPD_NGINX_CONF"
assert_true  zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"
assert_eq "$(grep -c 'gzip_vary on;' "$ZPD_NGINX_CONF")" "1" "gzip_vary restored exactly once"
rm -rf "$BASE"

# GZ3. Custom operator gzip directives are NEVER duplicated or modified.
new_base
printf 'server {\n  listen 80;\n  root %s/current/public;\n  gzip on;\n  gzip_types text/css;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
custom_before="$(cat "$ZPD_NGINX_CONF")"
assert_true zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"      # operator-managed, not drift
assert_true zpd_nginx_rewrite_gzip "$ZPD_NGINX_CONF"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$custom_before" "custom gzip config untouched"
rm -rf "$BASE"

# GZ4. The managed www redirect-only block stays minimal: no gzip inside it.
new_base
printf 'server {\n  listen 80;\n  server_name example.com www.example.com;\n  root %s/current/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
assert_true zpd_nginx_rewrite_www "$ZPD_NGINX_CONF"
assert_true zpd_nginx_rewrite_gzip "$ZPD_NGINX_CONF"
assert_eq "$(awk '/ZPD-WWW-REDIRECT-BEGIN/,/ZPD-WWW-REDIRECT-END/' "$ZPD_NGINX_CONF" | grep -c 'gzip')" "0" "redirect-only block has no gzip"
assert_true zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"
rm -rf "$BASE"

# RB7. repair --scan reports the robots drift (read-only), --apply --nginx fixes it.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
printf 'server {\n  listen 80;\n  root %s/current/public;\n  location = /robots.txt  { access_log off; log_not_found off; }\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
out="$(zrp_main --scan 2>&1)" || true
printf '%s' "$out" | grep -q 'robots.txt location lacks the Laravel fallback' && ok "scan reports the robots drift" || bad "scan missed the robots drift"
printf '%s' "$out" | grep -q 'gzip is not enabled' && ok "scan reports the gzip drift" || bad "scan missed the gzip drift"
assert_false zpd_nginx_robots_ok "$ZPD_NGINX_CONF"     # scan is read-only
assert_false zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"       # scan is read-only
( MOCK_HTTP_CODE=200 zrp_main --apply --nginx >/dev/null 2>&1 ); assert_rc 0 "$?" "--apply --nginx repairs the robots block"
assert_true zpd_nginx_robots_ok "$ZPD_NGINX_CONF"
assert_true zpd_nginx_gzip_ok "$ZPD_NGINX_CONF"
assert_true zpd_nginx_www_ok "$ZPD_NGINX_CONF"
out="$(zrp_main --scan 2>&1)" || true
printf '%s' "$out" | grep -q 'robots.txt location reaches Laravel' && ok "scan reports robots ok after repair" || bad "scan does not report robots ok"
printf '%s' "$out" | grep -q 'www host routing is canonical' && ok "scan reports www state" || bad "scan missing the www line"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Full legacy → atomic first cutover (retained success + failure paths)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- legacy first cutover --"
new_base; mk_source_repo; make_legacy_base; setup_legacy_service_configs
printf '#!/usr/bin/env bash\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "${ZPD_WRAPPER_BIN}/zedproxy-update"
chmod +x "${ZPD_WRAPPER_BIN}/zedproxy-update"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
assert_true zpd_is_legacy_layout
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "first legacy cutover succeeds"
assert_true zpd_is_atomic_layout
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_false bash -c "grep -q '${BASE}/scripts/deploy/deploy.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_true zpw_verify_wrappers "$BASE"
assert_true test -f "${BASE}/shared/storage/app/public/u.txt"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

new_base; mk_source_repo; make_legacy_base; setup_legacy_service_configs
printf '#!/usr/bin/env bash\n# OLD LEGACY WRAPPER\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "${ZPD_WRAPPER_BIN}/zedproxy-update"
chmod +x "${ZPD_WRAPPER_BIN}/zedproxy-update"
nginx_before="$(cat "$ZPD_NGINX_CONF")"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_SUP_REREAD_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "supervisor reread failure fails the first cutover"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "legacy nginx restored after supervisor failure"
assert_false test -L "${BASE}/current"
assert_true bash -c "grep -q 'OLD LEGACY WRAPPER' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Normal (already-atomic) update path (retained) + arbitrary CWD
# ─────────────────────────────────────────────────────────────────────────────
echo "-- normal atomic updates --"
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "already-atomic first release deploys"
first="$(zpd_current_release)"
setup_atomic_service_configs; sleep 1
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "normal second update deploys"
assert_true zpw_verify_wrappers "$BASE"
assert_false bash -c "[ '$first' = '$(zpd_current_release)' ]"
man="${BASE}/releases/$(zpd_current_release)/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" git_sha)" "$SRC_MAIN_SHA" "manifest git_sha == deployed HEAD"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( cd / && MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "deploy from / succeeds"
assert_false bash -c "zpd_current_release | grep -q nogit"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# PostgreSQL / Redis readiness gate (retained)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- pg/redis readiness --"
new_base
( MOCK_PSQL_RC=1 dep_check_pg "${BASE}/shared/.env" ); assert_rc 1 "$?" "PG connectivity failure detected"
( MOCK_REDIS_PONG=NOPE dep_check_redis "${BASE}/shared/.env" ); assert_rc 1 "$?" "Redis non-PONG detected"
( dep_check_pg "${BASE}/shared/.env" ); assert_rc 0 "$?" "PG connectivity OK"
( dep_check_redis "${BASE}/shared/.env" ); assert_rc 0 "$?" "Redis PONG OK"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Retained coverage (resolution, build codes, rollback target, masking)
# ─────────────────────────────────────────────────────────────────────────────
echo "-- retained core coverage --"
new_base
assert_eq "$(unset ZPD_REPO_URL; zpd_resolve_repo_url)" "https://github.com/Mhoseinshah1/zed_web.git" "unset repo → safe public default"
assert_false zpd_is_safe_repo_url "."
assert_true  zpd_is_safe_repo_url "https://github.com/o/r.git"
rm -rf "$BASE"

mk_source_repo
assert_eq "$(zpd_resolve_sha "$SRC_BARE" main)" "$SRC_MAIN_SHA" "resolve branch"
assert_eq "$(zpd_resolve_sha "$SRC_BARE" v1.0.0)" "$SRC_TAG_SHA" "resolve lightweight tag"
assert_eq "$(zpd_resolve_sha "$SRC_BARE" v2.0.0)" "$SRC_ATAG_SHA" "resolve annotated tag (peeled)"
( zpd_resolve_sha "$SRC_BARE" nope >/dev/null ); assert_rc 1 "$?" "missing ref fails"
rm -rf "$(dirname "$SRC_BARE")"

( zpd_release_id "" >/dev/null ); assert_rc 1 "$?" "empty SHA → no id"

new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"; before="$(zpd_current_release)"
setup_atomic_service_configs
export ZPD_REPO_URL="https://nonexistent.invalid.example/x.git" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "clone failure fails deploy"
assert_eq "$(zpd_current_release)" "$before" "live release untouched on clone failure"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE"

new_base; rm -f "${BASE}/shared/.env"; assert_false dep_preflight "${BASE}/shared"; rm -rf "$BASE"
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; ( dep_build "$rel" ); assert_rc 9 "$?" "missing package-lock → 9"; rm -rf "$BASE"
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; : > "$rel/package-lock.json"; ( MOCK_NPM_CI_RC=1 dep_build "$rel" ); assert_rc 11 "$?" "npm ci → 11"; rm -rf "$BASE"

new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
mk_release "20260102000000-bbbbbbbbbbbb" activating; before="$(zpd_current_release)"
( MOCK_MIGRATE_MODE=fail dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "$before" 0 "" ); assert_rc 30 "$?" "migration failure → 30"
assert_eq "$(zpd_current_release)" "$before" "symlink not switched on migration failure"
rm -rf "$BASE"

new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; mk_release "20260102000000-bbbbbbbbbbbb" success
zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(rb_default_target)" "20260101000000-aaaaaaaaaaaa" "default rollback target is previous healthy"
rm -rf "$BASE"

new_base
zpd_write_manifest "${BASE}/m.json" "note=connect postgres://u:supersecret@db:5432/x PGPASSWORD=topsecret"
assert_false bash -c "grep -q supersecret '${BASE}/m.json'"
assert_false bash -c "grep -q topsecret '${BASE}/m.json'"
rm -rf "$BASE"

# ═════════════════════════════════════════════════════════════════════════════
# Prompt 18 — self-healing reconciliation, adoption, state repair, diagnostics
# ═════════════════════════════════════════════════════════════════════════════

# mk_historical_release ID — a REAL healthy release (git repo, Laravel files,
# shared links) WITHOUT any manifest — exactly what a pre-manifest install left.
mk_historical_release() {
    local id="$1"
    local rd="${BASE}/releases/${id}"
    mkdir -p "$rd/public/build" "$rd/scripts/deploy"
    : > "$rd/artisan"; : > "$rd/public/index.php"
    printf '{"resources/js/app.js":{"file":"assets/app.js"}}\n' > "$rd/public/build/manifest.json"
    : > "$rd/update.sh"
    : > "$rd/scripts/deploy/rollback.sh"; : > "$rd/scripts/deploy/deploy-status.sh"
    : > "$rd/scripts/deploy/doctor.sh";   : > "$rd/scripts/deploy/repair.sh"
    git -C "$rd" init -q -b main
    git -C "$rd" add -A >/dev/null; git -C "$rd" commit -q -m "$id" >/dev/null
    zpd_link_shared "$rd" "${BASE}/shared" >/dev/null 2>&1 || true
    git -C "$rd" rev-parse HEAD
}

echo "-- scheduler discovery + reconciliation --"

# S1. Atomic layout with a MISSING scheduler cron → repaired.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
assert_true dep_reconcile_scheduler "$BASE"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
rm -rf "$BASE"

# S2. Atomic layout with a LEGACY scheduler path → repaired to current/.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
printf '* * * * * www-data php %s/artisan schedule:run\n' "$BASE" > "$ZPD_SCHED_CRON"
assert_true dep_reconcile_scheduler "$BASE"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_false bash -c "grep -qE 'php ${BASE}/artisan schedule:run' '$ZPD_SCHED_CRON'"
rm -rf "$BASE"

# S3. DUPLICATE entries in the canonical file → single canonical entry.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
{ zpd_scheduler_cron_content "$BASE"; zpd_scheduler_cron_content "$BASE"; } > "$ZPD_SCHED_CRON"
assert_true dep_reconcile_scheduler "$BASE"
assert_eq "$(grep -c 'schedule:run' "$ZPD_SCHED_CRON")" "1" "duplicate scheduler entries collapsed to one"
rm -rf "$BASE"

# S4. Scheduler configured in ANOTHER cron.d file → removed there, canonical created.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * www-data php %s/current/artisan schedule:run\n' "$BASE" > "${ZPD_CRON_D_DIR}/oldfile"
assert_true dep_reconcile_scheduler "$BASE"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_false bash -c "grep -q 'schedule:run' '${ZPD_CRON_D_DIR}/oldfile' 2>/dev/null"
rm -rf "$BASE"

# S5. Scheduler in /etc/crontab (root system crontab) → OUR line removed,
#     unrelated cron jobs preserved line-by-line.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * root php %s/artisan schedule:run\n17 * * * * root cd / && run-parts /etc/cron.hourly\n' "$BASE" > "$ZPD_ETC_CRONTAB"
assert_true dep_reconcile_scheduler "$BASE"
assert_false bash -c "grep -q 'schedule:run' '$ZPD_ETC_CRONTAB'"
assert_true bash -c "grep -q 'run-parts' '$ZPD_ETC_CRONTAB'"   # unrelated job preserved
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
rm -rf "$BASE"

# S6. CONFLICTING unmanaged entry (user spool crontab) → clear failure, nothing deleted.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
printf '* * * * * php %s/current/artisan schedule:run\n' "$BASE" > "${ZPD_CRON_SPOOL_DIR}/root"
out="$(dep_reconcile_scheduler "$BASE" 2>&1)"; rc=$?
assert_rc 1 "$rc" "conflicting user-crontab scheduler entry aborts reconciliation"
printf '%s' "$out" | grep -q "$(zpd_msg_sched_conflict)" && ok "conflict abort shows the Persian diagnostic" || bad "missing conflict diagnostic"
assert_true test -s "${ZPD_CRON_SPOOL_DIR}/root"   # user content NOT deleted
rm -rf "$BASE"

echo "-- self-healing during a NORMAL (non-legacy) update --"

# S7-S10 + idempotency: an atomic install with a missing scheduler cron, legacy
# supervisor config, legacy nginx root, and an OLD broken wrapper self-heals on
# a normal dep_main run (operational reconciliation is NOT gated on legacy=1).
new_base; mk_source_repo
sha0="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
printf 'server {\n  listen 80;\n  root %s/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"          # legacy nginx root
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"  # legacy supervisor
rm -f "$ZPD_SCHED_CRON"                                                                     # missing scheduler
printf '#!/usr/bin/env bash\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "${ZPD_WRAPPER_BIN}/zedproxy-update"  # old wrapper
chmod +x "${ZPD_WRAPPER_BIN}/zedproxy-update"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "normal update self-heals a partially migrated install"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"                # S7 scheduler repaired
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"               # S8 supervisor repaired
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"                    # S9 nginx repaired
assert_true bash -c "grep -q 'zpd_resolve_script update.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"  # S10 wrappers
assert_true test -x "${ZPD_WRAPPER_BIN}/zedproxy-doctor"
assert_true test -x "${ZPD_WRAPPER_BIN}/zedproxy-deploy-repair"
# S11. Idempotent: a second reconcile leaves the (already canonical) files as-is.
cron_before="$(cat "$ZPD_SCHED_CRON")"; sup_before="$(cat "$ZPD_SUPERVISOR_CONF")"
assert_true dep_reconcile_operational "$BASE" idem-check
assert_eq "$(cat "$ZPD_SCHED_CRON")" "$cron_before" "scheduler reconcile is idempotent"
assert_eq "$(cat "$ZPD_SUPERVISOR_CONF")" "$sup_before" "supervisor reconcile is idempotent"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# S12. FAILED reconciliation restores the per-release operational snapshot.
# BOTH nginx and supervisor start legacy; the supervisor repair's reread fails
# → the already-repaired nginx must be restored to its pre-deploy content.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
printf 'server {\n  listen 80;\n  root %s/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"                       # legacy nginx
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"  # legacy supervisor
nginx_before="$(cat "$ZPD_NGINX_CONF")"; sup_before="$(cat "$ZPD_SUPERVISOR_CONF")"
( MOCK_SUP_REREAD_RC=1 dep_reconcile_operational "$BASE" 20260101000000-aaaaaaaaaaaa >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "reconciliation failure (supervisor reread) fails"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "failed reconcile restored the nginx snapshot"
assert_eq "$(cat "$ZPD_SUPERVISOR_CONF")" "$sup_before" "failed reconcile restored the supervisor snapshot"
rm -rf "$BASE"

echo "-- historical release adoption + rollback compatibility --"

# A13. Historical active release WITHOUT a manifest → adopted from observed facts.
new_base
hsha="$(mk_historical_release 20260724200918-829baf51f244)"; zpd_switch_current 20260724200918-829baf51f244
assert_eq "$(dep_release_verify_mode 20260724200918-829baf51f244)" "historical" "pre-manifest release detected as historical"
dep_adopt_current_release >/dev/null 2>&1
man="${BASE}/releases/20260724200918-829baf51f244/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" result)" "adopted" "adopted manifest written"
assert_eq "$(zpd_manifest_get "$man" git_sha)" "$hsha" "adoption records the OBSERVED git HEAD (never invented)"
assert_eq "$(zpd_manifest_get "$man" migration_status)" "unknown" "adoption records migration_status=unknown"
assert_true bash -c "[ -n \"$(zpd_manifest_get "$man" manifest_schema_version)\" ]"
# A15b. A valid modern manifest is NEVER overwritten by adoption.
zpd_write_manifest "$man" "release_id=20260724200918-829baf51f244" "git_sha=${hsha}" "result=success" "manifest_schema_version=2"
dep_adopt_current_release >/dev/null 2>&1
assert_eq "$(zpd_manifest_get "$man" result)" "success" "modern manifest not overwritten by adoption"
rm -rf "$BASE"

# A14. INCOMPLETE manifest (no sha, no schema) → adoption backfills it.
new_base
hsha="$(mk_historical_release 20260724200918-829baf51f244)"; zpd_switch_current 20260724200918-829baf51f244
zpd_write_manifest "${BASE}/releases/20260724200918-829baf51f244/RELEASE_MANIFEST.json" \
    "release_id=20260724200918-829baf51f244" "result="
dep_adopt_current_release >/dev/null 2>&1
assert_eq "$(zpd_manifest_get "${BASE}/releases/20260724200918-829baf51f244/RELEASE_MANIFEST.json" result)" "adopted" "incomplete manifest backfilled via adoption"
rm -rf "$BASE"

# A16. INVALID MODERN manifest still fails strict verification (never weakened).
new_base
sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
zpd_write_manifest "${BASE}/releases/20260101000000-aaaaaaaaaaaa/RELEASE_MANIFEST.json" \
    "release_id=20260101000000-aaaaaaaaaaaa" "git_sha=0000000000000000000000000000000000000000" \
    "result=success" "manifest_schema_version=2"
( MOCK_HTTP_CODE=200 dep_verify_internal_release "$BASE" 20260101000000-aaaaaaaaaaaa "" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "modern manifest with a wrong SHA still FAILS (strict)"
# A16b. A modern-schema manifest with a MISSING SHA is also strict → fails.
zpd_write_manifest "${BASE}/releases/20260101000000-aaaaaaaaaaaa/RELEASE_MANIFEST.json" \
    "release_id=20260101000000-aaaaaaaaaaaa" "result=success" "manifest_schema_version=2"
assert_eq "$(dep_release_verify_mode 20260101000000-aaaaaaaaaaaa)" "strict" "modern manifest without SHA stays strict"
rm -rf "$BASE"

# A17/A18. Rollback to an ADOPTED historical release works; a missing old
# manifest alone never fails the rollback.
new_base
hsha="$(mk_historical_release 20260724200918-829baf51f244)"; zpd_switch_current 20260724200918-829baf51f244
dep_adopt_current_release >/dev/null 2>&1
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"; zpd_switch_current 20260102000000-bbbbbbbbbbbb
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( MOCK_HTTP_CODE=200 dep_rollback_code 20260724200918-829baf51f244 php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "rollback to the ADOPTED historical release passes full verification"
assert_eq "$(zpd_current_release)" "20260724200918-829baf51f244" "adopted release is active after rollback"
rm -rf "$BASE"

new_base
hsha="$(mk_historical_release 20260724200918-829baf51f244)"; zpd_switch_current 20260724200918-829baf51f244
nsha="$(mk_release_git 20260102000000-bbbbbbbbbbbb)"; zpd_switch_current 20260102000000-bbbbbbbbbbbb
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
# NO adoption ran — the historical release has NO manifest at all.
( MOCK_HTTP_CODE=200 dep_rollback_code 20260724200918-829baf51f244 php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "rollback does not fail solely because an old manifest is absent"
rm -rf "$BASE"

echo "-- state-file repair + status fallback --"

# ST19/20/21. Missing state file → created; inconsistent state → repaired.
new_base
sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
rm -f "$ZPD_STATE_FILE"
assert_true dep_reconcile_state
assert_eq "$(zpd_manifest_get "$ZPD_STATE_FILE" active_release)" "20260101000000-aaaaaaaaaaaa" "missing state file rebuilt from current symlink"
zpd_write_manifest "$ZPD_STATE_FILE" "active_release=20269999999999-attempted" "result=success"
assert_true dep_reconcile_state
assert_eq "$(zpd_manifest_get "$ZPD_STATE_FILE" active_release)" "20260101000000-aaaaaaaaaaaa" "stale state repaired to the current symlink (never the attempted release)"
assert_eq "$(zpd_manifest_get "$ZPD_STATE_FILE" git_sha)" "$sha" "state records the real SHA"
rm -rf "$BASE"

# ST22. deploy-status falls back to OBSERVED git facts for a manifest-less release.
new_base
hsha="$(mk_historical_release 20260724200918-829baf51f244)"; zpd_switch_current 20260724200918-829baf51f244
out="$(ds_report)"
printf '%s' "$out" | grep -q "$hsha" && ok "status shows the observed git SHA" || bad "status missing observed SHA"
printf '%s' "$out" | grep -q "(observed" && ok "status marks the SHA as observed" || bad "status does not mark observed source"
printf '%s' "$out" | grep -q "recovered" && ok "status reports result=recovered (not silently unknown)" || bad "status missing recovered result"
printf '%s' "$out" | grep -qi "WARNING" && ok "status warns about incomplete historical metadata" || bad "status missing historical warning"
rm -rf "$BASE"

echo "-- failed-release finalization --"

# F23/F24/F25. A failed activation ALWAYS finalizes the attempted release — and
# a rollback whose switch succeeded but whose readiness failed is recorded so.
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
# HTTP fails for the NEW release AND stays failed → rollback readiness fails too.
( MOCK_HTTP_CODE=500 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "deploy with permanently failing HTTP fails"
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | head -1)"
assert_true test -n "$attempted"
fman="${BASE}/releases/${attempted}/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$fman" result)" "failed" "attempted release finalized result=failed"
assert_true bash -c "[ -n \"$(zpd_manifest_get "$fman" failure_stage)\" ]"
assert_eq "$(zpd_manifest_get "$fman" rollback_switch)" "success" "rollback SWITCH recorded as success"
assert_eq "$(zpd_manifest_get "$fman" rollback_readiness)" "failed" "rollback READINESS recorded separately as failed"
assert_eq "$(zpd_manifest_get "$fman" active_release_after_failure)" "20260101000000-aaaaaaaaaaaa" "active-after-failure recorded"
# F25: no release (failed or otherwise) remains 'activating'.
n_act=0
for m in "$BASE"/releases/*/RELEASE_MANIFEST.json; do
    [ -f "$m" ] || continue
    [ "$(zpd_manifest_get "$m" result)" = "activating" ] && n_act=$((n_act + 1))
done
assert_eq "$n_act" "0" "no release remains marked 'activating' after a failure"
# F45: the failure path released the deploy lock.
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 0 "$?" "deploy lock free after the failed deployment"
# F38: an automatic redacted diagnostic bundle was produced.
bundle="$(ls "$BASE"/logs/diagnostics/zedproxy-diagnostic-*.tar.gz 2>/dev/null | head -1)"
assert_true test -n "$bundle"
assert_eq "$(stat -c '%a' "$bundle")" "600" "automatic failure bundle is mode 600"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# F47/F48. Multiple consecutive failed deployments remain recoverable, and a
# later good deployment succeeds normally.
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=500 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "failed deploy #1"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "previous release still active after failure #1"
sleep 1
( MOCK_HTTP_CODE=500 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "failed deploy #2"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "previous release still active after failure #2"
sleep 1
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "deployment after consecutive failures succeeds"
assert_false bash -c "[ \"$(zpd_current_release)\" = '20260101000000-aaaaaaaaaaaa' ]"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

echo "-- repair command --"

# R26. --scan is READ-ONLY.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"     # broken scheduler
before_tree="$(find "$BASE" -type f | sort | md5sum)"
out="$(zrp_main --scan 2>&1)"; rc=$?
assert_rc 0 "$rc" "repair --scan exits 0"
printf '%s' "$out" | grep -q 'FIX' && ok "scan reports the repairable scheduler" || bad "scan missed the broken scheduler"
assert_eq "$(find "$BASE" -type f | sort | md5sum)" "$before_tree" "repair --scan changed NOTHING (read-only)"
# R27/R28/R29. --apply repairs, backs up, and never touches .env/DB/APP_KEY.
env_before="$(md5sum "${BASE}/shared/.env")"
MOCK_LOG="${BASE}/php-invocations.log"
( export MOCK_LOG; MOCK_HTTP_CODE=200 zrp_main --apply >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "repair --apply succeeds"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_eq "$(md5sum "${BASE}/shared/.env")" "$env_before" "repair preserved .env (and its APP_KEY) byte-for-byte"
assert_true bash -c "grep -q 'APP_KEY=base64:AAAABBBB' '${BASE}/shared/.env'"
assert_false bash -c "grep -q 'artisan migrate' '$MOCK_LOG'"   # repair NEVER runs migrations
assert_true bash -c "ls -d ${BASE}/shared/deploy/snapshots/repair-* >/dev/null 2>&1"   # backup taken
rm -rf "$BASE"

echo "-- doctor command --"

# D30-33. Doctor detects scheduler mismatch, manifest mismatch, stale state, lock.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
rm -f "$ZPD_SCHED_CRON"
zpd_write_manifest "${BASE}/releases/20260101000000-aaaaaaaaaaaa/RELEASE_MANIFEST.json" \
    "release_id=20260101000000-aaaaaaaaaaaa" "git_sha=1111111111111111111111111111111111111111" "result=success"
zpd_write_manifest "$ZPD_STATE_FILE" "active_release=some-other-release" "result=success"
out="$(MOCK_HTTP_CODE=200 zdr_main 2>&1)" || true
printf '%s' "$out" | grep -Eq 'scheduler_cron\s+fail' && ok "doctor detects the scheduler mismatch" || bad "doctor missed scheduler mismatch"
printf '%s' "$out" | grep -Eq 'active_manifest\s+fail' && ok "doctor detects the manifest/HEAD mismatch" || bad "doctor missed manifest mismatch"
printf '%s' "$out" | grep -Eq 'state_file\s+fail' && ok "doctor detects the stale state file" || bad "doctor missed stale state"
# D33: a held deploy lock is reported.
( exec 9>"$ZPD_LOCK_FILE"; flock 9; sleep 2 ) & holder=$!
sleep 0.3
out="$(MOCK_HTTP_CODE=200 zdr_main 2>&1)" || true
printf '%s' "$out" | grep -Eq 'deploy_lock\s+warn\s+held' && ok "doctor detects the held deployment lock" || bad "doctor missed the held lock"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
# Doctor default mode is READ-ONLY.
before_tree="$(find "$BASE" -type f | sort | md5sum)"
MOCK_HTTP_CODE=200 zdr_main >/dev/null 2>&1 || true
assert_eq "$(find "$BASE" -type f | sort | md5sum)" "$before_tree" "doctor default mode changed NOTHING (read-only)"
rm -rf "$BASE"

# D34-37. Bundle: mode 600, no canary secrets, no .env, no cookies.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
printf 'APP_KEY=base64:ZP_CANARY_APPKEY_deadbeef01=\nDB_PASSWORD=ZP_CANARY_DB_SECRET_deadbeef02\n' > "${BASE}/shared/.env"
printf '[x] deploy log line PGPASSWORD=ZP_CANARY_DB_SECRET_deadbeef02 done\nSet-Cookie: zedproxy-session=abc\n' > "${BASE}/logs/deploy.log"
bundle="$(MOCK_HTTP_CODE=200 zdr_main --bundle 2>/dev/null | tail -1)"
assert_true test -f "$bundle"
assert_eq "$(stat -c '%a' "$bundle")" "600" "diagnostic bundle is mode 600"
exdir="$(mktemp -d)"; tar -xzf "$bundle" -C "$exdir" 2>/dev/null
assert_false bash -c "grep -Rq 'ZP_CANARY_DB_SECRET_deadbeef02' '$exdir'"   # canary redacted
assert_false bash -c "find '$exdir' -name '.env*' | grep -q ."               # no .env file
assert_false bash -c "grep -Rq 'zedproxy-session=abc' '$exdir'"              # no raw cookies
rm -rf "$exdir" "$BASE"

echo "-- bounded smoke + service timeouts + composer policy --"

# T39/T40. dep_smoke is BOUNDED — a hanging zedproxy:health times out fast.
t0=$SECONDS
new_base; rd="${BASE}/releases/r"; mkdir -p "$rd"
( MOCK_HEALTH_MODE=hang ZPD_HEALTH_CLI_TIMEOUT=1 dep_smoke "$rd" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "hanging smoke health fails (timeout) before activation"
assert_true test "$elapsed" -lt 10
( MOCK_HEALTH_MODE=fail dep_smoke "$rd" >/dev/null 2>&1 ); assert_rc 1 "$?" "smoke failure is NOT discarded"
( dep_smoke "$rd" >/dev/null 2>&1 ); assert_rc 0 "$?" "healthy smoke passes"
rm -rf "$BASE"

# T41. Hanging supervisorctl is bounded by ZPD_SVC_TIMEOUT.
t0=$SECONDS
( MOCK_SUP_HANG=1 ZPD_SVC_TIMEOUT=1 dep_restart_workers >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 124 "$rc" "hanging supervisorctl times out"
assert_true test "$elapsed" -lt 10

# T42. Hanging nginx -t is bounded.
t0=$SECONDS
( MOCK_NGINX_HANG=1 ZPD_SVC_TIMEOUT=1 dep_validate_nginx >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 124 "$rc" "hanging nginx -t times out"
assert_true test "$elapsed" -lt 10

# T43. Composer root behavior is DETERMINISTIC: the build subprocess sets
# COMPOSER_ALLOW_SUPERUSER=1, so a root-sensitive composer works identically.
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"
: > "$rel/composer.lock"; : > "$rel/package-lock.json"
( MOCK_COMPOSER_REQUIRE_SUPERUSER=1 dep_build "$rel" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "composer superuser policy is set inside the build subprocess"
rm -rf "$BASE"

echo "-- production reproduction fixture --"

# The EXACT production state: current → healthy historical release with no
# manifest and no state metadata; a stale attempted release stuck 'activating';
# the canonical scheduler cron missing with a legacy /etc/crontab entry. One
# normal update must adopt, reconcile, finalize, deploy, and stay rollbackable.
new_base; mk_source_repo
hsha="$(mk_historical_release 20260724200918-829baf51f244)"
zpd_switch_current 20260724200918-829baf51f244
setup_atomic_service_configs
rm -f "$ZPD_SCHED_CRON" "$ZPD_STATE_FILE"
printf '* * * * * root php %s/artisan schedule:run\n' "$BASE" > "$ZPD_ETC_CRONTAB"   # legacy scheduler source
mkdir -p "${BASE}/releases/20260725053735-4be1d78f39df"
zpd_write_manifest "${BASE}/releases/20260725053735-4be1d78f39df/RELEASE_MANIFEST.json" \
    "release_id=20260725053735-4be1d78f39df" "result=activating"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "PRODUCTION FIXTURE: repaired updater deploys successfully"
newrel="$(zpd_current_release)"
assert_false bash -c "[ '$newrel' = '20260724200918-829baf51f244' ]"
# 1. historical release adopted
assert_eq "$(zpd_manifest_get "${BASE}/releases/20260724200918-829baf51f244/RELEASE_MANIFEST.json" result)" "adopted" "historical active release was adopted"
# 2. scheduler reconciled (canonical file, legacy source cleaned)
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_false bash -c "grep -q 'schedule:run' '$ZPD_ETC_CRONTAB'"
# 3. accurate state metadata
assert_eq "$(zpd_manifest_get "$ZPD_STATE_FILE" active_release)" "$newrel" "state file records the new active release"
assert_eq "$(zpd_manifest_get "$ZPD_STATE_FILE" result)" "success" "state records success"
# 9. new release marked successful
assert_eq "$(zpd_manifest_get "${BASE}/releases/${newrel}/RELEASE_MANIFEST.json" result)" "success" "new release manifest is success"
# 10. no stale activating release remains
assert_eq "$(zpd_manifest_get "${BASE}/releases/20260725053735-4be1d78f39df/RELEASE_MANIFEST.json" result)" "failed" "stale attempted release finalized as failed"
# status no longer shows all-unknown
out="$(ds_report)"
printf '%s' "$out" | grep -q 'Git SHA:          <unknown>' && bad "status still shows SHA unknown" || ok "status shows a real SHA"
# 11. verified rollback to the ADOPTED previous release
( MOCK_HTTP_CODE=200 dep_rollback_code 20260724200918-829baf51f244 php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "PRODUCTION FIXTURE: verified rollback to the adopted release works"
assert_eq "$(zpd_current_release)" "20260724200918-829baf51f244" "adopted release active after rollback"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ═════════════════════════════════════════════════════════════════════════════
# Prompt 19 — transactional scheduler, fail-closed repair, read-only doctor
# ═════════════════════════════════════════════════════════════════════════════

echo "-- transactional scheduler sources --"

# P1. A stripped /etc/crontab entry is RESTORED when a LATER reconciliation step
#     fails, and the newly created canonical file is removed (it did not exist).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * root php %s/artisan schedule:run\n17 * * * * root run-parts /etc/cron.hourly\n' "$BASE" > "$ZPD_ETC_CRONTAB"
crontab_before="$(cat "$ZPD_ETC_CRONTAB")"
rm -f "${BASE}/current/update.sh"       # wrapper verification will fail AFTER the scheduler step
( dep_reconcile_operational "$BASE" 20260101000000-aaaaaaaaaaaa >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "reconciliation fails at the wrapper step"
assert_eq "$(cat "$ZPD_ETC_CRONTAB")" "$crontab_before" "stripped /etc/crontab entry RESTORED by the snapshot"
assert_false test -f "$ZPD_SCHED_CRON"   # newly created canonical removed with the failed transaction
assert_false bash -c "find '$BASE' -name '*.zpd-dedup.bak' | grep -q ."   # no unmanaged side files
rm -rf "$BASE"

# P2. Multiple matching lines in ONE file: processed once, both removed,
#     unrelated line preserved, exactly one snapshot entry for the file.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * root php %s/artisan schedule:run\n*/5 * * * * root php %s/current/artisan schedule:run\n17 * * * * root run-parts /etc/cron.hourly\n' "$BASE" "$BASE" > "$ZPD_ETC_CRONTAB"
assert_true dep_reconcile_operational "$BASE" 20260101000000-aaaaaaaaaaaa
assert_false bash -c "grep -q 'schedule:run' '$ZPD_ETC_CRONTAB'"
assert_true bash -c "grep -q 'run-parts' '$ZPD_ETC_CRONTAB'"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
snapmap="$(find "${BASE}/shared/deploy/snapshots" -name sources.map | head -1)"
assert_eq "$(grep -c "$ZPD_ETC_CRONTAB" "$snapmap")" "1" "one snapshot entry per source file (multi-line file processed once)"
rm -rf "$BASE"

# P3. A FOREIGN schedule:run that merely MENTIONS the base path (cd base &&
#     php /other/artisan) is NOT ours — left untouched, no conflict, no removal.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * root cd %s && php /srv/other-app/artisan schedule:run\n' "$BASE" > "$ZPD_ETC_CRONTAB"
assert_true dep_reconcile_scheduler "$BASE"
assert_true bash -c "grep -q '/srv/other-app/artisan schedule:run' '$ZPD_ETC_CRONTAB'"   # foreign entry preserved
rm -rf "$BASE"

echo "-- fail-closed repair --"

# P4. --apply refuses to run while the deployment lock is held.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
( exec 9>"$ZPD_LOCK_FILE"; flock 9; sleep 2 ) & holder=$!
sleep 0.3
( MOCK_HTTP_CODE=200 zrp_main --apply >/dev/null 2>&1 ); rc=$?
assert_rc 200 "$rc" "repair --apply is refused while a deployment holds the lock"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
rm -rf "$BASE"

# P5. Fail-closed: a supervisor reread failure aborts the repair, restores the
#     snapshot, records failure + restore result, and NEVER prints success.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"   # legacy
sup_before="$(cat "$ZPD_SUPERVISOR_CONF")"
out="$(MOCK_SUP_REREAD_RC=1 MOCK_HTTP_CODE=200 zrp_main --apply 2>&1)"; rc=$?
assert_rc 1 "$rc" "repair --apply fails closed on supervisor reread failure"
printf '%s' "$out" | grep -q "$(zpd_msg_repair_done)" && bad "success message shown on failed repair" || ok "no success message on failed repair"
assert_eq "$(cat "$ZPD_SUPERVISOR_CONF")" "$sup_before" "failed repair restored the supervisor snapshot"
lr="${BASE}/shared/deploy/last-repair.json"
assert_eq "$(zpd_manifest_get "$lr" result)" "failed" "last-repair records the failure"
assert_eq "$(zpd_manifest_get "$lr" failed_step)" "supervisor_reconcile" "last-repair records the failed step"
assert_true bash -c "[ -n \"$(zpd_manifest_get "$lr" restore_result)\" ]"
rm -rf "$BASE"

# P6. Workers not RUNNING is a REQUIRED failure (was a warning).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
out="$(MOCK_SUP_STATUS='zedproxy-worker:zedproxy-worker_00   FATAL   Exited too quickly' MOCK_HTTP_CODE=200 zrp_main --apply 2>&1)"; rc=$?
assert_rc 1 "$rc" "repair fails when the worker group is not RUNNING"
printf '%s' "$out" | grep -q "$(zpd_msg_repair_done)" && bad "success shown with broken workers" || ok "no success with broken workers"
rm -rf "$BASE"

# P7. HTTP health failure is a REQUIRED failure (was a warning).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
( MOCK_HTTP_CODE=500 zrp_main --apply >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "repair fails when /health is not 200 after repair"
rm -rf "$BASE"

# P8. --scheduler repairs ONLY the scheduler: a legacy Nginx root is untouched.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf 'server {\n  listen 80;\n  root %s/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"   # legacy nginx (out of scope)
nginx_before="$(cat "$ZPD_NGINX_CONF")"
( MOCK_HTTP_CODE=200 zrp_main --apply --scheduler >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "scoped --scheduler repair succeeds"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "--scheduler did NOT touch the Nginx config"
rm -rf "$BASE"

# P9. Manifests/state are backed up into the repair snapshot before repair
#     modifies them (recoverable metadata).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
mkdir -p "${BASE}/releases/20260102000000-bbbbbbbbbbbb"
zpd_write_manifest "${BASE}/releases/20260102000000-bbbbbbbbbbbb/RELEASE_MANIFEST.json" \
    "release_id=20260102000000-bbbbbbbbbbbb" "result=activating"
( MOCK_HTTP_CODE=200 zrp_main --apply --manifests >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "manifest repair succeeds"
assert_eq "$(zpd_manifest_get "${BASE}/releases/20260102000000-bbbbbbbbbbbb/RELEASE_MANIFEST.json" result)" "failed" "stale release finalized"
metasnap="$(find "${BASE}/shared/deploy/snapshots" -name 'manifest-20260102000000-bbbbbbbbbbbb.json' | head -1)"
assert_true test -n "$metasnap"
assert_true bash -c "grep -q 'activating' '$metasnap'"   # pre-repair content preserved in the snapshot
rm -rf "$BASE"

echo "-- adoption requires shared symlinks --"

# P10. A historical release with a REGULAR .env / local storage is NEVER
#      adopted, and the compatibility rollback path rejects it.
new_base
rd="${BASE}/releases/20260724200918-829baf51f244"
mkdir -p "$rd/public/build" "$rd/storage"; : > "$rd/artisan"; : > "$rd/public/index.php"
printf '{}' > "$rd/public/build/manifest.json"
printf 'APP_KEY=base64:PRIVATE-DIFFERENT-KEY\n' > "$rd/.env"       # REGULAR file, not a link
mkdir -p "$rd/public/storage"                                       # local dir, not a link
git -C "$rd" init -q -b main; git -C "$rd" add -A >/dev/null; git -C "$rd" commit -q -m x >/dev/null
zpd_switch_current 20260724200918-829baf51f244
dep_adopt_current_release >/dev/null 2>&1
assert_eq "$(dep_release_verify_mode 20260724200918-829baf51f244)" "historical" "release with a private .env is NOT adopted"
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( MOCK_HTTP_CODE=200 dep_verify_internal_release "$BASE" 20260724200918-829baf51f244 "" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "compat verification rejects non-shared .env/storage links"
rm -rf "$BASE"

echo "-- rollback-switch failure keeps the active directory --"

# P11. When activation fails and there is NO previous release to switch to, the
#      attempted release is still the active target — its directory must NOT be
#      renamed (a dangling `current` would be an instant outage).
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=500 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "first deploy with failing HTTP fails"
attempted="$(zpd_current_release)"
assert_true test -n "$attempted"
assert_true test -d "${BASE}/releases/${attempted}"            # directory kept in place
assert_false test -e "${BASE}/releases/${attempted}.failed"
assert_true test -e "$(zpd_current_link)"                      # current not dangling
assert_eq "$(zpd_manifest_get "${BASE}/releases/${attempted}/RELEASE_MANIFEST.json" result)" "failed" "kept release still finalized as failed"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

echo "-- read-only doctor + /dev/stdout safety + masking --"

# P12. --json serializes directly to stdout and never clobbers /dev/stdout.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
out="$(MOCK_HTTP_CODE=200 zdr_main --json 2>/dev/null)" || true
printf '%s' "$out" | grep -q '"checks_total"' && ok "doctor --json emits the summary" || bad "doctor --json output missing"
assert_true test -L /dev/stdout
out="$(ds_report --json)" || true
printf '%s' "$out" | grep -q '"active_release"' && ok "status --json emits the summary" || bad "status --json output missing"
assert_true test -L /dev/stdout
# P13. Empty state identity is reported as a repairable inconsistency.
zpd_write_manifest "$ZPD_STATE_FILE" "result=success"
out="$(MOCK_HTTP_CODE=200 zdr_main 2>&1)" || true
printf '%s' "$out" | grep -Eq 'state_file\s+fail\s+incomplete' && ok "doctor flags an empty state identity" || bad "doctor missed empty state identity"
# P14. A modern-schema manifest missing its SHA fails the doctor manifest check.
zpd_write_manifest "${BASE}/releases/20260101000000-aaaaaaaaaaaa/RELEASE_MANIFEST.json" \
    "release_id=20260101000000-aaaaaaaaaaaa" "result=success" "manifest_schema_version=2"
out="$(MOCK_HTTP_CODE=200 zdr_main 2>&1)" || true
printf '%s' "$out" | grep -Eq 'active_manifest\s+fail\s+modern manifest missing' && ok "doctor flags a modern manifest without a SHA" || bad "doctor missed missing modern SHA"
rm -rf "$BASE"

# P15. Observed authenticated origin is MASKED in deploy-status output.
new_base
hsha="$(mk_historical_release 20260724200918-829baf51f244)"; zpd_switch_current 20260724200918-829baf51f244
git -C "${BASE}/releases/20260724200918-829baf51f244" remote add origin "https://deploy:sekret12345@example.com/private.git"
out="$(ds_report)"
printf '%s' "$out" | grep -q 'sekret12345' && bad "status leaked an observed origin credential" || ok "status masks observed origin credentials"
printf '%s' "$out" | grep -q '\*\*\*' && ok "masked origin shown redacted" || bad "masked origin missing"
rm -rf "$BASE"

# P16. dep_check_pg is bounded — a hanging psql client times out fast.
t0=$SECONDS
new_base
( MOCK_PSQL_HANG=1 ZPD_DB_PROBE_TIMEOUT=1 dep_check_pg "${BASE}/shared/.env" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0        # timeout → rc 124 (any non-zero = probe failed)
assert_true test "$elapsed" -lt 10
rm -rf "$BASE"

# P17. Supervisor reconcile reloads the daemon even when the file is already
#      correct (a stale reread failure must be repairable).
new_base; setup_atomic_service_configs
( MOCK_SUP_REREAD_RC=1 dep_reconcile_supervisor "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "reread failure surfaces even with a correct supervisor file"
( dep_reconcile_supervisor "$BASE" >/dev/null 2>&1 ); assert_rc 0 "$?" "correct file + healthy daemon passes"
rm -rf "$BASE"

# ═════════════════════════════════════════════════════════════════════════════
# Prompt 20 — legacy rollback health, immutable failure context, extended
# scheduler discovery, fail-closed restore, exact repair flags, failure events
# ═════════════════════════════════════════════════════════════════════════════

echo "-- first-cutover legacy rollback --"

# Q1/Q3. Legacy rollback works with NO `current` and repoints the loopback
#        health vhost at the LEGACY webroot (never current/public).
new_base; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
mkdir -p "${BASE}/releases/20260101000000-aaaaaaaaaaaa"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
dep_bring_down "$BASE"     # legacy app fenced during the failed cutover
( MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "legacy rollback passes full health with current absent"
assert_false test -e "$(zpd_current_link)"
assert_true bash -c "grep -q 'root ${BASE}/public;' '$ZPD_LOCAL_HEALTH_CONF'"
assert_false bash -c "grep -q 'current/public' '$ZPD_LOCAL_HEALTH_CONF'"     # no dangling health root
assert_false zpd_is_in_maintenance "$BASE"
rm -rf "$BASE"

# Q2. First-cutover Supervisor commands are BOUNDED (a hang times out fast).
t0=$SECONDS
new_base; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
( MOCK_SUP_HANG=1 ZPD_SVC_TIMEOUT=1 MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0        # bounded + fail-closed
assert_true test "$elapsed" -lt 15
rm -rf "$BASE"

# Q2b. Fail-closed: FATAL workers fail the legacy rollback.
new_base; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
( MOCK_SUP_STATUS='zedproxy-worker:x FATAL Exited' MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "legacy rollback fails closed when workers are not RUNNING"
rm -rf "$BASE"

echo "-- immutable original failure context --"

# Q4a. dep_record_failure is immutable: the FIRST failure wins.
DEP_ORIG_FAILURE_STAGE=""; DEP_ORIG_FAILURE_REASON=""; DEP_ORIG_FAILURE_INVARIANT=""; DEP_ORIG_FAILURE_MESSAGE=""
dep_record_failure "internal_readiness" "internal_readiness_failed" "dep_verify_internal_release" "scheduler broken"
dep_record_failure "rollback_readiness" "later" "later" "later"
assert_eq "$DEP_ORIG_FAILURE_STAGE" "internal_readiness" "first recorded failure is immutable"
DEP_ORIG_FAILURE_STAGE=""; DEP_ORIG_FAILURE_REASON=""; DEP_ORIG_FAILURE_INVARIANT=""; DEP_ORIG_FAILURE_MESSAGE=""

# Q4b. The ORIGINAL failure stage survives a rollback that runs through every
#      rollback stage to SUCCESS (migrate failure + healthy previous release).
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_MIGRATE_MODE=fail MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "deploy with failing migrations fails"
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | head -1)"
fman="${BASE}/releases/${attempted}/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$fman" original_failure_stage)" "migrate" "original failure stage preserved through rollback"
assert_eq "$(zpd_manifest_get "$fman" original_failure_reason_code)" "migration_failed" "original reason preserved"
assert_eq "$(zpd_manifest_get "$fman" rollback_readiness)" "success" "rollback readiness recorded as success"
assert_eq "$(zpd_manifest_get "$fman" rollback_reconciliation)" "success" "rollback reconciliation recorded"
assert_eq "$(zpd_manifest_get "$fman" failure_stage)" "migrate" "failure_stage equals the ORIGINAL stage (not a rollback stage)"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# Q4c. Required-defaults seeding runs on every deploy; its failure stops
#      activation BEFORE the symlink switch and records the stage.
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
MOCK_LOG="${BASE}/php-invocations.log"
( export MOCK_LOG; MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "deploy succeeds with the required-defaults stage"
assert_true bash -c "grep -q 'zedproxy:seed-required-defaults' '$MOCK_LOG'"
unset MOCK_LOG
after_ok="$(zpd_current_release)"
sleep 1
( MOCK_SEED_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "required-defaults seeding failure fails the deploy"
assert_eq "$(zpd_current_release)" "$after_ok" "current NOT switched after a seeding failure"
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | tail -1)"
fman="${BASE}/releases/${attempted}/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$fman" original_failure_stage)" "required_defaults" "failure stage is required_defaults"
assert_eq "$(zpd_manifest_get "$fman" original_failure_reason_code)" "required_defaults_failed" "failure reason recorded"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

echo "-- extended scheduler discovery --"

# Q5. A systemd .timer executing our scheduler is detected → fail BEFORE modification.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[Timer]\nOnCalendar=*-*-* *:*:00\nExecStart=/usr/bin/php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/zp-sched.timer"
out="$(dep_reconcile_scheduler "$BASE" 2>&1)"; rc=$?
assert_rc 1 "$rc" "systemd .timer scheduler source aborts reconciliation"
printf '%s' "$out" | grep -q 'SYSTEMD' && ok "systemd timer reported" || bad "systemd timer not reported"
assert_false test -f "$ZPD_SCHED_CRON"       # failed BEFORE any modification
rm -rf "$BASE"

# Q6. A systemd .service executing our scheduler is detected.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[Service]\nExecStart=/usr/bin/php %s/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/zp-sched.service"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "systemd .service scheduler source aborts reconciliation"
assert_false test -f "$ZPD_SCHED_CRON"
rm -rf "$BASE"

# Q7. A Supervisor program executing our scheduler is detected.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[program:zp-sched]\ncommand=php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/supervisor.d/zp-sched.conf"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "Supervisor scheduler program aborts reconciliation"
rm -rf "$BASE"

# Q8. Ambiguous source fails BEFORE modification: the legacy crontab entry that
#     WOULD have been migrated is untouched after the abort.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * root php %s/artisan schedule:run\n' "$BASE" > "$ZPD_ETC_CRONTAB"
printf '[Service]\nExecStart=php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/amb.service"
crontab_before="$(cat "$ZPD_ETC_CRONTAB")"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "ambiguous source aborts"
assert_eq "$(cat "$ZPD_ETC_CRONTAB")" "$crontab_before" "no source was modified before the abort"
rm -rf "$BASE"

# Q9. After successful reconciliation EXACTLY ONE active scheduler source remains.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * www-data php %s/current/artisan schedule:run\n' "$BASE" > "${ZPD_CRON_D_DIR}/legacyfile"
assert_true dep_reconcile_scheduler "$BASE"
assert_true dep_scheduler_single_source_ok "$BASE"
assert_false test -f "${ZPD_CRON_D_DIR}/legacyfile"
rm -rf "$BASE"

echo "-- fail-closed snapshot restore --"

# Q10/Q11/Q12. Nginx reload, Supervisor reread/update, worker restart/RUNNING
#              failures each make dep_restore_operational_snapshot fail.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
snap="$(dep_snapshot_operational_config restore-test)"
( MOCK_NGINX_RC=1 dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 );    assert_rc 1 "$?" "nginx -t failure fails the restore"
( MOCK_SYSTEMCTL_RC=1 dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 1 "$?" "nginx reload failure fails the restore"
( MOCK_SUP_REREAD_RC=1 dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 1 "$?" "supervisor reread failure fails the restore"
( MOCK_SUP_UPDATE_RC=1 dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 1 "$?" "supervisor update failure fails the restore"
( MOCK_SUP_RESTART_RC=1 dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 1 "$?" "worker restart failure fails the restore"
( MOCK_SUP_STATUS='zedproxy-worker:x FATAL Exited' dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 1 "$?" "workers not RUNNING fails the restore"
( dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 0 "$?" "healthy restore passes"
rm -rf "$BASE"

echo "-- exact repair component flags --"

# Q13. Each individual flag repairs ONLY its named component.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
printf 'server {\n  listen 80;\n  root %s/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"                       # broken nginx
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"  # broken supervisor
rm -f "$ZPD_SCHED_CRON" "$ZPD_LOCAL_HEALTH_CONF"                                                          # broken sched + hv
printf '#!/usr/bin/env bash\n# OLD\n' > "${ZPD_WRAPPER_BIN}/zedproxy-update"; chmod +x "${ZPD_WRAPPER_BIN}/zedproxy-update"
sup_broken="$(cat "$ZPD_SUPERVISOR_CONF")"
( MOCK_HTTP_CODE=200 zrp_main --apply --nginx >/dev/null 2>&1 ); assert_rc 0 "$?" "--nginx repair succeeds"
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"
assert_eq "$(cat "$ZPD_SUPERVISOR_CONF")" "$sup_broken" "--nginx did not touch supervisor"
assert_false test -f "$ZPD_SCHED_CRON"                       # --nginx did not touch scheduler
( MOCK_HTTP_CODE=200 zrp_main --apply --supervisor >/dev/null 2>&1 ); assert_rc 0 "$?" "--supervisor repair succeeds"
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
assert_false test -f "$ZPD_SCHED_CRON"                       # still untouched
( MOCK_HTTP_CODE=200 zrp_main --apply --wrappers >/dev/null 2>&1 ); assert_rc 0 "$?" "--wrappers repair succeeds"
assert_true bash -c "grep -q 'zpd_resolve_script update.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_false test -f "$ZPD_SCHED_CRON"
( MOCK_HTTP_CODE=200 zrp_main --apply --health-vhost >/dev/null 2>&1 ); assert_rc 0 "$?" "--health-vhost repair succeeds"
assert_true zpd_local_health_conf_ok "$ZPD_LOCAL_HEALTH_CONF" "$BASE"
assert_false test -f "$ZPD_SCHED_CRON"
( MOCK_HTTP_CODE=200 zrp_main --apply --scheduler >/dev/null 2>&1 ); assert_rc 0 "$?" "--scheduler repair succeeds"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
rm -rf "$BASE"

echo "-- failure events + diagnostics for every stage --"

# Q14. BUILD failure → failed manifest + central failure event + bundle.
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_NPM_CI_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "build failure fails deploy"
lf="${BASE}/shared/deploy/last-failure.json"
assert_eq "$(zpd_manifest_get "$lf" stage)" "build" "central failure event records stage=build"
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | head -1)"
assert_eq "$(zpd_manifest_get "${BASE}/releases/${attempted}/RELEASE_MANIFEST.json" result)" "failed" "build-failed manifest finalized"
assert_true bash -c "ls ${BASE}/logs/diagnostics/zedproxy-diagnostic-*.tar.gz >/dev/null 2>&1"   # bundle produced
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# Q15. SMOKE failure → failed manifest + central event + bundle.
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HEALTH_MODE=fail MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "smoke failure fails deploy"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "smoke" "central failure event records stage=smoke"
assert_true bash -c "ls ${BASE}/logs/diagnostics/zedproxy-diagnostic-*.tar.gz >/dev/null 2>&1"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# Q16a. CLONE failure (resolvable full-SHA ref, unreachable repo) → central event.
new_base; setup_atomic_service_configs
export ZPD_REPO_URL="https://nonexistent.invalid.example/x.git" ZPD_REF="0123456789abcdef0123456789abcdef01234567"
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "clone failure fails deploy"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "clone" "central failure event records stage=clone"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE"

# Q16b. BACKUP failure → central event.
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_PGDUMP_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "backup failure fails deploy"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "backup" "central failure event records stage=backup"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ═════════════════════════════════════════════════════════════════════════════
# Prompt 21 — remaining production-safety gaps
# ═════════════════════════════════════════════════════════════════════════════

echo "-- fail-closed rollback reconciliation (HTTP passing is not enough) --"

# R1a. Worker-restart failure fails the rollback even though HTTP would pass.
new_base
mkdir -p "${BASE}/releases/20260101000000-aaaaaaaaaaaa"
setup_atomic_service_configs
MOCK_SUP_RESTART_RC=1 MOCK_HTTP_CODE=200 dep_rollback_code 20260101000000-aaaaaaaaaaaa php8.3-fpm >/dev/null 2>&1; rc=$?
assert_rc 1 "$rc" "rollback fails when worker restart fails (HTTP 200 irrelevant)"
assert_eq "$DEP_ROLLBACK_SWITCH" "success" "switch recorded separately (success)"
assert_eq "$DEP_ROLLBACK_RECONCILE" "failed" "reconciliation recorded separately (failed)"
assert_eq "$DEP_ROLLBACK_READY" "not_available" "readiness never reached after reconcile failure"

# R1b. nginx -t is validated BEFORE reload — a broken config fails the rollback.
( MOCK_NGINX_RC=1 MOCK_HTTP_CODE=200 dep_rollback_code 20260101000000-aaaaaaaaaaaa php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "rollback fails when nginx -t fails (validated before reload)"

# R1c. Workers not RUNNING fails the rollback.
( MOCK_SUP_STATUS='zedproxy-worker:x FATAL Exited' MOCK_HTTP_CODE=200 dep_rollback_code 20260101000000-aaaaaaaaaaaa php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "rollback fails when the worker group is not RUNNING"
rm -rf "$BASE"

echo "-- mandatory legacy rollback snapshot --"

# R2a. Snapshot creation failure is DETECTED (capture is verified).
new_base; make_legacy_base; setup_legacy_service_configs
mkdir -p "${BASE}/shared/deploy"; : > "${BASE}/shared/deploy/legacy-snapshots"  # dir blocked → capture fails
( zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON" 2>/dev/null ); \
  assert_rc 1 "$?" "legacy snapshot capture failure returns non-zero"
rm -rf "$BASE"

# R2b. A first cutover ABORTS before maintenance/migration/switch when the
#      snapshot cannot be created — the legacy install is untouched.
new_base; mk_source_repo; make_legacy_base; setup_legacy_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
nginx_before="$(cat "$ZPD_NGINX_CONF")"
mkdir -p "${BASE}/shared/deploy" && : > "${BASE}/shared/deploy/legacy-snapshots"
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "first cutover aborts when the legacy snapshot fails"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "legacy_snapshot" "failure recorded at stage=legacy_snapshot"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "legacy nginx untouched (aborted before cutover)"
assert_false test -L "${BASE}/current"
assert_false zpd_is_in_maintenance "$BASE"          # never entered maintenance
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

echo "-- exact fail-closed legacy restore --"

# R3a. A copy failure during the legacy restore fails the whole rollback.
new_base; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
rm -f "$ZPD_NGINX_CONF"; mkdir "$ZPD_NGINX_CONF"    # restore target blocked
( MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "legacy restore copy failure fails the rollback"
rm -rf "$BASE"

# R3b. Restored Nginx root NOT serving the legacy app → rollback fails (never
#      healthy just because the internal health vhost was repaired).
new_base; make_legacy_base
setup_atomic_service_configs                        # snapshot captures WRONG (current/) configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
( MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "restored nginx root not serving legacy → rollback fails"
rm -rf "$BASE"

# R3c. Restored Supervisor command NOT running the legacy artisan → fails.
new_base; make_legacy_base; setup_legacy_service_configs
zpd_supervisor_conf_content "$BASE" > "$ZPD_SUPERVISOR_CONF"   # current/artisan (wrong for legacy)
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
( MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "restored supervisor command not running legacy artisan → rollback fails"
rm -rf "$BASE"

echo "-- bounded blocking commands (hang → timeout, fail-closed) --"

# R4a. Hanging pg_dump times out fast AND the partial dump is removed.
t0=$SECONDS
new_base
( MOCK_PGDUMP_HANG=1 ZPD_BACKUP_TIMEOUT=1 dep_backup_database "${BASE}/backups/x/db.dump" "${BASE}/shared/.env" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0
assert_true test "$elapsed" -lt 15
assert_false test -e "${BASE}/backups/x/db.dump"    # incomplete dump removed
rm -rf "$BASE"

# R4b. Hanging composer install times out fast (build fails, never freezes).
t0=$SECONDS
new_base; rd="${BASE}/rel"; mkdir -p "$rd"; : > "${rd}/composer.lock"; : > "${rd}/package-lock.json"
( MOCK_COMPOSER_HANG=1 ZPD_COMPOSER_TIMEOUT=1 dep_build "$rd" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0
assert_true test "$elapsed" -lt 15

# R4c. Hanging npm ci times out fast.
t0=$SECONDS
( MOCK_NPM_HANG=1 ZPD_NPM_TIMEOUT=1 dep_build "$rd" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0
assert_true test "$elapsed" -lt 15
rm -rf "$BASE"

# R4d. A hanging asset build (npm run build) finalizes the attempted release as
#      FAILED — full dep_main, bounded end-to-end.
t0=$SECONDS
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_NPM_BUILD_HANG=1 ZPD_BUILD_TIMEOUT=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "hanging build fails the deploy (bounded)"
assert_true test "$elapsed" -lt 60
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | head -1)"
assert_eq "$(zpd_manifest_get "${BASE}/releases/${attempted}/RELEASE_MANIFEST.json" result)" "failed" "hung-build release finalized as failed"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "build" "central event records stage=build"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# R4e. A hanging migration times out → DB state recorded as UNKNOWN (never a
#      confident applied/failed) and the deploy rolls back safely.
new_base; rd="${BASE}/rel"; mkdir -p "$rd"
t0=$SECONDS
MOCK_MIGRATE_MODE=hang ZPD_MIGRATION_TIMEOUT=1 dep_run_migrations "$rd" >/dev/null 2>&1; rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "hanging migration times out and fails"
assert_eq "$DEP_MIGRATION_STATUS" "unknown" "migration timeout → status UNKNOWN"
assert_true test "$elapsed" -lt 15
rm -rf "$BASE"
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_MIGRATE_MODE=hang ZPD_MIGRATION_TIMEOUT=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "deploy with hung migration fails and rolls back"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "previous release restored after migration timeout"
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | head -1)"
assert_eq "$(zpd_manifest_get "${BASE}/releases/${attempted}/RELEASE_MANIFEST.json" migration_status)" "unknown" "manifest records migration_status=unknown"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# R4f. Hanging `artisan down` (required fencing) is bounded and fails.
t0=$SECONDS
new_base
( MOCK_DOWN_HANG=1 ZPD_MAINTENANCE_TIMEOUT=1 dep_bring_down "$BASE" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0
assert_true test "$elapsed" -lt 15

# R4g. Hanging `artisan up` is bounded and fails (required, never best-effort).
t0=$SECONDS
( MOCK_UP_HANG=1 ZPD_MAINTENANCE_TIMEOUT=1 dep_bring_up "$BASE" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "hanging artisan up times out and fails"
assert_true test "$elapsed" -lt 15
rm -rf "$BASE"

echo "-- transactional metadata/state repair --"

# R5a. State file ABSENT before a failed repair → the repair-created state file
#      is REMOVED again (no half-committed state).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
rm -f "$ZPD_STATE_FILE"
( MOCK_HTTP_CODE=500 zrp_main --apply >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "repair fails at the HTTP gate"
assert_false test -e "$ZPD_STATE_FILE"              # newly created state removed
rm -rf "$BASE"

# R5b. State file EXISTED → failed repair restores its exact bytes.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
mkdir -p "$(dirname "$ZPD_STATE_FILE")"
printf '{"sentinel":"pre-repair-state"}\n' > "$ZPD_STATE_FILE"
cp "$ZPD_STATE_FILE" "${BASE}/state.ref"
( MOCK_HTTP_CODE=500 zrp_main --apply >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "repair fails at the HTTP gate (state existed)"
assert_true cmp -s "$ZPD_STATE_FILE" "${BASE}/state.ref"
rm -rf "$BASE"

# R5c. A restore REMOVE failure is accounted (returns non-zero, never silent).
new_base
snap="$(mktemp -d)"; mkdir -p "${snap}/meta"; printf '0\n' > "${snap}/meta/state.existed"
mkdir -p "${ZPD_STATE_FILE}/blocker"                # state path is a dir → rm -f fails
( zrp_restore_metadata "$snap" >/dev/null 2>&1 ); assert_rc 1 "$?" "metadata restore remove-failure returns non-zero"
rm -rf "$BASE" "$snap"

echo "-- component-scoped operational restore --"

# R6a. A scheduler-scoped restore restores the cron file but NEVER rewrites
#      unrelated Nginx configuration.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs
snap="$(dep_snapshot_operational_config scope-test)"
printf 'BROKEN-BY-TEST\n' > "$ZPD_NGINX_CONF"
printf '* * * * * root echo corrupted\n' > "$ZPD_SCHED_CRON"
( dep_restore_operational_snapshot "$snap" scheduler >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "scheduler-scoped restore succeeds"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "BROKEN-BY-TEST" "scheduler-scoped restore did NOT touch nginx"

# R6b. Files CREATED after the snapshot are removed (verified absent) by a
#      full restore.
rm -f "$ZPD_SCHED_CRON"
snap2="$(dep_snapshot_operational_config absent-test)"
zpd_scheduler_cron_content "$BASE" > "$ZPD_SCHED_CRON"   # created AFTER snapshot
( dep_restore_operational_snapshot "$snap2" >/dev/null 2>&1 )
assert_false test -e "$ZPD_SCHED_CRON"
rm -rf "$BASE"

echo "-- complete scheduler-source discovery --"

# R7a. A unit in a VENDOR systemd tree (not /etc) is discovered and blocks.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
mkdir -p "${BASE}/vendor-systemd"
export ZPD_SYSTEMD_UNIT_DIRS="${BASE}/systemd:${BASE}/vendor-systemd"
printf '[Service]\nExecStart=/usr/bin/php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/vendor-systemd/zp-sched.service"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "vendor-tree systemd unit blocks reconciliation"
assert_false test -f "$ZPD_SCHED_CRON"

# R7b. A DROP-IN override (<unit>.d/*.conf) is discovered and blocks.
rm -f "${BASE}/vendor-systemd/zp-sched.service"
mkdir -p "${BASE}/systemd/cron-shim.service.d"
printf '[Service]\nExecStart=php %s/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/cron-shim.service.d/override.conf"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "systemd drop-in override blocks reconciliation"
rm -rf "${BASE}/systemd/cron-shim.service.d"

# R7c. A Supervisor program included via the MAIN supervisord [include] globs
#      (outside the conventional conf.d) is discovered and blocks.
mkdir -p "${BASE}/custom-super"
printf '[include]\nfiles = custom-super/*.conf\n' > "$ZPD_SUPERVISORD_CONF"
printf '[program:zp-sched]\ncommand=php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/custom-super/sched.conf"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "supervisord include-glob program blocks reconciliation"
rm -f "$ZPD_SUPERVISORD_CONF" "${BASE}/custom-super/sched.conf"

# R7d. INACTIVE leftover unit ≠ ACTIVE duplicate: a disabled+inactive unit is a
#      warning (reconciliation succeeds, single active source verified); the
#      same unit active blocks.
printf '[Service]\nExecStart=php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/leftover.service"
out="$(MOCK_TIMER_ENABLED=disabled MOCK_TIMER_ACTIVE=inactive dep_reconcile_scheduler "$BASE" 2>&1)"; rc=$?
assert_rc 0 "$rc" "inactive leftover unit does not block reconciliation"
printf '%s' "$out" | grep -qi 'leftover' && ok "inactive leftover reported as warning" || bad "inactive leftover not reported"
( MOCK_TIMER_ENABLED=disabled MOCK_TIMER_ACTIVE=inactive dep_scheduler_single_source_ok "$BASE" ); \
  assert_rc 0 "$?" "single-source check passes with only an inactive leftover"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 1 "$?" "the SAME unit while enabled/active blocks reconciliation"
rm -rf "$BASE"

echo "-- required success-metadata commit --"

# R8a. Code activation succeeds but the FINAL manifest write fails → NOT an
#      ordinary success: non-zero exit + metadata_commit failure event with
#      code_active=true (the live code is left in place).
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
(
  eval "$(declare -f zpd_write_manifest | sed '1s/zpd_write_manifest/zpd_orig_write_manifest/')"
  zpd_write_manifest() {
    case "$1" in *RELEASE_MANIFEST.json) case "$*" in *"result=success"*) return 1 ;; esac ;; esac
    zpd_orig_write_manifest "$@"
  }
  MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1
); rc=$?
assert_rc 1 "$rc" "success-manifest write failure is NOT reported as ordinary success"
lf="${BASE}/shared/deploy/last-failure.json"
assert_eq "$(zpd_manifest_get "$lf" stage)" "metadata_commit" "failure event records stage=metadata_commit"
assert_eq "$(zpd_manifest_get "$lf" code_active)" "true" "diagnostics preserve: code activation succeeded"
assert_true test -L "${BASE}/current"               # live code left in place
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# R8b. Manifest committed but central-state reconciliation fails → DEGRADED
#      success: exit 0, manifest result=success, and a recorded state_reconcile
#      event (never silent).
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
(
  dep_reconcile_state() { return 1; }
  MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1
); rc=$?
assert_rc 0 "$rc" "state-reconcile failure after committed manifest is a degraded SUCCESS"
man="${BASE}/releases/$(zpd_current_release)/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" result)" "success" "release manifest committed as success"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "state_reconcile" "degradation recorded (stage=state_reconcile)"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# R8c. dep_finalize_stale_releases PROPAGATES a manifest-write failure.
new_base
mk_release 20260101000000-aaaaaaaaaaaa activating
(
  eval "$(declare -f zpd_write_manifest | sed '1s/zpd_write_manifest/zpd_orig_write_manifest/')"
  zpd_write_manifest() { case "$*" in *stale_interrupted*) return 1 ;; esac; zpd_orig_write_manifest "$@"; }
  dep_finalize_stale_releases >/dev/null 2>&1
); assert_rc 1 "$?" "stale-release finalization failure propagates non-zero"
rm -rf "$BASE"

echo "-- Codex review fixes (relative cron form, snapshot modes, rollback cron sources, supervisor autostart) --"

# X1. The installer's relative form `cd <base> && php artisan schedule:run` is
#     classified OURS and reconciled away (no double scheduling)…
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '* * * * * www-data cd %s && php artisan schedule:run >> /var/log/x 2>&1\n' "$BASE" > "$ZPD_ETC_CRONTAB"
dep_scheduler_scan "$BASE" | grep -q "^OURS ${ZPD_ETC_CRONTAB}" \
  && ok "relative-artisan cron form classified OURS" || bad "relative-artisan cron form not classified OURS"
assert_true dep_reconcile_scheduler "$BASE"
assert_false bash -c "grep -q 'schedule:run' '$ZPD_ETC_CRONTAB'"
assert_true dep_scheduler_single_source_ok "$BASE"
# …while a foreign app's relative artisan after `cd <base>` stays FOREIGN.
printf '* * * * * www-data cd %s && php /other/app/artisan schedule:run\n' "$BASE" > "$ZPD_ETC_CRONTAB"
dep_scheduler_scan "$BASE" | grep -q "^FOREIGN ${ZPD_ETC_CRONTAB}" \
  && ok "foreign absolute artisan after cd stays FOREIGN" || bad "foreign artisan misclassified as ours"
rm -rf "$BASE"

# X2. Snapshot copies keep the ORIGINAL mode — a 0644 cron file is restored as
#     0644, never forced to the snapshot directory's 0600.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; chmod 644 "$ZPD_SCHED_CRON"
snap="$(dep_snapshot_operational_config mode-test)"
printf '* * * * * root echo corrupted\n' > "$ZPD_SCHED_CRON"; chmod 600 "$ZPD_SCHED_CRON"
( dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 0 "$?" "restore succeeds"
assert_eq "$(stat -c '%a' "$ZPD_SCHED_CRON")" "644" "restored cron keeps its original 0644 mode"
rm -rf "$BASE"

# X3. A failed first cutover puts a STRIPPED non-canonical cron entry back from
#     the per-activation snapshot — the legacy app never loses its scheduler.
new_base; make_legacy_base; setup_legacy_service_configs
printf '* * * * * root cd %s && php artisan schedule:run\n' "$BASE" > "$ZPD_ETC_CRONTAB"
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
DEP_SNAPSHOT_DIR="$(dep_snapshot_operational_config cutover-test)"
: > "$ZPD_ETC_CRONTAB"                          # reconciliation stripped the entry
( MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "legacy rollback succeeds and restores stripped cron sources"
assert_true bash -c "grep -q 'schedule:run' '$ZPD_ETC_CRONTAB'"
DEP_SNAPSHOT_DIR=""
rm -rf "$BASE"

# X4. A Supervisor scheduler stanza with autostart=false that is NOT running is
#     an inactive leftover (deploy proceeds); the same stanza running blocks.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[program:zp-sched]\ncommand=php %s/current/artisan schedule:run\nautostart=false\n' "$BASE" > "${BASE}/supervisor.d/zp-sched.conf"
( MOCK_SUP_STATUS='zp-sched   STOPPED   Not started' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "stopped autostart=false supervisor stanza does not block"
rm -f "$ZPD_SCHED_CRON"
( MOCK_SUP_STATUS='zp-sched   RUNNING   pid 7, uptime 0:01:00' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "the SAME stanza while RUNNING blocks"
rm -rf "$BASE"

echo "-- Codex round 2 (secret hygiene, legacy-snapshot validation, timer pairs, scoped restore) --"

# Y1. PGPASSWORD reaches pg_dump via the ENVIRONMENT (never as an argv word
#     under timeout/env, where /proc/*/cmdline would expose it).
new_base
( MOCK_PGDUMP_REQUIRE_ENVPASS=1 dep_backup_database "${BASE}/backups/x/db.dump" "${BASE}/shared/.env" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "pg_dump receives PGPASSWORD through the environment"
assert_false bash -c "grep -RqE 'env[[:space:]]+PGPASSWORD=' '$REPO_ROOT/scripts' '$REPO_ROOT/install.sh' '$REPO_ROOT/update.sh'"
rm -rf "$BASE"

# Y2. An OLD-LAYOUT global marker (pre-pointer format) is never an automatic
#     rollback path; a committed fresh capture is; a lost artifact invalidates.
new_base; make_legacy_base; setup_legacy_service_configs
mkdir -p "${BASE}/shared/deploy"
zpd_write_manifest "${BASE}/shared/deploy/legacy-rollback.json" "legacy_base=${BASE}"   # old layout: global marker
assert_false zpd_has_legacy_rollback
assert_false zpd_legacy_rollback_valid
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
assert_true zpd_has_legacy_rollback
assert_true zpd_legacy_rollback_valid
snapdir="$(zpd_legacy_snapshot_dir)"
rm -f "${snapdir}/nginx.legacy"                                                         # artifact lost
assert_false zpd_legacy_rollback_valid
rm -rf "$BASE"

# Y3. A stopped .service whose COMPANION .timer is enabled/active is a LIVE
#     duplicate (blocks); with the timer also inactive it is a leftover.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[Service]\nExecStart=/usr/bin/php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/zp-sched.service"
( MOCK_TIMER_LISTED=1 MOCK_SVC_ENABLED=static MOCK_SVC_ACTIVE=inactive MOCK_TIMER_ENABLED=enabled MOCK_TIMER_ACTIVE=active \
    dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "stopped service with an ACTIVE companion timer blocks reconciliation"
rm -f "$ZPD_SCHED_CRON"
( MOCK_TIMER_LISTED=1 MOCK_SVC_ENABLED=static MOCK_SVC_ACTIVE=inactive MOCK_TIMER_ENABLED=disabled MOCK_TIMER_ACTIVE=inactive \
    dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "stopped service with an inactive companion timer is a leftover"
rm -rf "$BASE"

# Y4. Component-scoped restores stay in their lane: a wrappers-only restore
#     touches neither Nginx config nor Supervisor; an nginx-only restore never
#     restarts workers.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
snap="$(dep_snapshot_operational_config lane-test)"
printf 'BROKEN-NGINX\n' > "$ZPD_NGINX_CONF"
( MOCK_SUP_RESTART_RC=1 dep_restore_operational_snapshot "$snap" wrappers >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "wrappers-only restore succeeds without touching services"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "BROKEN-NGINX" "wrappers-only restore did not rewrite nginx"
( MOCK_SUP_RESTART_RC=1 dep_restore_operational_snapshot "$snap" nginx >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "nginx-only restore never restarts workers"
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"
rm -rf "$BASE"

echo "-- Codex round 3 (per-stanza supervisor, truncated map, backup dump removal) --"

# Z1. Multi-stanza Supervisor file: an unrelated first program with
#     autostart=false must NOT mask an autostarted ZedProxy scheduler stanza.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[program:unrelated]\ncommand=/bin/sleep 1\nautostart=false\n\n[program:zp-sched]\ncommand=php %s/current/artisan schedule:run\n' "$BASE" \
    > "${BASE}/supervisor.d/multi.conf"
( MOCK_SUP_STATUS='unrelated   STOPPED   Not started' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "autostarted scheduler stanza blocks despite unrelated autostart=false stanza"
# The scheduler stanza itself autostart=false + stopped → inactive leftover.
printf '[program:zp-sched]\ncommand=php %s/current/artisan schedule:run\nautostart=false\n' "$BASE" \
    > "${BASE}/supervisor.d/multi.conf"
( MOCK_SUP_STATUS='zp-sched   STOPPED   Not started' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "per-stanza: the scheduler stanza's OWN autostart=false decides"
rm -rf "$BASE"

# Z2. A TRUNCATED legacy-files.map (missing a managed-source record) is not a
#     valid rollback path.
new_base; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
assert_true zpd_legacy_rollback_valid
snapdir="$(zpd_legacy_snapshot_dir)"
grep -v '^scheduler	' "${snapdir}/legacy-files.map" > "${BASE}/map.tmp" \
  && mv "${BASE}/map.tmp" "${snapdir}/legacy-files.map"
assert_false zpd_legacy_rollback_valid
rm -rf "$BASE"

# Z3. The cron backup script removes an incomplete dump on failure/timeout
#     (static invariant — the script is exercised against the real repo .env
#     only in production).
assert_true bash -c "grep -q 'rm -f \"\$TMPFILE\"' '$REPO_ROOT/scripts/backup.sh'"
assert_true bash -c "grep -qE 'if ! PGPASSWORD' '$REPO_ROOT/scripts/backup.sh'"

# ═════════════════════════════════════════════════════════════════════════════
# Prompt 22 — behavioral test group
# ═════════════════════════════════════════════════════════════════════════════

echo "-- P22: TERM-then-KILL bounds (SIGTERM-ignoring child) --"

# W1a. A child that IGNORES SIGTERM is still killed after the grace period.
t0=$SECONDS
( MOCK_SUP_IGNORE_TERM=1 ZPD_SVC_TIMEOUT=1 ZPD_KILL_GRACE=1 dep_svc "$ZPD_SUPERVISORCTL" status >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 137 "$rc" "SIGTERM-ignoring child is SIGKILLed after the grace period"
assert_true test "$elapsed" -lt 10

# W1b. Full script run (with the real flock wrapper): a SIGTERM-ignoring hang
#      cannot wedge the deployment, and the DEPLOYMENT LOCK is released.
t0=$SECONDS
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
( ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main MOCK_SUP_IGNORE_TERM=1 ZPD_SVC_TIMEOUT=1 ZPD_KILL_GRACE=1 \
  ZPD_WORKER_STOP_TIMEOUT=4 MOCK_HTTP_CODE=200 bash "$DEPLOY" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_true test "$rc" -ne 0
assert_true test "$elapsed" -lt 120
( exec 9>>"$ZPD_LOCK_FILE"; flock -n 9 ); assert_rc 0 "$?" "deployment lock released after the bounded failure"
rm -rf "$BASE" "$(dirname "$SRC_BARE")"

echo "-- P22: required worker fencing (migrate never runs after a failed fence) --"

# W2. Workers that never verify STOPPED abort BEFORE migrations: migrate is not
#     executed, current does not switch, operational config is untouched,
#     maintenance is cleared, and failure_stage=worker_stop.
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
nginx_before="$(cat "$ZPD_NGINX_CONF")"
export MOCK_LOG="${BASE}/php-invocations.log"; : > "$MOCK_LOG"
( export MOCK_LOG; ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main ZPD_WORKER_STOP_TIMEOUT=4 \
  MOCK_SUP_STATUS='zedproxy-worker:zedproxy-worker_00   RUNNING   pid 100, uptime 0:00:05' \
  MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "deploy fails when the worker fence cannot verify STOPPED"
assert_false bash -c "grep -q 'artisan migrate' '$MOCK_LOG'"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "current never switched"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "operational config untouched"
assert_false zpd_is_in_maintenance "$(zpd_current_link)"
assert_eq "$(zpd_manifest_get "${BASE}/shared/deploy/last-failure.json" stage)" "worker_stop" "failure recorded at stage=worker_stop"
attempted="$(ls "$BASE/releases" | grep -E '\.failed$' | head -1)"
assert_eq "$(zpd_manifest_get "${BASE}/releases/${attempted}/RELEASE_MANIFEST.json" original_failure_stage)" "worker_stop" "manifest original_failure_stage=worker_stop"
unset MOCK_LOG; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# W2b. A fence rejecting FATAL/BACKOFF/malformed states (unit-level).
new_base; setup_atomic_service_configs
( MOCK_SUP_STATUS='zedproxy-worker:x   FATAL   Exited too quickly' ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "FATAL worker state rejects the fence"
( MOCK_SUP_STATUS='zedproxy-worker:x   BACKOFF   restarting' ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "BACKOFF worker state rejects the fence"
( MOCK_SUP_STATUS='zedproxy-worker:x' ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "malformed status line rejects the fence"
( MOCK_SUP_STOP_RC=1 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "failed stop command rejects the fence"
( dep_stop_workers >/dev/null 2>&1 ); assert_rc 0 "$?" "verified STOPPED workers pass the fence"
rm -rf "$BASE"

echo "-- P22: symmetric inventory snapshot/restore --"

# W3a. Wrapper capture failure FAILS the operational snapshot (no fail-open).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
rm -f "${ZPD_WRAPPER_LIB}/bootstrap.sh"; mkdir -p "${ZPD_WRAPPER_LIB}/bootstrap.sh/x"   # unreadable-as-file
( dep_snapshot_operational_config wrapfail-test >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "wrapper capture failure fails the snapshot"
rm -rf "${ZPD_WRAPPER_LIB}/bootstrap.sh"; zpw_install_wrappers >/dev/null 2>&1

# W3b. The inventory records path/type/sha256/mode/uid/gid for every resource,
#      and deploy.env IS restored (bytes + metadata verified).
printf 'ZPD_KEEP_RELEASES=5\n' > "$ZPD_DEPLOY_ENV"; chmod 640 "$ZPD_DEPLOY_ENV"
snap="$(dep_snapshot_operational_config inv-test)"
assert_true test -f "${snap}/inventory.tsv"
assert_true bash -c "awk -F'\t' '\$1==\"env\" && \$3==\"file\" && \$5 ~ /^[0-9a-f]{64}\$/ && \$6==\"640\" {ok=1} END {exit ok?0:1}' '${snap}/inventory.tsv'"
printf 'ZPD_KEEP_RELEASES=99\nTAMPERED=1\n' > "$ZPD_DEPLOY_ENV"; chmod 600 "$ZPD_DEPLOY_ENV"
( dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 0 "$?" "full restore succeeds"
assert_eq "$(cat "$ZPD_DEPLOY_ENV")" "ZPD_KEEP_RELEASES=5" "deploy.env bytes restored"
assert_eq "$(stat -c '%a' "$ZPD_DEPLOY_ENV")" "640" "deploy.env mode restored"

# W3c. A symlink resource is restored as a symlink with its recorded target.
rm -f "$ZPD_DEPLOY_ENV"; printf 'REAL\n' > "${BASE}/real.env"; ln -s "${BASE}/real.env" "$ZPD_DEPLOY_ENV"
snap2="$(dep_snapshot_operational_config sym-test)"
rm -f "$ZPD_DEPLOY_ENV"; printf 'REPLACED\n' > "$ZPD_DEPLOY_ENV"                        # symlink → regular file
( dep_restore_operational_snapshot "$snap2" >/dev/null 2>&1 ); assert_rc 0 "$?" "restore with symlink record succeeds"
assert_true test -L "$ZPD_DEPLOY_ENV"
assert_eq "$(readlink "$ZPD_DEPLOY_ENV")" "${BASE}/real.env" "symlink target restored"
rm -rf "$BASE"

echo "-- P22: unknown scheduler state is fail-closed --"

# W4a. Unrecognized systemd states (unknown) BLOCK reconciliation.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[Service]\nExecStart=php %s/current/artisan schedule:run\n' "$BASE" > "${BASE}/systemd/mystery.service"
( MOCK_SVC_ENABLED=n/a MOCK_SVC_ACTIVE=n/a dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "unknown systemd state blocks reconciliation (never assumed inactive)"

# W4b. A systemctl TIMEOUT is unknown → blocks (bounded, no hang).
t0=$SECONDS
( MOCK_SYSTEMCTL_HANG=1 ZPD_SVC_TIMEOUT=1 ZPD_KILL_GRACE=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "systemctl timeout blocks reconciliation"
assert_true test "$elapsed" -lt 60
rm -f "${BASE}/systemd/mystery.service"

# W4c. Supervisor: an autostart=false stanza with an unparseable/timed-out/
#      BACKOFF status is UNKNOWN → blocks; only verified STOPPED/EXITED passes.
printf '[program:zp-sched]\ncommand=php %s/current/artisan schedule:run\nautostart=false\n' "$BASE" \
    > "${BASE}/supervisor.d/zp-sched.conf"
( MOCK_SUP_STATUS='zp-sched   BACKOFF   restarting' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "BACKOFF supervisor status is unknown → blocks"
rm -f "$ZPD_SCHED_CRON"
( MOCK_SUP_STATUS='garbage' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "unparseable supervisor status is unknown → blocks"
rm -f "$ZPD_SCHED_CRON"
t0=$SECONDS
( MOCK_SUP_HANG=1 ZPD_SVC_TIMEOUT=1 ZPD_KILL_GRACE=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "supervisorctl status timeout is unknown → blocks"
assert_true test "$elapsed" -lt 30
rm -f "$ZPD_SCHED_CRON"
( MOCK_SUP_STATUS='zp-sched   STOPPED   Not started' dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "positively STOPPED stanza is a leftover (proceeds)"
rm -rf "$BASE"

echo "-- P22: fresh immutable first-cutover snapshots --"

# W5a. Every attempt gets its OWN committed snapshot; the pointer moves.
new_base; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON" attempt-1
d1="$(zpd_legacy_snapshot_dir)"
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON" attempt-2
d2="$(zpd_legacy_snapshot_dir)"
assert_true test -d "$d1"
assert_true test -d "$d2"
assert_false bash -c "[ '$d1' = '$d2' ]"
assert_eq "$(zpd_manifest_get "${d2}/marker.json" snapshot_complete)" "true" "committed snapshot records snapshot_complete=true"
assert_eq "$(zpd_manifest_get "${d2}/marker.json" snapshot_schema_version)" "2" "snapshot records its schema version"
rm -rf "$BASE"

# W5b. A COMPLETE BUT STALE earlier snapshot is not reused: the failed cutover
#      restores the CURRENT pre-cutover configuration, not the stale capture.
new_base; mk_source_repo; make_legacy_base; setup_legacy_service_configs
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON" stale-attempt
stale_dir="$(zpd_legacy_snapshot_dir)"
printf 'server {\n  listen 80;\n  root %s/public;\n  # CURRENT-PRECUTOVER-MARK\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_SUP_REREAD_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "cutover fails (supervisor reread)"
assert_true bash -c "grep -q 'CURRENT-PRECUTOVER-MARK' '$ZPD_NGINX_CONF'"
assert_false bash -c "[ '$(zpd_legacy_snapshot_dir)' = '$stale_dir' ]"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# W5c. An INTERRUPTED (uncommitted) snapshot is ignored/refused.
new_base; make_legacy_base; setup_legacy_service_configs
mkdir -p "${BASE}/shared/deploy/legacy-snapshots/broken"
zpd_write_manifest "${BASE}/shared/deploy/legacy-snapshots/broken/marker.json" \
  "snapshot_schema_version=2" "snapshot_complete=false"
printf '%s\n' "${BASE}/shared/deploy/legacy-snapshots/broken" > "${BASE}/shared/deploy/legacy-snapshots/current.ptr"
assert_false zpd_legacy_rollback_valid
( zpd_restore_legacy_rollback >/dev/null 2>&1 ); assert_rc 1 "$?" "restore refuses an uncommitted snapshot"

# W5d. An OLD SCHEMA version is rejected.
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON" schema-test
assert_true zpd_legacy_rollback_valid
snapdir="$(zpd_legacy_snapshot_dir)"
zpd_write_manifest "${snapdir}/marker.json" "snapshot_schema_version=1" "snapshot_complete=true"
assert_false zpd_legacy_rollback_valid
( zpd_restore_legacy_rollback >/dev/null 2>&1 ); assert_rc 1 "$?" "restore refuses an old-schema snapshot"
rm -rf "$BASE"

# ═════════════════════════════════════════════════════════════════════════════
# Prompt 23 — remaining fail-closed defects
# ═════════════════════════════════════════════════════════════════════════════

echo "-- P23: scheduler DISCOVERY itself is fail-closed --"

# V1. Discovery-command failures block BEFORE the canonical cron is written:
#     list-unit-files rc=1, list-timers rc=1, systemctl cat rc=1,
#     list-unit-files timeout, malformed output.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
( MOCK_LUF_RC=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "list-unit-files failure blocks reconciliation"
assert_false test -f "$ZPD_SCHED_CRON"
( MOCK_LT_RC=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "list-timers failure blocks reconciliation"
assert_false test -f "$ZPD_SCHED_CRON"
rm -f "$ZPD_SCHED_CRON"
( MOCK_TIMER_LISTED=1 MOCK_CAT_RC=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "systemctl cat failure blocks reconciliation"
assert_false test -f "$ZPD_SCHED_CRON"
rm -f "$ZPD_SCHED_CRON"
t0=$SECONDS
( MOCK_LUF_HANG=1 ZPD_SVC_TIMEOUT=1 ZPD_KILL_GRACE=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); rc=$?
elapsed=$((SECONDS - t0))
assert_rc 1 "$rc" "list-unit-files timeout blocks reconciliation"
assert_true test "$elapsed" -lt 30
assert_false test -f "$ZPD_SCHED_CRON"
rm -f "$ZPD_SCHED_CRON"
( MOCK_LUF_MALFORMED=1 dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "malformed list-unit-files output blocks reconciliation"
assert_false test -f "$ZPD_SCHED_CRON"
# A timer that systemd reports with an unexpected error is UNKNOWN, not absent.
( MOCK_TIMER_LISTED=1 MOCK_LUF_RC=4 _dep_systemd_timer_exists zp.timer >/dev/null 2>&1 ); \
  assert_rc 2 "$?" "unexpected list-unit-files rc makes timer existence UNKNOWN"
# Healthy discovery still reconciles.
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); assert_rc 0 "$?" "healthy discovery reconciles"
rm -rf "$BASE"

echo "-- P23: every unverified Supervisor worker status is rejected --"

# V2. Unverified status responses never pass the fence.
new_base; setup_atomic_service_configs
( MOCK_SUP_STATUS='other-prog   RUNNING   pid 5' MOCK_SUP_STATUS_RC=1 ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "rc=1 with unrelated output rejects the fence"
( MOCK_SUP_STATUS='error: could not contact supervisord' MOCK_SUP_STATUS_RC=3 ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "rc=3 with an error message rejects the fence"
( MOCK_SUP_STATUS='other-prog   RUNNING   pid 5' ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "rc=0 with UNRELATED programs only never proves absence"
( MOCK_SUP_STATUS='' ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "empty status output rejects the fence"
( MOCK_SUP_STATUS=$'zedproxy-worker:a   STOPPED   Jul 25\nzedproxy-worker:b   RUNNING   pid 9' ZPD_WORKER_STOP_TIMEOUT=4 dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "mixed STOPPED and RUNNING lines reject the fence"
( MOCK_SUP_STATUS='zedproxy-worker: ERROR (no such group)' dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "explicit no-such-group response is POSITIVE absence"
rm -f "$ZPD_SUPERVISOR_CONF"
( MOCK_SUP_STATUS='ignored' dep_stop_workers >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "no managed worker config (verified fact) — nothing to fence"
rm -rf "$BASE"

echo "-- P23: operational snapshot commit is atomic and required --"

# V3a. A failed SNAPSHOT.json write can never yield success, and the leftover
#      temporary directory is refused by restore.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
(
  eval "$(declare -f zpd_write_manifest | sed '1s/zpd_write_manifest/zpd_orig_write_manifest/')"
  zpd_write_manifest() { case "$1" in *SNAPSHOT.json) return 1 ;; esac; zpd_orig_write_manifest "$@"; }
  dep_snapshot_operational_config manifest-fail >/dev/null 2>&1
); assert_rc 1 "$?" "failed SNAPSHOT.json write fails the snapshot"
assert_false test -d "${BASE}/shared/deploy/snapshots/manifest-fail"          # never committed
( dep_restore_operational_snapshot "${BASE}/shared/deploy/snapshots/manifest-fail.tmp" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "restore refuses the interrupted .tmp snapshot"

# V3b. A committed snapshot carries schema + complete=true and restores.
snap="$(dep_snapshot_operational_config commit-test)"
case "$snap" in *.tmp) bad "committed snapshot path still .tmp" ;; *) ok "committed snapshot path is final" ;; esac
assert_eq "$(zpd_manifest_get "${snap}/SNAPSHOT.json" snapshot_complete)" "true" "snapshot committed complete=true"
assert_eq "$(zpd_manifest_get "${snap}/SNAPSHOT.json" snapshot_schema_version)" "2" "snapshot records schema version"
( dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); assert_rc 0 "$?" "committed snapshot restores"

# V3c. Old schema, missing inventory, and hash mismatch are all refused/failed.
zpd_write_manifest "${snap}/SNAPSHOT.json" "snapshot_schema_version=1" "snapshot_complete=true"
( dep_restore_operational_snapshot "$snap" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "restore refuses an old snapshot schema"
snap2="$(dep_snapshot_operational_config inv-miss)"
rm -f "${snap2}/inventory.tsv"
( dep_restore_operational_snapshot "$snap2" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "restore refuses a snapshot without its inventory"
snap3="$(dep_snapshot_operational_config hash-test)"
printf 'TAMPERED\n' > "${snap3}/nginx.snap"
( dep_restore_operational_snapshot "$snap3" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "restore fails on a snapshot hash mismatch"
rm -rf "$BASE"

echo "-- P23: metadata probes never enable root Composer plugins --"

# V4. dep_probe_version must NOT export COMPOSER_ALLOW_SUPERUSER (the mock
#     fails if it leaks) — and still stays bounded + non-interactive.
assert_eq "$(MOCK_COMPOSER_FORBID_SUPERUSER=1 dep_probe_version "$ZPD_COMPOSER" --version --no-ansi)" "2.8.1" \
  "metadata probe works WITHOUT COMPOSER_ALLOW_SUPERUSER"
if declare -f dep_probe_version | grep -q COMPOSER_ALLOW_SUPERUSER; then
  bad "dep_probe_version still exports COMPOSER_ALLOW_SUPERUSER"
else
  ok "dep_probe_version does not export COMPOSER_ALLOW_SUPERUSER"
fi
if declare -f dep_probe_version | grep -q COMPOSER_NO_INTERACTION; then
  ok "dep_probe_version stays non-interactive"
else
  bad "dep_probe_version lost COMPOSER_NO_INTERACTION"
fi
# Root safety failure degrades to "unknown" — metadata never blocks.
probe_fail_dir="$(mktemp -d)"
printf '#!/usr/bin/env bash\nexit 1\n' > "${probe_fail_dir}/composer"; chmod +x "${probe_fail_dir}/composer"
assert_eq "$(dep_probe_version "${probe_fail_dir}/composer" --version)" "unknown" "probe failure yields unknown (never blocks)"
rm -rf "$probe_fail_dir"

echo "-- Codex round 4 (unknown-migration guidance, split scheduler forms, manual rollback, scoped capture, state canonicalization) --"

# U1. A timed-out migration stays INDETERMINATE in the rollback guidance —
#     never "no migrations ran"; the operator is pointed at the backup.
new_base; mk_source_repo
psha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
out="$(MOCK_MIGRATE_MODE=hang ZPD_MIGRATION_TIMEOUT=1 MOCK_HTTP_CODE=200 dep_main 2>&1)"; rc=$?
assert_rc 1 "$rc" "deploy with hung migration fails"
printf '%s' "$out" | grep -qi 'may be partially migrated' \
  && ok "unknown migration reported as INDETERMINATE" || bad "unknown migration guidance missing"
printf '%s' "$out" | grep -q 'DB backup:' \
  && ok "operator directed to the DB backup" || bad "backup pointer missing for unknown migration"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# U2. Split scheduler forms are discovered: WorkingDirectory=<base> + relative
#     ExecStart (systemd), and directory=<base> + relative command (Supervisor).
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; rm -f "$ZPD_SCHED_CRON"
printf '[Service]\nWorkingDirectory=%s\nExecStart=/usr/bin/php artisan schedule:run\n' "$BASE" > "${BASE}/systemd/split.service"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "WorkingDirectory + relative artisan systemd unit blocks"
rm -f "${BASE}/systemd/split.service" "$ZPD_SCHED_CRON"
printf '[program:zp-split]\ndirectory=%s\ncommand=php artisan schedule:run\n' "$BASE" > "${BASE}/supervisor.d/split.conf"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "directory + relative command Supervisor stanza blocks"
rm -f "${BASE}/supervisor.d/split.conf" "$ZPD_SCHED_CRON"
# A foreign working directory with a relative artisan stays undetected (not ours).
printf '[Service]\nWorkingDirectory=/other/app\nExecStart=/usr/bin/php artisan schedule:run\n' > "${BASE}/systemd/foreign.service"
( dep_reconcile_scheduler "$BASE" >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "foreign WorkingDirectory unit does not block"
rm -rf "$BASE"

# U3. MANUAL legacy rollback (fresh process, DEP_SNAPSHOT_DIR unset) restores
#     stripped cron sources via the committed last-op-snapshot pointer.
new_base; make_legacy_base; setup_legacy_service_configs
printf '* * * * * root cd %s && php artisan schedule:run\n' "$BASE" > "$ZPD_ETC_CRONTAB"
zpd_save_legacy_rollback "$BASE" "$ZPD_NGINX_CONF" "$ZPD_SUPERVISOR_CONF" "$ZPD_SCHED_CRON"
dep_snapshot_operational_config manual-rb-test >/dev/null      # commits + writes the pointer
: > "$ZPD_ETC_CRONTAB"                                          # reconciliation stripped it
( DEP_SNAPSHOT_DIR= MOCK_HTTP_CODE=200 dep_first_cutover_rollback "$BASE" php8.3-fpm >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "manual legacy rollback succeeds without DEP_SNAPSHOT_DIR"
assert_true bash -c "grep -q 'schedule:run' '$ZPD_ETC_CRONTAB'"
rm -rf "$BASE"

# U4. Deployment output is PERSISTED under the log dir — the doctor bundle's
#     log collection has a real source after a failed update.
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_NPM_CI_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 )
assert_true bash -c "ls ${BASE}/logs/deploy-*.log >/dev/null 2>&1"
assert_true bash -c "grep -q 'ERROR' ${BASE}/logs/deploy-*.log"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# U5. State reconciliation refuses a `current` that resolves OUTSIDE releases/
#     even when its basename matches a real release id.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"
mkdir -p "${BASE}/evil/20260101000000-aaaaaaaaaaaa"
ln -sfn "${BASE}/evil/20260101000000-aaaaaaaaaaaa" "${BASE}/current"
( dep_reconcile_state >/dev/null 2>&1 ); \
  assert_rc 1 "$?" "state repair refuses a non-canonical current target"
assert_false test -f "$ZPD_STATE_FILE"
rm -rf "$BASE"

# U6. Repair backups are SCOPED: an unrelated broken wrapper library no longer
#     aborts a metadata-only or nginx-only repair.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
rm -f "${ZPD_WRAPPER_LIB}/bootstrap.sh"; mkdir -p "${ZPD_WRAPPER_LIB}/bootstrap.sh/x"   # wrapper capture would fail
( MOCK_HTTP_CODE=200 zrp_main --apply --state >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "metadata-only repair succeeds despite broken wrappers (scoped capture)"
( MOCK_HTTP_CODE=200 zrp_main --apply --nginx >/dev/null 2>&1 ); \
  assert_rc 0 "$?" "nginx-only repair succeeds despite broken wrappers (scoped capture)"
rm -rf "${ZPD_WRAPPER_LIB}/bootstrap.sh"
rm -rf "$BASE"

# U7. The cron backup publishes atomically to a collision-free name (static).
assert_true bash -c "grep -q 'TMPFILE=' '$REPO_ROOT/scripts/backup.sh'"
assert_true bash -c "grep -q 'mv \"\$TMPFILE\" \"\$FINAL\"' '$REPO_ROOT/scripts/backup.sh'"

# U8. A SYMLINKED state file keeps its type through a failed repair restore.
new_base; sha="$(mk_release_git 20260101000000-aaaaaaaaaaaa)"; zpd_switch_current 20260101000000-aaaaaaaaaaaa
setup_atomic_service_configs; zpw_install_wrappers >/dev/null 2>&1
mkdir -p "$(dirname "$ZPD_STATE_FILE")"
printf '{"sentinel":"symlinked-state"}\n' > "${BASE}/state-real.json"
ln -s "${BASE}/state-real.json" "$ZPD_STATE_FILE"
( MOCK_HTTP_CODE=500 zrp_main --apply >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "repair fails at the HTTP gate (symlinked state)"
assert_true test -L "$ZPD_STATE_FILE"
assert_eq "$(readlink "$ZPD_STATE_FILE")" "${BASE}/state-real.json" "state symlink target preserved"
assert_true bash -c "grep -q 'symlinked-state' '${BASE}/state-real.json'"
rm -rf "$BASE"

rm -rf "$MOCKBIN"
echo ""
echo "== results: ${PASS} passed, ${FAIL} failed =="
[ "$FAIL" -eq 0 ]
