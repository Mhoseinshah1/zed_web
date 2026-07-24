#!/usr/bin/env bash
# =============================================================================
# Shell tests for the atomic release-deployment system.
#
#   bash tests/deploy/run-tests.sh
#
# System commands that are NOT git (composer, npm, php, pg_dump, systemctl,
# nginx, supervisorctl, curl) are MOCKED. Git is REAL and runs against LOCAL
# temporary repositories created per-run (a bare "remote" with a main branch,
# commits, a lightweight tag and an annotated tag) — never the production GitHub
# repository. No root, no network. The runner's real Nginx/PostgreSQL/Supervisor
# are never touched.
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
cat > "${MOCKBIN}/supervisorctl" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "${MOCKBIN}/curl" <<'EOF'
#!/usr/bin/env bash
echo "${MOCK_HTTP_CODE:-200}"
exit 0
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
export ZPD_GIT="git"                 # REAL git against local temp repos
export ZPD_MIN_DISK_MB=1
export ZPD_HEALTH_URL="http://localhost"
export ZPD_ALLOW_LOCAL_REPO=1        # tests use absolute local bare repos
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

# shellcheck disable=SC1090
source "$LIB"
# shellcheck disable=SC1090
source "$DEPLOY"
# shellcheck disable=SC1090
source "$ROLLBACK"

# ── Build a real "remote" bare repo with main + tags ─────────────────────────
# Sets globals: SRC_BARE, SRC_MAIN_SHA, SRC_TAG_SHA (v1.0.0 lightweight),
# SRC_ATAG_SHA (v2.0.0 annotated, peeled commit).
mk_source_repo() {
    local work; work="$(mktemp -d)"
    git -C "$work" init -q -b main
    mkdir -p "$work/app" "$work/bootstrap" "$work/config" "$work/routes" "$work/public" "$work/storage/app/public"
    : > "$work/artisan"; : > "$work/composer.json"; : > "$work/package.json"
    : > "$work/composer.lock"; : > "$work/package-lock.json"
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
    mkdir -p "${BASE}/releases" "${BASE}/shared/storage/app/public"
    printf 'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_DATABASE=zed\nDB_USERNAME=zed\nDB_PASSWORD=secret\n' > "${BASE}/shared/.env"
}
mk_release() { local id="$1" result="${2:-success}"; mkdir -p "${BASE}/releases/${id}"; zpd_write_manifest "${BASE}/releases/${id}/RELEASE_MANIFEST.json" "release_id=${id}" "result=${result}"; }

echo "== ZedProxy deployment tests =="

# ─────────────────────────────────────────────────────────────────────────────
# Repository-source resolution (root-cause #1: never ".")
# ─────────────────────────────────────────────────────────────────────────────
new_base
# 3/6. ZPD_REPO_URL unset AND no origin → safe built-in public default
unset ZPD_REPO_URL
assert_eq "$(zpd_resolve_repo_url)" "https://github.com/Mhoseinshah1/zed_web.git" "unset repo → safe public default (never CWD)"
# 5. explicit override wins
ZPD_REPO_URL="https://example.com/x/y.git" assert_eq "$(ZPD_REPO_URL=https://example.com/x/y.git zpd_resolve_repo_url)" "https://example.com/x/y.git" "explicit ZPD_REPO_URL override"
# 6. reject "." and relative paths as an implicit fallback
assert_false zpd_is_safe_repo_url "."
assert_false zpd_is_safe_repo_url "./sub"
assert_false zpd_is_safe_repo_url "../x"
assert_false zpd_is_safe_repo_url "relname"
assert_true  zpd_is_safe_repo_url "https://github.com/o/r.git"
assert_true  zpd_is_safe_repo_url "git@github.com:o/r.git"
# absolute local path only allowed under the explicit flag
( unset ZPD_ALLOW_LOCAL_REPO; ! zpd_is_safe_repo_url "/srv/repo.git" ); assert_rc 0 "$?" "absolute local path rejected without ZPD_ALLOW_LOCAL_REPO"
assert_true zpd_is_safe_repo_url "/srv/repo.git"   # ZPD_ALLOW_LOCAL_REPO=1 in env
rm -rf "$BASE"

