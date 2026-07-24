#!/usr/bin/env bash
# =============================================================================
# Shell tests for the atomic release-deployment system.
#
#   bash tests/deploy/run-tests.sh
#
# Everything runs in temp directories with MOCKED system commands (composer,
# npm, php, pg_dump, systemctl, nginx, supervisorctl, curl, git). No root, no
# network, and the CI runner's real Nginx/PostgreSQL/Supervisor are never touched.
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LIB="${REPO_ROOT}/scripts/lib/deploy-lib.sh"
DEPLOY="${REPO_ROOT}/scripts/deploy/deploy.sh"
ROLLBACK="${REPO_ROOT}/scripts/deploy/rollback.sh"

PASS=0; FAIL=0
ok()  { echo "  ok   - $*"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL - $*"; FAIL=$((FAIL + 1)); }
assert_true()  { if "$@"; then ok "$*"; else bad "$* (expected success)"; fi; }
assert_false() { if "$@"; then bad "$* (expected failure)"; else ok "! $*"; fi; }
assert_eq()    { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
assert_rc()    { local want="$1" got="$2" msg="$3"; if [ "$got" = "$want" ]; then ok "$msg"; else bad "$msg (rc=$got want=$want)"; fi; }

# ── Mocked commands ──────────────────────────────────────────────────────────
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
# artisan <cmd ...>
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

cat > "${MOCKBIN}/supervisorctl" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  restart) exit ${MOCK_SUPERVISOR_RESTART_RC:-0} ;;
  stop)    exit 0 ;;
  *)       exit 0 ;;
esac
EOF

cat > "${MOCKBIN}/curl" <<'EOF'
#!/usr/bin/env bash
echo "${MOCK_HTTP_CODE:-200}"
exit 0
EOF

cat > "${MOCKBIN}/git" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  *"rev-parse"*) echo "abcdef123456"; exit 0 ;;
  clone*)
    for a in "$@"; do target="$a"; done
    mkdir -p "$target/storage/app/public"
    : > "$target/artisan"; : > "$target/composer.json"; : > "$target/package.json"
    # Lock files are required by the hardened dep_build (reproducible install).
    : > "$target/composer.lock"; : > "$target/package-lock.json"
    exit ${MOCK_GIT_RC:-0} ;;
  *) exit 0 ;;
esac
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
export ZPD_GIT="${MOCKBIN}/git"
export ZPD_MIN_DISK_MB=1
export ZPD_HEALTH_URL="http://localhost"

# shellcheck disable=SC1090
source "$LIB"
# shellcheck disable=SC1090
source "$DEPLOY"
# shellcheck disable=SC1090
source "$ROLLBACK"

# ── Per-test isolated base ───────────────────────────────────────────────────
new_base() {
    BASE="$(mktemp -d)"
    export ZPD_BASE="$BASE"
    export ZPD_LOCK_FILE="${BASE}/deploy.lock"
    export ZPD_LOG_DIR="${BASE}/logs"
    export ZPD_STATE_FILE="${BASE}/shared/deploy/state.json"
    export ZPD_BACKUP_DIR="${BASE}/backups"
    mkdir -p "${BASE}/releases" "${BASE}/shared/storage/app/public"
    printf 'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_DATABASE=zed\nDB_USERNAME=zed\nDB_PASSWORD=secret\n' > "${BASE}/shared/.env"
}
mk_release() { # id result
    local id="$1" result="${2:-success}"
    mkdir -p "${BASE}/releases/${id}"
    zpd_write_manifest "${BASE}/releases/${id}/RELEASE_MANIFEST.json" "release_id=${id}" "result=${result}"
}

echo "== ZedProxy deployment tests =="

# 1. Successful deployment (full dep_main happy path)
new_base
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "successful deployment returns 0"
assert_true zpd_valid_release_id "$(zpd_current_release)"
assert_eq "$(zpd_manifest_get "$(zpd_state_file)" result)" "success" "state file records success"
rm -rf "$BASE"

# 2. Simultaneous deployment lock
new_base
( exec 9>"$ZPD_LOCK_FILE"; flock 9; sleep 3 ) & holder=$!
sleep 0.4
zpd_run_locked "$ZPD_LOCK_FILE" -- true; rc=$?
assert_rc 200 "$rc" "second deployment fails immediately with busy code"
assert_eq "$(zpd_lock_busy_message)" "یک عملیات به‌روزرسانی دیگر در حال اجرا است." "busy message is Persian"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
rm -rf "$BASE"

# 3. Missing .env
new_base
rm -f "${BASE}/shared/.env"
assert_false dep_preflight "${BASE}/shared"
rm -rf "$BASE"

# 4. Empty APP_KEY
new_base
printf 'APP_KEY=\nDB_DATABASE=zed\n' > "${BASE}/shared/.env"
assert_false dep_preflight "${BASE}/shared"
rm -rf "$BASE"

