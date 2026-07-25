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
# realistic banner so dep_probe_version can extract a token.
case "$*" in
  *--version*) echo "Composer version 2.8.1 2024-10-01 12:00:00"; exit 0 ;;
esac
exit ${MOCK_COMPOSER_RC:-0}
EOF
cat > "${MOCKBIN}/npm" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  ci)        exit ${MOCK_NPM_CI_RC:-0} ;;
  run)       [ "${2:-}" = "build" ] && exit ${MOCK_NPM_BUILD_RC:-0}; exit 0 ;;
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
if [ "${1:-}" = "-r" ]; then echo "${MOCK_PHP_VERSION:-8.3.7}"; exit 0; fi
if [ "${1:-}" = "-v" ]; then echo "PHP ${MOCK_PHP_VERSION:-8.3.7} (cli)"; exit 0; fi
case "$*" in
  *"artisan down"*)
      mkdir -p storage/framework 2>/dev/null || true
      printf '<?php return array();\n' > storage/framework/maintenance.php 2>/dev/null || true
      exit 0 ;;
  *"artisan up"*)
      rc="${MOCK_UP_RC:-0}"
      if [ "$rc" = "0" ] && [ "${MOCK_UP_STUCK:-0}" != "1" ]; then
          rm -f storage/framework/maintenance.php storage/framework/down 2>/dev/null || true
      fi
      exit "$rc" ;;
  *"artisan migrate"*)
      case "${MOCK_MIGRATE_MODE:-none}" in
        applied) echo "Migrating: 2026_01_01_000000_add_widget"; echo "Migrated:  2026_01_01_000000_add_widget (12.34ms)"; exit 0 ;;
        fail)    echo "   SQLSTATE[42P01]: undefined_table"; exit 1 ;;
        *)       echo "Nothing to migrate."; exit 0 ;;
      esac ;;
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
out=""; while [ $# -gt 0 ]; do [ "$1" = "-f" ] && { out="$2"; shift; }; shift; done
[ -n "$out" ] && : > "$out"
exit ${MOCK_PGDUMP_RC:-0}
EOF
cat > "${MOCKBIN}/systemctl" <<'EOF'
#!/usr/bin/env bash
exit ${MOCK_SYSTEMCTL_RC:-0}
EOF
cat > "${MOCKBIN}/nginx" <<'EOF'
#!/usr/bin/env bash
[ "${1:-}" = "-t" ] && exit ${MOCK_NGINX_RC:-0}
exit 0
EOF
cat > "${MOCKBIN}/supervisorctl" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  reread)  exit ${MOCK_SUP_REREAD_RC:-0} ;;
  update)  exit ${MOCK_SUP_UPDATE_RC:-0} ;;
  restart) exit ${MOCK_SUP_RESTART_RC:-0} ;;
  start)   exit 0 ;;
  stop)    exit 0 ;;
  status)  printf '%s\n' "${MOCK_SUP_STATUS:-zedproxy-worker:zedproxy-worker_00   RUNNING   pid 100, uptime 0:00:05}"; exit ${MOCK_SUP_STATUS_RC:-0} ;;
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

# shellcheck disable=SC1090
source "$LIB"
# shellcheck disable=SC1090
source "$WRAPPERS"
# shellcheck disable=SC1090
source "$DEPLOY"
# shellcheck disable=SC1090
source "$ROLLBACK"

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
    export ZPD_SCHED_CRON="${BASE}/scheduler.cron"
    export ZPD_SCHED_LOG="${BASE}/scheduler.log"
    export ZPD_WRAPPER_BIN="${BASE}/wbin"
    export ZPD_WRAPPER_LIB="${BASE}/wlib"
    # Loopback health vhost written to a writable temp path (never /etc in tests).
    export ZPD_LOCAL_HEALTH_CONF="${BASE}/local-health.conf"
    export ZPD_FPM_SOCK="${BASE}/php-fpm.sock"
    mkdir -p "${BASE}/releases" "${BASE}/shared/storage/app/public" \
             "${BASE}/shared/storage/framework" "${BASE}/wbin" "${BASE}/wlib"
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

rm -rf "$MOCKBIN"
echo ""
echo "== results: ${PASS} passed, ${FAIL} failed =="
[ "$FAIL" -eq 0 ]