# 8. active-release git origin fallback
new_base
mk_source_repo
rel="${BASE}/releases/20260101000000-aaaaaaaaaaaa"; git clone -q "$SRC_BARE" "$rel" >/dev/null
zpd_switch_current "20260101000000-aaaaaaaaaaaa"
( unset ZPD_REPO_URL; assert_eq "$(zpd_resolve_repo_url)" "$SRC_BARE" "active-release origin used when ZPD_REPO_URL unset" )
rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# 9. legacy git origin fallback
new_base
mk_source_repo
git clone -q "$SRC_BARE" "${BASE}/legacyco" >/dev/null
# Simulate legacy layout: base itself is a git checkout (no current symlink)
rm -rf "${BASE}/.git"; cp -a "${BASE}/legacyco/.git" "${BASE}/.git"; rm -rf "${BASE}/legacyco"
( unset ZPD_REPO_URL; assert_eq "$(zpd_resolve_repo_url)" "$SRC_BARE" "legacy origin used when no active release" )
rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# 7/10. persistent deploy.env + missing-source abort
new_base
export ZPD_DEPLOY_ENV="${BASE}/deploy.env"
zpd_write_deploy_env "$ZPD_DEPLOY_ENV" "ZPD_REPO_URL=https://github.com/o/fromfile.git" "ZPD_REF=main" "ZPD_BASE=${BASE}"
assert_true test -f "$ZPD_DEPLOY_ENV"
assert_eq "$(stat -c '%a' "$ZPD_DEPLOY_ENV")" "600" "deploy.env is mode 600"
( unset ZPD_REPO_URL; zpd_load_deploy_env; assert_eq "$ZPD_REPO_URL" "https://github.com/o/fromfile.git" "deploy.env repo loaded when env unset" )
# secret-looking keys are never written
zpd_write_deploy_env "$ZPD_DEPLOY_ENV" "ZPD_BASE=${BASE}" "DB_PASSWORD=leak" "APP_KEY=leak2" "ZPD_REF=main"
assert_false bash -c "grep -q leak '${ZPD_DEPLOY_ENV}'"
unset ZPD_DEPLOY_ENV
rm -rf "$BASE"

# ─────────────────────────────────────────────────────────────────────────────
# Ref → exact SHA resolution (real git ls-remote)
# ─────────────────────────────────────────────────────────────────────────────
mk_source_repo
# 11. branch
assert_eq "$(zpd_resolve_sha "$SRC_BARE" main)" "$SRC_MAIN_SHA" "resolve branch main → tip SHA"
# 12. lightweight tag
assert_eq "$(zpd_resolve_sha "$SRC_BARE" v1.0.0)" "$SRC_TAG_SHA" "resolve lightweight tag → commit SHA"
# 13. annotated tag (peeled to commit)
assert_eq "$(zpd_resolve_sha "$SRC_BARE" v2.0.0)" "$SRC_ATAG_SHA" "resolve annotated tag → peeled commit SHA"
# 14. full SHA
assert_eq "$(zpd_resolve_sha "$SRC_BARE" "$SRC_MAIN_SHA")" "$SRC_MAIN_SHA" "resolve full SHA → itself"
# 15. missing ref fails
( zpd_resolve_sha "$SRC_BARE" no-such-ref >/dev/null ); assert_rc 1 "$?" "missing ref → resolution fails"
rm -rf "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Release id contains SHA; never "nogit" in production
# ─────────────────────────────────────────────────────────────────────────────
# 16/17.
id="$(zpd_release_id abcdef123456)"
assert_true zpd_valid_release_id "$id"
assert_true bash -c "printf '%s' '$id' | grep -q -- '-abcdef123456$'"
( zpd_release_id "" >/dev/null ); assert_rc 1 "$?" "empty SHA without ZPD_ALLOW_NOGIT → no release id"
prod_id="$(zpd_release_id '' 2>/dev/null || true)"
assert_eq "$prod_id" "" "empty SHA yields no id (never a nogit release) in production"
nogit_id="$(ZPD_ALLOW_NOGIT=1 zpd_release_id '')"
case "$nogit_id" in *-nogit) ok "ZPD_ALLOW_NOGIT fixture yields -nogit id" ;; *) bad "nogit fixture id ($nogit_id)" ;; esac

# 18. clone checks out the exact resolved SHA
mk_source_repo
d="$(mktemp -d)/rel"
assert_true zpd_git_clone_ref "$SRC_BARE" v1.0.0 "$d"
assert_eq "$(zpd_git_head_sha "$d")" "$SRC_TAG_SHA" "clone checked out the exact tag commit"
rm -rf "$(dirname "$d")" "$(dirname "$SRC_BARE")"

