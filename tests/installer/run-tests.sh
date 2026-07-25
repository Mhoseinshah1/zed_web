#!/usr/bin/env bash
# =============================================================================
# Shell tests for the ZedProxy installer safety helpers (scripts/lib/installer-lib.sh).
#
# These prove the decision logic that keeps a re-run of install.sh from
# destroying an existing installation. Run with:
#     bash tests/installer/run-tests.sh
#
# No root, no network, no services — everything runs in temp dirs.
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LIB="${REPO_ROOT}/scripts/lib/installer-lib.sh"

# shellcheck source=/dev/null
source "$LIB"

PASS=0
FAIL=0

ok()   { echo "  ok   - $*"; PASS=$((PASS + 1)); }
bad()  { echo "  FAIL - $*"; FAIL=$((FAIL + 1)); }

assert_true()  { if "$@"; then ok "$*"; else bad "$* (expected success)"; fi; }
assert_false() { if "$@"; then bad "$* (expected failure)"; else ok "! $*"; fi; }
assert_eq()    { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1', want '$2')"; fi; }

make_env() {
    # make_env DIR [APP_KEY]
    local dir="$1" key="${2-base64:$(head -c32 /dev/urandom | base64)}"
    mkdir -p "$dir"
    touch "$dir/artisan" "$dir/composer.json"
    cat > "$dir/.env" <<EOF
APP_NAME=ZedProxy
APP_KEY=${key}
APP_URL=https://panel.example.com
DB_DATABASE=zedproxy
DB_USERNAME=zedproxy_user
DB_PASSWORD="s3cr3t=p@ss/word+42"
CUSTOM_TELEGRAM_TOKEN=abc123
EOF
}

echo "== ZedProxy installer helper tests =="

# ── Scenario 1: fresh install (no .env) is NOT detected as existing ──
T1="$(mktemp -d)"
touch "$T1/artisan" "$T1/composer.json"   # cloned repo, never configured
assert_false zp_detect_existing_installation "$T1"
rm -rf "$T1"

# ── Scenario 2: re-run with a valid existing APP_KEY ──
T2="$(mktemp -d)"; make_env "$T2"
assert_true zp_detect_existing_installation "$T2"
assert_true zp_appkey_present_and_valid "$T2/.env"

# ── Scenario 3: custom .env values are read back verbatim (preserved) ──
assert_eq "$(zp_env_get "$T2/.env" DB_PASSWORD)" 's3cr3t=p@ss/word+42' "custom DB_PASSWORD preserved"
assert_eq "$(zp_env_get "$T2/.env" CUSTOM_TELEGRAM_TOKEN)" 'abc123' "custom variable preserved"
assert_eq "$(zp_env_get "$T2/.env" DB_DATABASE)" 'zedproxy' "DB_DATABASE read"
rm -rf "$T2"

# ── Scenario 7: empty APP_KEY is detected as needing (only) generation ──
T7="$(mktemp -d)"; make_env "$T7" ""
assert_false zp_appkey_present_and_valid "$T7/.env"
assert_eq "$(zp_env_get "$T7/.env" APP_KEY)" '' "empty APP_KEY read as empty"
rm -rf "$T7"

# ── APP_KEY shape validation ──
assert_true  zp_appkey_is_valid "base64:$(head -c32 /dev/urandom | base64)"   # AES-256
assert_true  zp_appkey_is_valid "base64:$(head -c16 /dev/urandom | base64)"   # AES-128
assert_false zp_appkey_is_valid ""
assert_false zp_appkey_is_valid "plaintext-not-base64"
assert_false zp_appkey_is_valid "base64:not-valid-base64-@@@"

# ── Scenario 5: .env backup succeeds (600) and fails safely on a bad source ──
T5="$(mktemp -d)"; make_env "$T5"
BK="$(zp_backup_env "$T5/.env" "$T5/backups" 20260723_120000)"
assert_eq "$BK" "$T5/backups/20260723_120000/.env" "backup written to timestamped path"
assert_eq "$(stat -c %a "$BK")" '600' "backup .env is mode 600"
assert_true  test -f "$BK"
assert_false zp_backup_env "$T5/does-not-exist.env" "$T5/backups" 20260723_120000   # missing source aborts
rm -rf "$T5"

# ── Scenario 11: local git changes are detected and backed up before reset ──
T11="$(mktemp -d)"
(
    cd "$T11"
    git init -q
    git config user.email t@t.t; git config user.name t
    echo "tracked" > tracked.txt
    printf ".env\nstorage-uploads/\n" > .gitignore
    git add tracked.txt .gitignore
    git commit -qm init
)
# Clean tree → no local changes.
assert_false zp_git_has_local_changes "$T11"
# Modify a tracked file + add an untracked file + an IGNORED runtime file.
echo "modified" >> "$T11/tracked.txt"
echo "new" > "$T11/untracked.txt"
mkdir -p "$T11/storage-uploads"; echo "user-upload" > "$T11/storage-uploads/photo.bin"
make_env_noop() { :; }
echo "APP_KEY=x" > "$T11/.env"   # ignored runtime file
assert_true zp_git_has_local_changes "$T11"
LC="$(zp_git_backup_local_changes "$T11" "$T11/bk" 20260723_120000)"
assert_true  test -f "$LC/tracked.txt"
assert_true  test -f "$LC/untracked.txt"
# Ignored runtime files (.env, uploads) are NOT reported/backed up by this path
# (git ignores them), proving reset/clean -fd would leave them untouched.
assert_false test -f "$LC/.env"
assert_false test -f "$LC/storage-uploads/photo.bin"
rm -rf "$T11"

# ── is_git_repo + mask_secret ──
T12="$(mktemp -d)"
assert_false zp_is_git_repo "$T12"
( cd "$T12" && git init -q )
assert_true  zp_is_git_repo "$T12"
assert_eq "$(zp_mask_secret 'supersecretvalue')" 'su****ue' "secret masked"
assert_eq "$(zp_mask_secret 'abc')" '****' "short secret fully masked"
rm -rf "$T12"

# ── Scheduler cron: fresh install writes exactly one entry ──
TC="$(mktemp -d)"
CRON="$TC/cron.d/zedproxy-scheduler"
mkdir -p "$TC/cron.d"
LINE="$(zp_scheduler_cron_line /var/www/zedproxy www-data php /var/log/zedproxy-scheduler.log)"
assert_eq "$LINE" '* * * * * www-data cd /var/www/zedproxy && php artisan schedule:run >> /var/log/zedproxy-scheduler.log 2>&1' "scheduler cron line matches required format"
zp_write_cron_file "$CRON" "$LINE"
assert_true test -f "$CRON"
assert_eq "$(stat -c %a "$CRON")" '644' "cron file is mode 644"
assert_eq "$(zp_count_lines_matching "$CRON" 'schedule:run')" '1' "fresh install: exactly one schedule:run entry"

# ── Installer re-run: duplicate cron prevention (file fully replaced) ──
zp_write_cron_file "$CRON" "$LINE"
zp_write_cron_file "$CRON" "$LINE"
assert_eq "$(zp_count_lines_matching "$CRON" 'schedule:run')" '1' "re-run: still exactly one schedule:run entry (no duplicates)"

# ── Default arguments produce the documented www-data/php line ──
assert_eq "$(zp_scheduler_cron_line /var/www/zedproxy)" '* * * * * www-data cd /var/www/zedproxy && php artisan schedule:run >> /var/log/zedproxy-scheduler.log 2>&1' "defaults match required entry"

# ── Legacy backup cron is removed (backups run from only one system) ──
LEGACY="$TC/cron.d/zedproxy-backup"
echo "0 3 * * * www-data bash /var/www/zedproxy/scripts/backup.sh" > "$LEGACY"
assert_true  test -f "$LEGACY"
assert_true  zp_remove_file "$LEGACY"
assert_false test -f "$LEGACY"
# Removing an absent file is still success (idempotent).
assert_true  zp_remove_file "$LEGACY"
rm -rf "$TC"

# =============================================================================
# Atomic-layout wiring in install.sh / update.sh (static assertions).
# These validate acceptance criteria that a fresh install uses the release
# layout and every service targets `current`, at the config-generation level
# (the same level CI validates the installer: bash -n + shellcheck).
# =============================================================================
INSTALL_SH="${REPO_ROOT}/install.sh"
UPDATE_SH="${REPO_ROOT}/update.sh"

grep_has()  { if grep -Eq "$1" "$2"; then ok "$3"; else bad "$3"; fi; }
grep_none() { if grep -Eq "$1" "$2"; then bad "$3"; else ok "$3"; fi; }

# 20. Fresh install builds the release/shared/current layout.
grep_has 'ZPD_RELEASES="\$\{ZPD_BASE\}/releases"' "$INSTALL_SH" "install.sh defines releases dir"
grep_has 'ZPD_SHARED="\$\{ZPD_BASE\}/shared"'      "$INSTALL_SH" "install.sh defines shared dir"
grep_has 'ZPD_CURRENT="\$\{ZPD_BASE\}/current"'    "$INSTALL_SH" "install.sh defines current link"
grep_has 'activate_initial_release'                "$INSTALL_SH" "install.sh activates an initial release"
grep_has 'prepare_shared_and_link'                 "$INSTALL_SH" "install.sh links release → shared"

# 21. Nginx root uses current/public (via ACTIVE_APP_DIR), never a bare APP_DIR.
grep_has  'root \$\{ACTIVE_APP_DIR\}/public;' "$INSTALL_SH" "nginx root uses ACTIVE_APP_DIR/public"
grep_none 'root \$\{APP_DIR\}/public;'        "$INSTALL_SH" "nginx root no longer hardcodes APP_DIR/public"

# 21b. robots.txt is served DYNAMICALLY by Laravel: the generated nginx block
#      must fall through to the front controller, and the old static-only
#      quiet-404 form must be gone (it made /robots.txt 404 in production).
grep_has  'location = /robots\.txt \{'                       "$INSTALL_SH" "nginx has an exact-match robots.txt location"
grep_has  'try_files \\\$uri /index\.php\?\\\$query_string;' "$INSTALL_SH" "robots.txt location falls through to Laravel (dynamic robots)"
grep_none 'location = /robots\.txt.*access_log.*\}'          "$INSTALL_SH" "no static-only single-line robots.txt block remains"

# 21c. Required default records (terms/privacy/about pages, login/register
#      noindex SEO records) are ensured on install AND on every deploy — the
#      targeted command only, never the full DatabaseSeeder.
grep_has  'zedproxy:seed-required-defaults' "$INSTALL_SH" "install.sh ensures required default records after migrations"
grep_none 'artisan db:seed'                 "$INSTALL_SH" "install.sh never runs the full DatabaseSeeder"

# 22. Supervisor worker uses current/artisan.
grep_has  'command=php \$\{ACTIVE_APP_DIR\}/artisan queue:work' "$INSTALL_SH" "supervisor worker uses ACTIVE_APP_DIR/artisan"
grep_none 'command=php \$\{APP_DIR\}/artisan queue:work'        "$INSTALL_SH" "supervisor worker no longer hardcodes APP_DIR/artisan"

# 23. Scheduler cron uses current/artisan (ACTIVE_APP_DIR).
grep_has 'zp_scheduler_cron_line "\$\{ACTIVE_APP_DIR\}"' "$INSTALL_SH" "scheduler cron uses ACTIVE_APP_DIR"

# 24. Shortcuts use the ONE shared command-wrapper installer (not inline
#     heredocs), and both install.sh AND deploy.sh install/refresh them.
WRAPPERS_SH="${REPO_ROOT}/scripts/deploy/install-command-wrappers.sh"
DEPLOY_SH="${REPO_ROOT}/scripts/deploy/deploy.sh"
grep_has  'zpw_install_wrappers'   "$INSTALL_SH" "install.sh installs wrappers via the shared installer"
grep_has  'zpw_install_wrappers'   "$DEPLOY_SH"  "deploy.sh (re)installs wrappers on activation (legacy servers get them)"
grep_has  'dep_seed_required_defaults' "$DEPLOY_SH" "deploy.sh ensures required default records before the symlink switch"
grep_none 'artisan db:seed'            "$DEPLOY_SH" "deploy.sh never runs the full DatabaseSeeder"
grep_has  'zpd_resolve_script'     "$WRAPPERS_SH" "shared installer emits the current-release resolver"
grep_none 'exec sudo bash "\$\{APP_DIR\}/scripts/deploy/deploy.sh"' "$INSTALL_SH" "no hardcoded legacy deploy.sh in install.sh shortcuts"
grep_has  'zpw_backup_wrappers'    "$DEPLOY_SH"  "deploy.sh backs up wrappers before a legacy cutover"
grep_has  'zpw_restore_wrappers'   "$DEPLOY_SH"  "first-cutover rollback restores the previous wrappers"

# deploy.env is created by the installer (non-secret persistent config).
grep_has 'zpd_write_deploy_env "\$DEPLOY_ENV_FILE"' "$INSTALL_SH" "installer writes /etc/zedproxy/deploy.env"

# ACTIVE_APP_DIR defaults to current/ (services target the active release).
grep_has 'ACTIVE_APP_DIR="\$\{ZPD_CURRENT\}"' "$INSTALL_SH" "ACTIVE_APP_DIR defaults to current/"

# 25. Standalone updater is pwd-independent + self-bootstraps with STRICT ref
#     handling (resolve → verify HEAD; no `|| true` on checkout).
grep_has  'mktemp -d'                 "$UPDATE_SH" "update.sh fetches into a temp bootstrap dir"
grep_has  'clone "\$repo"'            "$UPDATE_SH" "update.sh clones the resolved repository"
grep_has  "DEFAULT_REPO='https://github.com/Mhoseinshah1/zed_web.git'" "$UPDATE_SH" "update.sh has a safe public default repo"
grep_has  'RESOLVED_SHA'              "$UPDATE_SH" "update.sh resolves the exact updater SHA"
grep_has  'GOT_SHA'                   "$UPDATE_SH" "update.sh verifies fetched HEAD == resolved SHA"
grep_none 'checkout[^\n]*\|\| true'   "$UPDATE_SH" "update.sh checkout is NOT ignored (no || true)"
grep_none '\bcd \$\(pwd\)'            "$UPDATE_SH" "update.sh does not depend on \$(pwd)"

# ── No required service operation may fail open in the deploy paths (Defect 1) ──
# (25) supervisorctl reread/update and git checkout must never be `|| true`.
grep_none 'supervisorctl[^\n]*reread[^\n]*\|\| *true' "$DEPLOY_SH" "deploy.sh: supervisor reread not '|| true'"
grep_none 'supervisorctl[^\n]*update[^\n]*\|\| *true' "$DEPLOY_SH" "deploy.sh: supervisor update not '|| true'"
grep_none 'SUPERVISORCTL[^\n]*reread[^\n]*\|\| *true' "$DEPLOY_SH" "deploy.sh: \$ZPD_SUPERVISORCTL reread not '|| true' (in cutover)"
# The strict cutover functions and readiness gate exist.
grep_has  'dep_cutover_supervisor'    "$DEPLOY_SH" "deploy.sh has a strict supervisor cutover"
grep_has  'dep_cutover_scheduler'     "$DEPLOY_SH" "deploy.sh has a strict scheduler cutover"
grep_has  'dep_verify_internal_release' "$DEPLOY_SH" "deploy.sh has an internal (maintenance-safe) readiness phase"
grep_has  'dep_verify_http_release'   "$DEPLOY_SH" "deploy.sh has a public HTTP readiness phase"
grep_has  'dep_supervisor_group_running' "$DEPLOY_SH" "deploy.sh verifies the worker group is running"

# No production repository fallback anywhere may be "." (root cause #1).
grep_none 'clone[^\n]*"\$\{ZPD_REPO_URL:-\.\}"' "$DEPLOY_SH" "deploy.sh has no '.' repo fallback"

echo ""
echo "== results: ${PASS} passed, ${FAIL} failed =="
[ "$FAIL" -eq 0 ]
