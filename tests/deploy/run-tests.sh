#!/usr/bin/env bash
# =============================================================================
# Shell tests for the atomic release-deployment system.
#
#   bash tests/deploy/run-tests.sh
#
# Non-git system commands (composer, npm, php, pg_dump, systemctl, nginx,
# supervisorctl, curl, psql, redis-cli) are MOCKED with CONFIGURABLE failures.
# Git is REAL and runs against LOCAL temporary repositories (a bare "remote" with
# a main branch, commits, a lightweight tag and an annotated tag) — never the
# production GitHub repository. No root, no network.
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
exit ${MOCK_COMPOSER_RC:-0}
EOF
cat > "${MOCKBIN}/npm" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  ci)    exit ${MOCK_NPM_CI_RC:-0} ;;
  run)   [ "${2:-}" = "build" ] && exit ${MOCK_NPM_BUILD_RC:-0}; exit 0 ;;
  *)     exit 0 ;;
esac
EOF
cat > "${MOCKBIN}/php" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = "-r" ]; then echo "8.3"; exit 0; fi
if [ "${1:-}" = "-v" ]; then echo "PHP 8.3.0"; exit 0; fi
case "$*" in
  *"artisan migrate"*)        exit ${MOCK_MIGRATE_RC:-0} ;;
  *"artisan schedule:list"*)  exit ${MOCK_SCHEDULE_RC:-0} ;;
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
# Configurable supervisor mock: reread / update / restart / status / stop.
cat > "${MOCKBIN}/supervisorctl" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  reread)  exit ${MOCK_SUP_REREAD_RC:-0} ;;
  update)  exit ${MOCK_SUP_UPDATE_RC:-0} ;;
  restart) exit ${MOCK_SUP_RESTART_RC:-0} ;;
  stop)    exit 0 ;;
  status)  printf '%s\n' "${MOCK_SUP_STATUS:-zedproxy-worker:zedproxy-worker_00   RUNNING   pid 100, uptime 0:00:05}"; exit ${MOCK_SUP_STATUS_RC:-0} ;;
  *)       exit 0 ;;
esac
EOF
cat > "${MOCKBIN}/curl" <<'EOF'
#!/usr/bin/env bash
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
    mkdir -p "$work/app" "$work/bootstrap" "$work/config" "$work/routes" "$work/public" \
             "$work/storage/app/public" "$work/scripts/deploy"
    : > "$work/artisan"; : > "$work/composer.json"; : > "$work/package.json"
    : > "$work/composer.lock"; : > "$work/package-lock.json"
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
    mkdir -p "${BASE}/releases" "${BASE}/shared/storage/app/public" "${BASE}/wbin" "${BASE}/wlib"
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

echo "== ZedProxy deployment tests (Prompt 16: legacy cutover + wrappers) =="

# ─────────────────────────────────────────────────────────────────────────────
# update.sh — strict ref handling (Defect 3)
# ─────────────────────────────────────────────────────────────────────────────
# Build a repo whose deploy.sh writes a marker so we can tell if it actually ran.
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

# 3. Exact updater SHA is verified + valid ref runs deploy
( ZPD_DEPLOY_ENV="$NOENV" ZPD_REPO_URL="$UPD_BARE" ZPD_REF=main bash "$UPDATE_SH" "$MARKER" >/dev/null 2>&1 )
assert_eq "$(cat "$MARKER" 2>/dev/null)" "RAN" "update.sh runs deploy for a valid ref"
rm -f "$MARKER"