# 19. git errors captured + redacted but useful
mk_source_repo
errf="$(mktemp)"
( zpd_git_clone_ref "https://user:supersecret@nonexistent.invalid.example/x.git" main "$(mktemp -d)/z" "$errf" ); rc=$?
assert_rc 1 "$rc" "clone of an unreachable repo fails"
assert_true test -s "$errf"
assert_false bash -c "zpd_redact_file '$errf' | grep -q supersecret"   # credential never shown
rm -f "$errf"; rm -rf "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Full dep_main against a real repo (from arbitrary working directories)
# ─────────────────────────────────────────────────────────────────────────────
# 1/44. successful deployment invoked from an arbitrary CWD (e.g. /)
new_base; mk_source_repo
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( cd / && MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "successful deployment from / returns 0"
assert_true zpd_valid_release_id "$(zpd_current_release)"
assert_eq "$(zpd_manifest_get "$(zpd_state_file)" result)" "success" "state file records success"
# 16b/17b. release id embeds the resolved main SHA; never nogit
assert_eq "$(zpd_current_release)" "$(zpd_current_release | sed -E 's/-.*//')-${SRC_MAIN_SHA:0:12}" "release id embeds resolved SHA"
assert_false bash -c "zpd_current_release | grep -q nogit"
# manifest records the exact deployed SHA + repo + ref
man="${BASE}/releases/$(zpd_current_release)/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" git_sha)" "$SRC_MAIN_SHA" "manifest git_sha == deployed HEAD"
assert_eq "$(zpd_manifest_get "$man" git_ref)" "main" "manifest records the requested ref"
# 40. public/storage link resolves to shared
assert_true test -L "${BASE}/releases/$(zpd_current_release)/public/storage"
rm -rf "$BASE" "$(dirname "$SRC_BARE")"; unset ZPD_REPO_URL ZPD_REF