# 5. Failed PostgreSQL backup stops deployment
new_base
( MOCK_PGDUMP_RC=1 dep_backup_database "${BASE}/backups/db.dump" "${BASE}/shared/.env" ); rc=$?
assert_rc 1 "$rc" "failed pg_dump returns non-zero"
rm -rf "$BASE"

# 6. Composer failure — release discarded, current untouched
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success
zpd_switch_current "20260101000000-aaaaaaaaaaaa"
before="$(zpd_current_release)"
( MOCK_COMPOSER_RC=1 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "composer failure fails deployment"
assert_eq "$(zpd_current_release)" "$before" "current release untouched after composer failure"
assert_true bash -c "ls -d ${BASE}/releases/*.failed >/dev/null 2>&1"
rm -rf "$BASE"

# 7. npm ci failure
new_base
rel="${BASE}/releases/rel"; mkdir -p "$rel"
: > "$rel/composer.lock"; : > "$rel/package-lock.json"
( MOCK_NPM_CI_RC=1 dep_build "$rel" ); assert_rc 11 "$?" "npm ci failure returns build code 11"
rm -rf "$BASE"

# 7b. Missing lock file → build refuses (reproducible-install guard)
new_base
rel="${BASE}/releases/rel"; mkdir -p "$rel"
: > "$rel/composer.lock"   # package-lock.json intentionally absent
( dep_build "$rel" ); assert_rc 9 "$?" "missing package-lock.json returns build code 9"
rm -rf "$BASE"

# 8. Asset build failure
new_base
rel="${BASE}/releases/rel"; mkdir -p "$rel"
: > "$rel/composer.lock"; : > "$rel/package-lock.json"
( MOCK_NPM_BUILD_RC=1 dep_build "$rel" ); assert_rc 12 "$?" "npm run build failure returns build code 12"
rm -rf "$BASE"

# 9. Migration failure (activation aborts before switch)
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success
zpd_switch_current "20260101000000-aaaaaaaaaaaa"
mk_release "20260102000000-bbbbbbbbbbbb" activating
before="$(zpd_current_release)"
( MOCK_MIGRATE_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "$before" ); rc=$?
assert_rc 30 "$rc" "migration failure returns 30"
assert_eq "$(zpd_current_release)" "$before" "symlink not switched on migration failure"
rm -rf "$BASE"

# 10. Nginx validation failure during activation
new_base
mk_release "20260102000000-bbbbbbbbbbbb" activating
( MOCK_NGINX_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "" ); rc=$?
assert_rc 31 "$rc" "nginx -t failure returns 31"
rm -rf "$BASE"

# 11. PHP-FPM reload failure
new_base
mk_release "20260102000000-bbbbbbbbbbbb" activating
( MOCK_SYSTEMCTL_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "" ); rc=$?
assert_rc 31 "$rc" "php-fpm reload failure returns 31"
rm -rf "$BASE"

# 12. Queue restart failure
new_base
mk_release "20260102000000-bbbbbbbbbbbb" activating
( MOCK_SUPERVISOR_RESTART_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "" ); rc=$?
assert_rc 31 "$rc" "worker restart failure returns 31"
rm -rf "$BASE"

# 13. Health-check failure
new_base
( MOCK_HTTP_CODE=500 dep_health "http://localhost" ); assert_rc 1 "$?" "health check fails on non-200"
( MOCK_HTTP_CODE=200 dep_health "http://localhost" ); assert_rc 0 "$?" "health check passes on 200"
rm -rf "$BASE"

# 14. Atomic symlink switch
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success
mk_release "20260102000000-bbbbbbbbbbbb" success
assert_true zpd_switch_current "20260101000000-aaaaaaaaaaaa"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "current points to first release"
assert_true zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(zpd_current_release)" "20260102000000-bbbbbbbbbbbb" "atomic switch updates current"
assert_eq "$(readlink "$(zpd_current_link)")" "releases/20260102000000-bbbbbbbbbbbb" "current is a relative symlink"
rm -rf "$BASE"

# 15. Automatic rollback on activation failure
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success
zpd_switch_current "20260101000000-aaaaaaaaaaaa"
prev="$(zpd_current_release)"
( MOCK_NGINX_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "activation failure fails the deployment"
assert_eq "$(zpd_current_release)" "$prev" "current rolled back to previous release"
rm -rf "$BASE"

# 16. Existing-install migration into shared
new_base
OLD="$(mktemp -d)"
printf 'APP_KEY=base64:ORIGINALKEY0000000000000000000000000000000=\n' > "${OLD}/.env"
mkdir -p "${OLD}/storage/app/public"; echo "upload" > "${OLD}/storage/app/public/u.txt"
SH2="$(mktemp -d)"
assert_true zpd_init_shared_from_existing "$OLD" "$SH2"
assert_true test -f "${SH2}/.env"
assert_true test -f "${SH2}/storage/app/public/u.txt"
# 18/19. .env + encryption key preserved verbatim
assert_eq "$(grep APP_KEY "${SH2}/.env")" "$(grep APP_KEY "${OLD}/.env")" "APP_KEY preserved unchanged"
# 17. uploaded files preserved and reachable via a release symlink
REL="$(mktemp -d)"; mkdir -p "$REL"; : > "${REL}/.env"; rm -rf "${REL}/storage"
assert_true zpd_link_shared "$REL" "$SH2"
assert_true test -f "${REL}/storage/app/public/u.txt"
assert_eq "$(cat "${REL}/.env")" "$(cat "${SH2}/.env")" ".env served through shared symlink"
rm -rf "$OLD" "$SH2" "$REL" "$BASE"

# 20. Low disk space
new_base
assert_false zpd_check_disk_space "$BASE" 999999999
assert_true  zpd_check_disk_space "$BASE" 0
rm -rf "$BASE"

# 21. Release retention (keep N, never active/previous)
new_base
for n in 1 2 3 4 5 6 7 8; do mk_release "2026010${n}000000-cccccccccccc" success; done
zpd_switch_current "20260101000000-cccccccccccc"   # oldest = active (edge case)
# previous of the oldest is empty; make current the newest to have a real previous
zpd_switch_current "20260108000000-cccccccccccc"
active="$(zpd_current_release)"; previous="$(zpd_previous_release)"
prunable="$(zpd_prunable_releases 3)"
assert_false bash -c "printf '%s\n' \"$prunable\" | grep -q '$active'"
assert_false bash -c "printf '%s\n' \"$prunable\" | grep -q '$previous'"
assert_eq "$(printf '%s\n' "$prunable" | grep -c .)" "5" "keeps 3 newest (active+previous among them), prunes the other 5"
rm -rf "$BASE"

# 22. Manual rollback selects previous healthy + switches
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success
mk_release "20260102000000-bbbbbbbbbbbb" success
zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(rb_default_target)" "20260101000000-aaaaaaaaaaaa" "default target is previous healthy release"
( MOCK_HTTP_CODE=200 rb_rollback "20260101000000-aaaaaaaaaaaa" "php8.3-fpm" >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "manual rollback succeeds"
assert_eq "$(zpd_current_release)" "20260101000000-aaaaaaaaaaaa" "manual rollback switched current"
rm -rf "$BASE"

# 23. Interrupted deployment releases the lock (the lock-holder is killed hard)
new_base
( exec 9>"$ZPD_LOCK_FILE"; flock -n 9; sleep 5 ) & victim=$!
sleep 0.4
# While held, a new deploy must be refused.
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 200 "$?" "lock is held while a deploy runs"
kill -9 "$victim" 2>/dev/null; wait "$victim" 2>/dev/null
zpd_run_locked "$ZPD_LOCK_FILE" -- true; rc=$?
assert_rc 0 "$rc" "lock is free after an interrupted deployment"
rm -rf "$BASE"

# 24. Scheduler verification
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success
zpd_switch_current "20260101000000-aaaaaaaaaaaa"
( MOCK_SCHEDULE_RC=0 dep_verify_scheduler "$(zpd_current_link)" ); assert_rc 0 "$?" "scheduler verified when schedule:list works"
( MOCK_SCHEDULE_RC=1 dep_verify_scheduler "$(zpd_current_link)" ); assert_rc 1 "$?" "scheduler check fails when schedule:list fails"
rm -rf "$BASE"

# 25. Invalid release manifest
new_base
mkdir -p "${BASE}/releases/bad"
printf 'not json at all' > "${BASE}/releases/bad/RELEASE_MANIFEST.json"
assert_false zpd_manifest_valid "${BASE}/releases/bad/RELEASE_MANIFEST.json"
zpd_write_manifest "${BASE}/releases/good.json" "release_id=20260101000000-aaaaaaaaaaaa" "result=success"
assert_true  zpd_manifest_valid "${BASE}/releases/good.json"
assert_eq "$(zpd_manifest_get "${BASE}/releases/good.json" release_id)" "20260101000000-aaaaaaaaaaaa" "manifest value read back"
assert_eq "$(zpd_manifest_get "${BASE}/releases/good.json" missing)" "" "missing key returns empty"
rm -rf "$BASE"

# Secret masking never leaks into a manifest
new_base
zpd_write_manifest "${BASE}/m.json" "note=connect postgres://u:supersecret@db:5432/x PGPASSWORD=topsecret"
assert_false bash -c "grep -q supersecret '${BASE}/m.json'"
assert_false bash -c "grep -q topsecret '${BASE}/m.json'"
rm -rf "$BASE"

rm -rf "$MOCKBIN"
echo ""
echo "== results: ${PASS} passed, ${FAIL} failed =="
[ "$FAIL" -eq 0 ]