# 1. Invalid updater ref fails BEFORE deploy (marker never written)
( ZPD_DEPLOY_ENV="$NOENV" ZPD_REPO_URL="$UPD_BARE" ZPD_REF=does-not-exist bash "$UPDATE_SH" "$MARKER" >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "update.sh fails on an invalid ref"
assert_false test -f "$MARKER" # deploy never ran
rm -f "$MARKER"

# 2. Checkout failure is NOT ignored — mock git that resolves ls-remote but fails checkout.
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
# Command-wrapper installer (Defect 2)
# ─────────────────────────────────────────────────────────────────────────────
# 4. Detect an existing legacy zedproxy-update wrapper (pointing at the base
#    deployer), then confirm zpw_install_wrappers replaces it with the stable one.
new_base
old="${ZPD_WRAPPER_BIN}/zedproxy-update"
printf '#!/usr/bin/env bash\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "$old"; chmod +x "$old"
assert_true bash -c "grep -q '${BASE}/scripts/deploy/deploy.sh' '$old'"   # legacy wrapper detected
assert_true zpw_install_wrappers
assert_false bash -c "grep -q '${BASE}/scripts/deploy/deploy.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"   # old broken wrapper replaced
assert_true bash -c "grep -q 'zpd_resolve_script update.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_eq "$(stat -c '%a' "${ZPD_WRAPPER_BIN}/zedproxy-update")" "755" "update wrapper is 0755"
assert_eq "$(stat -c '%a' "${ZPD_WRAPPER_LIB}/bootstrap.sh")" "644" "bootstrap.sh is 0644"
# 6/7/8. New wrapper resolves current/update.sh, from any directory.
mkdir -p "${BASE}/current/scripts/deploy"; : > "${BASE}/current/update.sh"
: > "${BASE}/current/scripts/deploy/rollback.sh"; : > "${BASE}/current/scripts/deploy/deploy-status.sh"
assert_true zpw_verify_wrappers "$BASE"
resolved="$( cd /root 2>/dev/null || cd /; ZPD_BASE="$BASE"; . "${ZPD_WRAPPER_LIB}/bootstrap.sh"; zpd_resolve_script update.sh )"
assert_eq "$resolved" "${BASE}/current/update.sh" "wrapper resolves current/update.sh from another CWD (/root or /)"
resolved="$( cd /tmp; ZPD_BASE="$BASE"; . "${ZPD_WRAPPER_LIB}/bootstrap.sh"; zpd_resolve_script update.sh )"
assert_eq "$resolved" "${BASE}/current/update.sh" "wrapper resolves current/update.sh from /tmp"
# 10. Backup + restore round-trip.
bdir="${BASE}/wbackup"
assert_true zpw_backup_wrappers "$bdir"
printf 'MUTATED' > "${ZPD_WRAPPER_BIN}/zedproxy-update"
assert_true zpw_restore_wrappers "$bdir"
assert_false bash -c "grep -q MUTATED '${ZPD_WRAPPER_BIN}/zedproxy-update'"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Strict Supervisor cutover (Defect 1)
# ─────────────────────────────────────────────────────────────────────────────
# 16. Missing Supervisor config → created explicitly, on current/.
new_base
rm -f "$ZPD_SUPERVISOR_CONF"
assert_true dep_cutover_supervisor "$BASE"
assert_true test -f "$ZPD_SUPERVISOR_CONF"
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
rm -rf "$BASE"

# 15. Effective worker command still on the legacy path is rejected.
new_base
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"
assert_false zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
rm -rf "$BASE"

# 12/13. reread / update failure fail the cutover (→ rollback).
new_base; setup_legacy_service_configs
( MOCK_SUP_REREAD_RC=1 dep_cutover_supervisor "$BASE" ); assert_rc 1 "$?" "supervisor reread failure fails cutover"
rm -rf "$BASE"
new_base; setup_legacy_service_configs
( MOCK_SUP_UPDATE_RC=1 dep_cutover_supervisor "$BASE" ); assert_rc 1 "$?" "supervisor update failure fails cutover"
rm -rf "$BASE"

# 14. Worker restart failure fails activation (dep_restart_workers is required).
new_base; mk_release "20260101000000-aaaaaaaaaaaa" activating
( MOCK_SUP_RESTART_RC=1 setup_atomic_service_configs; MOCK_SUP_RESTART_RC=1 dep_activate "20260101000000-aaaaaaaaaaaa" "php8.3-fpm" "" 0 "" ); assert_rc 31 "$?" "worker restart failure → activation 31"
rm -rf "$BASE"

# 11 (unit). Supervisor group not running → readiness rejects.
new_base
( MOCK_SUP_STATUS="zedproxy-worker:zedproxy-worker_00   FATAL   Exited too quickly" dep_supervisor_group_running ); assert_rc 1 "$?" "FATAL worker group is not 'running'"
( dep_supervisor_group_running ); assert_rc 0 "$?" "RUNNING worker group passes"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Strict scheduler cutover (Defect 1)
# ─────────────────────────────────────────────────────────────────────────────
# 18. Missing cron → created explicitly (atomic), single current/ entry.
new_base
rm -f "$ZPD_SCHED_CRON"
assert_true dep_cutover_scheduler "$BASE"
assert_true test -f "$ZPD_SCHED_CRON"
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
assert_eq "$(grep -c 'schedule:run' "$ZPD_SCHED_CRON")" "1" "exactly one schedule:run entry"
assert_eq "$(stat -c '%a' "$ZPD_SCHED_CRON")" "644" "cron mode 0644"
rm -rf "$BASE"

# 19. Duplicate scheduler lines are rejected by the validator.
new_base
{ zpd_scheduler_cron_content "$BASE"; zpd_scheduler_cron_content "$BASE"; } > "$ZPD_SCHED_CRON"
assert_false zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
rm -rf "$BASE"

# 20. Scheduler still using the legacy path is rejected.
new_base
printf '* * * * * www-data php %s/artisan schedule:run\n' "$BASE" > "$ZPD_SCHED_CRON"
assert_false zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"
rm -rf "$BASE"

# 17. Cron rewrite failure triggers rollback — the cron's parent is a FILE, so
# the atomic write cannot create it (works whether or not the test runs as root).
new_base
: > "${BASE}/notadir"
export ZPD_SCHED_CRON="${BASE}/notadir/scheduler.cron"
( dep_cutover_scheduler "$BASE" ); rc=$?
assert_rc 1 "$rc" "cron write with a non-directory parent fails the cutover"
rm -rf "$BASE"

# 21. schedule:list failure → readiness/verify fails.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
( MOCK_SCHEDULE_RC=1 dep_verify_scheduler "$(zpd_current_link)" ); assert_rc 1 "$?" "schedule:list failure detected"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Full legacy → atomic first cutover (success + failure paths)
# ─────────────────────────────────────────────────────────────────────────────
# 22/23/24/5. First legacy cutover success: services on current/, wrapper replaced,
# readiness verifies current Nginx/Supervisor/Cron; cannot succeed on legacy paths.
new_base; mk_source_repo
make_legacy_base
setup_legacy_service_configs
# Pre-existing OLD broken wrapper on the legacy server.
printf '#!/usr/bin/env bash\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "${ZPD_WRAPPER_BIN}/zedproxy-update"
chmod +x "${ZPD_WRAPPER_BIN}/zedproxy-update"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
assert_true zpd_is_legacy_layout
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "first legacy cutover succeeds"
assert_true zpd_is_atomic_layout
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"                 # 22
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"            # 22/23
assert_true zpd_scheduler_cron_ok "$ZPD_SCHED_CRON" "$BASE"            # 22/24
# 5. Old broken wrapper replaced by the stable one from the new release.
assert_false bash -c "grep -q '${BASE}/scripts/deploy/deploy.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_true bash -c "grep -q 'zpd_resolve_script update.sh' '${ZPD_WRAPPER_BIN}/zedproxy-update'"
assert_true zpw_verify_wrappers "$BASE"
# legacy uploads preserved through shared
assert_true test -f "${BASE}/shared/storage/app/public/u.txt"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# 23. Deployment cannot report success while the worker config still uses legacy:
# the readiness gate must reject a legacy supervisor command even when HTTP is OK.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
setup_atomic_service_configs
printf '[program:zedproxy-worker]\ncommand=php %s/artisan queue:work\n' "$BASE" > "$ZPD_SUPERVISOR_CONF"  # legacy worker
( MOCK_HTTP_CODE=200 dep_verify_release "$BASE" "20260101000000-aaaaaaaaaaaa" "" ); assert_rc 1 "$?" "readiness rejects a legacy worker command (no false success)"
rm -rf "$BASE"

# 24. Same, but the scheduler cron uses the legacy path.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
setup_atomic_service_configs
printf '* * * * * www-data php %s/artisan schedule:run\n' "$BASE" > "$ZPD_SCHED_CRON"  # legacy scheduler
( MOCK_HTTP_CODE=200 dep_verify_release "$BASE" "20260101000000-aaaaaaaaaaaa" "" ); assert_rc 1 "$?" "readiness rejects a legacy scheduler cron (no false success)"
rm -rf "$BASE"

# 11/first-cutover-failure. Supervisor rewrite/reread failure triggers legacy rollback,
# restoring the previous wrappers (10).
new_base; mk_source_repo; make_legacy_base; setup_legacy_service_configs
printf '#!/usr/bin/env bash\n# OLD LEGACY WRAPPER\nexec sudo bash "%s/scripts/deploy/deploy.sh" "$@"\n' "$BASE" > "${ZPD_WRAPPER_BIN}/zedproxy-update"
chmod +x "${ZPD_WRAPPER_BIN}/zedproxy-update"
nginx_before="$(cat "$ZPD_NGINX_CONF")"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_SUP_REREAD_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "supervisor reread failure fails the first cutover"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "legacy nginx restored after supervisor failure"
assert_false test -L "${BASE}/current"                                  # 10
assert_true bash -c "grep -q 'OLD LEGACY WRAPPER' '${ZPD_WRAPPER_BIN}/zedproxy-update'"   # previous wrapper restored
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Normal (already-atomic) update keeps using the active wrapper (9)
# ─────────────────────────────────────────────────────────────────────────────
new_base; mk_source_repo
setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "already-atomic first release deploys"
first="$(zpd_current_release)"
setup_atomic_service_configs   # configs are already current; keep them
sleep 1
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "normal second update deploys"
assert_true zpw_verify_wrappers "$BASE"   # 9: active wrapper resolves current/
assert_false bash -c "[ '$first' = '$(zpd_current_release)' ]"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Readiness — PostgreSQL / Redis connectivity gate the deploy
# ─────────────────────────────────────────────────────────────────────────────
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
setup_atomic_service_configs
zpd_write_manifest "${BASE}/releases/20260101000000-aaaaaaaaaaaa/RELEASE_MANIFEST.json" "release_id=20260101000000-aaaaaaaaaaaa" "result=success"
( MOCK_HTTP_CODE=200 MOCK_PSQL_RC=1 dep_check_pg "${BASE}/shared/.env" ); assert_rc 1 "$?" "PG connectivity failure detected"
( MOCK_REDIS_PONG=NOPE dep_check_redis "${BASE}/shared/.env" ); assert_rc 1 "$?" "Redis non-PONG detected"
( dep_check_pg "${BASE}/shared/.env" ); assert_rc 0 "$?" "PG connectivity OK"
( dep_check_redis "${BASE}/shared/.env" ); assert_rc 0 "$?" "Redis PONG OK"
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Retained coverage from the prior suite (resolution, locking, build, rollback…)
# ─────────────────────────────────────────────────────────────────────────────
# Repo resolution + ".": never CWD.
new_base
assert_eq "$(unset ZPD_REPO_URL; zpd_resolve_repo_url)" "https://github.com/Mhoseinshah1/zed_web.git" "unset repo → safe public default"
assert_false zpd_is_safe_repo_url "."
assert_false zpd_is_safe_repo_url "relname"
assert_true  zpd_is_safe_repo_url "https://github.com/o/r.git"
rm -rf "$BASE"

# Ref → SHA (branch/tag/annotated/full/missing).
mk_source_repo
assert_eq "$(zpd_resolve_sha "$SRC_BARE" main)" "$SRC_MAIN_SHA" "resolve branch"
assert_eq "$(zpd_resolve_sha "$SRC_BARE" v1.0.0)" "$SRC_TAG_SHA" "resolve lightweight tag"
assert_eq "$(zpd_resolve_sha "$SRC_BARE" v2.0.0)" "$SRC_ATAG_SHA" "resolve annotated tag (peeled)"
assert_eq "$(zpd_resolve_sha "$SRC_BARE" "$SRC_MAIN_SHA")" "$SRC_MAIN_SHA" "resolve full SHA"
( zpd_resolve_sha "$SRC_BARE" nope >/dev/null ); assert_rc 1 "$?" "missing ref fails"
rm -rf "$(dirname "$SRC_BARE")"

# Release id never nogit in production.
( zpd_release_id "" >/dev/null ); assert_rc 1 "$?" "empty SHA → no id"
nogit_id="$(ZPD_ALLOW_NOGIT=1 zpd_release_id '')"; case "$nogit_id" in *-nogit) ok "nogit only under fixture" ;; *) bad "nogit fixture" ;; esac