# 3b. ZPD_REPO_URL unset but deploy.env provides it (invoked from /root-like dir)
new_base; mk_source_repo
export ZPD_DEPLOY_ENV="${BASE}/deploy.env"
zpd_write_deploy_env "$ZPD_DEPLOY_ENV" "ZPD_BASE=${BASE}" "ZPD_REPO_URL=${SRC_BARE}" "ZPD_REF=main"
tmphome="$(mktemp -d)"
( cd "$tmphome"; unset ZPD_REPO_URL ZPD_REF; MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "deploy works with ZPD_REPO_URL unset (deploy.env), from arbitrary dir"
unset ZPD_DEPLOY_ENV; rm -rf "$BASE" "$tmphome" "$(dirname "$SRC_BARE")"

# 12b. tag deployment records the exact commit
new_base; mk_source_repo
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=v1.0.0
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "tag deployment succeeds"
man="${BASE}/releases/$(zpd_current_release)/RELEASE_MANIFEST.json"
assert_eq "$(zpd_manifest_get "$man" git_sha)" "$SRC_TAG_SHA" "tag deploy records exact tag commit"
rm -rf "$BASE" "$(dirname "$SRC_BARE")"; unset ZPD_REPO_URL ZPD_REF

# 10b. missing repository source aborts before touching anything
new_base
( unset ZPD_REPO_URL; export ZPD_DEPLOY_ENV="${BASE}/none.env"
  # Force an unsafe/empty resolution by disallowing the default via a stub:
  zpd_resolve_repo_url() { return 1; }
  MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "unresolvable repository aborts the deploy"
assert_eq "$(zpd_current_release)" "" "no release created when repo cannot be resolved"
rm -rf "$BASE"

# 15/41. clone failure leaves the live application untouched, no nogit dir
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
before="$(zpd_current_release)"
export ZPD_REPO_URL="https://nonexistent.invalid.example/x.git" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "clone failure fails the deploy"
assert_eq "$(zpd_current_release)" "$before" "live release untouched after clone failure"
assert_false bash -c "ls -d ${BASE}/releases/*nogit* >/dev/null 2>&1"
assert_false bash -c "ls -d ${BASE}/releases/.pending* >/dev/null 2>&1"
rm -rf "$BASE"; unset ZPD_REPO_URL ZPD_REF

# 42. build failure leaves the live application untouched (release → .failed)
new_base; mk_source_repo
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
before="$(zpd_current_release)"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_COMPOSER_RC=1 MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "build failure fails the deploy"
assert_eq "$(zpd_current_release)" "$before" "live release untouched after build failure"
assert_true bash -c "ls -d ${BASE}/releases/*.failed >/dev/null 2>&1"
rm -rf "$BASE" "$(dirname "$SRC_BARE")"; unset ZPD_REPO_URL ZPD_REF

# ─────────────────────────────────────────────────────────────────────────────
# Legacy → first-cutover migration + rollback
# ─────────────────────────────────────────────────────────────────────────────
# 26/27/28/30/31/32/33. first cutover success repoints nginx/supervisor/scheduler
new_base; mk_source_repo
# Simulate a legacy single-dir install at the base with its own artisan/.env and
# NO pre-existing shared dir (so the compatibility migration seeds shared).
rm -rf "${BASE}/shared"
: > "${BASE}/artisan"
printf 'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_DATABASE=zed\nDB_USERNAME=zed\nDB_PASSWORD=secret\n' > "${BASE}/.env"
mkdir -p "${BASE}/storage/app/public"; echo upload > "${BASE}/storage/app/public/u.txt"
export ZPD_NGINX_CONF="${BASE}/nginx.conf"
export ZPD_SUPERVISOR_CONF="${BASE}/worker.conf"
export ZPD_SCHED_CRON="${BASE}/scheduler.cron"
printf 'server {\n    root %s/public;\n}\nserver {\n    ssl_certificate x;\n    root %s/public;\n}\n' "$BASE" "$BASE" > "$ZPD_NGINX_CONF"
printf 'command=php %s/artisan queue:work\nstdout_logfile=%s/storage/logs/worker.log\n' "$BASE" "$BASE" > "$ZPD_SUPERVISOR_CONF"
printf '* * * * * www-data cd %s && php %s/artisan schedule:run >> /var/log/x 2>&1\n' "$BASE" "$BASE" > "$ZPD_SCHED_CRON"
assert_true zpd_is_legacy_layout
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 0 "$rc" "first legacy cutover succeeds"
assert_true zpd_is_atomic_layout
assert_true zpd_nginx_root_ok "$ZPD_NGINX_CONF" "$BASE"
assert_true zpd_supervisor_ok "$ZPD_SUPERVISOR_CONF" "$BASE"
assert_true bash -c "grep -q '${BASE}/current/artisan schedule:run' '$ZPD_SCHED_CRON'"
# 27/28. legacy .env + uploads preserved (moved into shared, still readable)
assert_true test -f "${BASE}/shared/.env"
assert_true test -f "${BASE}/shared/storage/app/public/u.txt"
unset ZPD_NGINX_CONF ZPD_SUPERVISOR_CONF ZPD_SCHED_CRON ZPD_REPO_URL ZPD_REF
rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# 34/35/36/37. first-cutover FAILURE restores legacy nginx/supervisor/scheduler + health
new_base; mk_source_repo
rm -rf "${BASE}/shared"
: > "${BASE}/artisan"
printf 'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_DATABASE=zed\nDB_USERNAME=zed\nDB_PASSWORD=secret\n' > "${BASE}/.env"
export ZPD_NGINX_CONF="${BASE}/nginx.conf" ZPD_SUPERVISOR_CONF="${BASE}/worker.conf" ZPD_SCHED_CRON="${BASE}/scheduler.cron"
printf 'server {\n    root %s/public;\n}\n' "$BASE" > "$ZPD_NGINX_CONF"
printf 'command=php %s/artisan queue:work\nstdout_logfile=%s/storage/logs/worker.log\n' "$BASE" "$BASE" > "$ZPD_SUPERVISOR_CONF"
printf 'php %s/artisan schedule:run\n' "$BASE" > "$ZPD_SCHED_CRON"
nginx_before="$(cat "$ZPD_NGINX_CONF")"; super_before="$(cat "$ZPD_SUPERVISOR_CONF")"; cron_before="$(cat "$ZPD_SCHED_CRON")"
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
# Health returns 500 → activation fails → first-cutover rollback restores legacy.
( MOCK_HTTP_CODE=500 dep_main >/dev/null 2>&1 ); rc=$?
assert_rc 1 "$rc" "first cutover fails when health is unhealthy"
assert_eq "$(cat "$ZPD_NGINX_CONF")" "$nginx_before" "legacy nginx config restored"
assert_eq "$(cat "$ZPD_SUPERVISOR_CONF")" "$super_before" "legacy supervisor config restored"
assert_eq "$(cat "$ZPD_SCHED_CRON")" "$cron_before" "legacy scheduler cron restored"
assert_false test -L "${BASE}/current"   # current symlink dropped on rollback
unset ZPD_NGINX_CONF ZPD_SUPERVISOR_CONF ZPD_SCHED_CRON ZPD_REPO_URL ZPD_REF
rm -rf "$BASE" "$(dirname "$SRC_BARE")"

# ─────────────────────────────────────────────────────────────────────────────
# Normal (already-atomic) update + rollback after a second release
# ─────────────────────────────────────────────────────────────────────────────
# 38/39.
new_base; mk_source_repo
export ZPD_REPO_URL="$SRC_BARE" ZPD_REF=main
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "already-atomic first release"
first="$(zpd_current_release)"
sleep 1   # ensure a distinct timestamp so the second release id differs
( MOCK_HTTP_CODE=200 dep_main >/dev/null 2>&1 ); assert_rc 0 "$?" "already-atomic second release"
second="$(zpd_current_release)"
assert_false bash -c "[ '$first' = '$second' ]"
( MOCK_HTTP_CODE=200 rb_rollback "$first" "php8.3-fpm" >/dev/null 2>&1 ); assert_rc 0 "$?" "rollback to first release"
assert_eq "$(zpd_current_release)" "$first" "rollback switched current to the previous release"
rm -rf "$BASE" "$(dirname "$SRC_BARE")"; unset ZPD_REPO_URL ZPD_REF

# ─────────────────────────────────────────────────────────────────────────────
# Library-level unit checks (nginx/supervisor rewrite idempotency, layout)
# ─────────────────────────────────────────────────────────────────────────────
new_base
conf="${BASE}/nginx.conf"
printf 'server {\n  root %s/public;\n}\nserver {\n  ssl_certificate a;\n  root %s/current/public;\n}\n' "$BASE" "$BASE" > "$conf"
zpd_nginx_rewrite_root "$conf" "$BASE"
assert_true zpd_nginx_root_ok "$conf" "$BASE"
zpd_nginx_rewrite_root "$conf" "$BASE"      # idempotent
assert_true zpd_nginx_root_ok "$conf" "$BASE"
assert_true bash -c "grep -q 'ssl_certificate a;' '$conf'"   # SSL directive preserved
sconf="${BASE}/w.conf"; printf 'command=php %s/artisan queue:work\n' "$BASE" > "$sconf"
zpd_supervisor_rewrite "$sconf" "$BASE"
assert_true zpd_supervisor_ok "$sconf" "$BASE"
rm -rf "$BASE"

# ── The following reuse the original, still-valid coverage ───────────────────

# Simultaneous deployment lock (46)
new_base
( exec 9>"$ZPD_LOCK_FILE"; flock 9; sleep 3 ) & holder=$!
sleep 0.4
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 200 "$?" "second deployment fails immediately (busy)"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
rm -rf "$BASE"

# Interrupted deployment releases the lock (46b)
new_base
( exec 9>"$ZPD_LOCK_FILE"; flock -n 9; sleep 5 ) & victim=$!
sleep 0.4
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 200 "$?" "lock held while a deploy runs"
kill -9 "$victim" 2>/dev/null; wait "$victim" 2>/dev/null
zpd_run_locked "$ZPD_LOCK_FILE" -- true; assert_rc 0 "$?" "lock free after interrupted deploy"
rm -rf "$BASE"

# Preflight (.env / APP_KEY) and pg_dump failure
new_base; rm -f "${BASE}/shared/.env"; assert_false dep_preflight "${BASE}/shared"; rm -rf "$BASE"
new_base; printf 'APP_KEY=\n' > "${BASE}/shared/.env"; assert_false dep_preflight "${BASE}/shared"; rm -rf "$BASE"
new_base; ( MOCK_PGDUMP_RC=1 dep_backup_database "${BASE}/backups/db.dump" "${BASE}/shared/.env" ); assert_rc 1 "$?" "failed pg_dump returns non-zero"; rm -rf "$BASE"

# Build codes (missing lock, npm ci, npm build)
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; ( dep_build "$rel" ); assert_rc 9 "$?" "missing package-lock.json → build code 9"; rm -rf "$BASE"
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; : > "$rel/package-lock.json"; ( MOCK_NPM_CI_RC=1 dep_build "$rel" ); assert_rc 11 "$?" "npm ci failure → code 11"; rm -rf "$BASE"
new_base; rel="${BASE}/releases/rel"; mkdir -p "$rel"; : > "$rel/composer.lock"; : > "$rel/package-lock.json"; ( MOCK_NPM_BUILD_RC=1 dep_build "$rel" ); assert_rc 12 "$?" "npm build failure → code 12"; rm -rf "$BASE"

# Activation-stage failures
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
mk_release "20260102000000-bbbbbbbbbbbb" activating; before="$(zpd_current_release)"
( MOCK_MIGRATE_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "$before" ); assert_rc 30 "$?" "migration failure → 30"
assert_eq "$(zpd_current_release)" "$before" "symlink not switched on migration failure"
rm -rf "$BASE"
new_base; mk_release "20260102000000-bbbbbbbbbbbb" activating; ( MOCK_NGINX_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "" ); assert_rc 31 "$?" "nginx -t failure → 31"; rm -rf "$BASE"
new_base; mk_release "20260102000000-bbbbbbbbbbbb" activating; ( MOCK_SYSTEMCTL_RC=1 dep_activate "20260102000000-bbbbbbbbbbbb" "php8.3-fpm" "" ); assert_rc 31 "$?" "php-fpm reload failure → 31"; rm -rf "$BASE"

# Health check
new_base; ( MOCK_HTTP_CODE=500 dep_health "http://localhost" ); assert_rc 1 "$?" "health fails on non-200"; ( MOCK_HTTP_CODE=200 dep_health "http://localhost" ); assert_rc 0 "$?" "health passes on 200"; rm -rf "$BASE"

# Atomic symlink switch + relative target
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; mk_release "20260102000000-bbbbbbbbbbbb" success
assert_true zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(readlink "$(zpd_current_link)")" "releases/20260102000000-bbbbbbbbbbbb" "current is a relative symlink"
rm -rf "$BASE"

# Existing-install migration into shared (+ public/storage link)
new_base
OLD="$(mktemp -d)"; printf 'APP_KEY=base64:ORIGINALKEY0000000000000000000000000000000=\n' > "${OLD}/.env"
mkdir -p "${OLD}/storage/app/public"; echo upload > "${OLD}/storage/app/public/u.txt"
SH2="$(mktemp -d)"
assert_true zpd_init_shared_from_existing "$OLD" "$SH2"
assert_eq "$(grep APP_KEY "${SH2}/.env")" "$(grep APP_KEY "${OLD}/.env")" "APP_KEY preserved unchanged"
REL="$(mktemp -d)"; mkdir -p "${REL}/public"; : > "${REL}/.env"; rm -rf "${REL}/storage"
assert_true zpd_link_shared "$REL" "$SH2"
assert_true test -f "${REL}/storage/app/public/u.txt"
assert_true test -L "${REL}/public/storage"
rm -rf "$OLD" "$SH2" "$REL" "$BASE"

# Disk space + retention + manual rollback default target
new_base; assert_false zpd_check_disk_space "$BASE" 999999999; assert_true zpd_check_disk_space "$BASE" 0; rm -rf "$BASE"
new_base
for n in 1 2 3 4 5 6 7 8; do mk_release "2026010${n}000000-cccccccccccc" success; done
zpd_switch_current "20260108000000-cccccccccccc"
assert_eq "$(zpd_prunable_releases 3 | grep -c .)" "5" "keeps 3 newest (+active/previous), prunes 5"
rm -rf "$BASE"
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; mk_release "20260102000000-bbbbbbbbbbbb" success
zpd_switch_current "20260102000000-bbbbbbbbbbbb"
assert_eq "$(rb_default_target)" "20260101000000-aaaaaaaaaaaa" "default rollback target is previous healthy release"
rm -rf "$BASE"

# rb_default_target → legacy when no previous release but a legacy snapshot exists
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
mkdir -p "${BASE}/shared/deploy"
zpd_write_manifest "$(zpd_legacy_marker_file)" "legacy_base=${BASE}"
assert_eq "$(rb_default_target)" "legacy" "legacy rollback target when no previous release"
rm -rf "$BASE"

# Scheduler verify + manifest validity + secret masking
new_base
mk_release "20260101000000-aaaaaaaaaaaa" success; zpd_switch_current "20260101000000-aaaaaaaaaaaa"
( MOCK_SCHEDULE_RC=0 dep_verify_scheduler "$(zpd_current_link)" ); assert_rc 0 "$?" "scheduler verified"
( MOCK_SCHEDULE_RC=1 dep_verify_scheduler "$(zpd_current_link)" ); assert_rc 1 "$?" "scheduler check fails"
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