# Full dep_main happy path from arbitrary CWD + manifest SHA.
new_base; mk_source_repo; setup_atomic_service_configs
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( cd / && MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "deploy from / succeeds"
man="${BASE}/releases/$(zpd_current_release)/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" git_sha)" "$SRC_MAIN_SHA" "manifest git_sha == deployed HEAD"
assert_false bash -c "zpd_current_release | grep -q nogit"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# Clone failure leaves live app untouched, no nogit/.pending dir.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"; before="$(zpd_current_release)"
setup_atomic_service_configs
export ZPD_REPO_URL="https://nonexistent.invalid.example/x.git" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 1 "$?" "clone failure fails deploy"
assert_eq "$(zpd_current_release)" "$before" "live release untouched"
assert_false bash -c "ls -d ${BASE}/releases/*nogit* >/dev/null 2>&1"
assert_false bash -c "ls -d ${BASE}/releases/.pending* >/dev/null 2>&1"
unset ZPD_REPO_URL ZPD_REF; rm -rf "$BASE"

# Lock contention + release on interruption.
new_base
( exec 9>"$ZPD_LOCK_FILE"; flock 9; sleep 3 ) & holder=$!
sleep 0.4
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 200 "$?" "second deploy busy"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
rm -rf "$BASE"

# Preflight + build codes.
new_base; rm -f "${BASE}/shared/.env"; assert_false dep_preflight "${BASE}/shared"; rm -rf "$BASE"
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; ( dep_build "$rel" ); assert_rc 9 "$?" "missing package-lock → 9"; rm -rf "$BASE"
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; : > "$rel/package-lock.json"; ( MOCK_NPM_CI_RC=1 dep_build "$rel" ); assert_rc 11 "$?" "npm ci → 11"; rm -rf "$BASE"

# Migration failure (no switch) + nginx -t failure.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
mk_release "20260102000000-bbbbbbbbbbbb" activating; before="$(zpd_current_release)"
( MOCK_MIGRATE_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "$before" 0 "" ); assert_rc 30 "$?" "migration failure → 30"
assert_eq "$(zpd_current_release)" "$before" "symlink not switched on migration failure"
rm -rf "$BASE"

# Atomic symlink relative target.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; mk_release "20260102000000-bbbbbbbbbbbb" success
assert_true zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(readlink "$(zpd_current_link)")" "releases/20260102000000-bbbbbbbbbbbb" "relative symlink"
rm -rf "$BASE"

# public/storage link + shared preservation.
new_base
OLD="$(mktemp -d)"; printf 'APP_KEY=base64:ORIGINALKEY0000000000000000000000000000000=\n' > "${OLD}/.env"
mkdir -p "${OLD}/storage/app/public"; echo up > "${OLD}/storage/app/public/u.txt"; SH2="$(mktemp -d)"
assert_true zpd_init_shared_from_existing "$OLD" "$SH2"
REL="$(mktemp -d)"; mkdir -p "${REL}/public"; : > "${REL}/.env"; rm -rf "${REL}/storage"
assert_true zpd_link_shared "$REL" "$SH2"
assert_true test -L "${REL}/public/storage"
rm -rf "$OLD" "$SH2" "$REL" "$BASE"

# Manual rollback default target (previous healthy) + legacy target.
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; mk_release "20260102000000-bbbbbbbbbbbb" success
zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(rb_default_target)" "20260101000000-aaaaaaaaaaaa" "default target is previous healthy"
rm -rf "$BASE"
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
mkdir -p "${BASE}/shared/deploy"; zpd_write_manifest "$(zpd_legacy_marker_file)" "legacy_base=${BASE}"
assert_eq "$(rb_default_target)" "legacy" "legacy rollback target when no previous release"
rm -rf "$BASE"

# Secret masking never leaks into a manifest.
new_base
zpd_write_manifest "${BASE}/m.json" "note=connect postgres://u:supersecret@db:5432/x PGPASSWORD=topsecret"
assert_false bash -c "grep -q supersecret '${BASE}/m.json'"
assert_false bash -c "grep -q topsecret '${BASE}/m.json'"
rm -rf "$BASE"

rm -rf "$MOCKBIN"
echo ""
echo "== results: ${PASS} passed, ${FAIL} failed =="
[ "$FAIL" -eq 0 ]
