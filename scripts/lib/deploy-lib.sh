#!/usr/bin/env bash
# =============================================================================
# ZedProxy atomic release-deployment helper library.
#
# Pure, side-effect-free helper functions shared by deploy.sh / rollback.sh /
# deploy-status.sh and covered by tests/deploy/run-tests.sh. Sourcing this file
# MUST NOT run any deployment step, print anything, or mutate global state beyond
# defining functions and the load sentinel — so it is safe to source from both
# the deployment scripts and the test runner.
#
# All function names are prefixed with `zpd_` (ZedProxy Deploy).
#
# Filesystem layout (paths overridable via env for tests/back-compat):
#   $ZPD_BASE/                 (default /var/www/zedproxy)
#     current -> releases/<release-id>   (atomic symlink; Nginx root)
#     releases/<release-id>/...
#     shared/.env
#     shared/storage/
#     shared/persistent/
#   Lock:   $ZPD_LOCK_FILE     (default /var/run/zedproxy-deploy.lock — NOT in a release)
#   Logs:   $ZPD_LOG_DIR       (default /var/log/zedproxy — outside releases)
#   State:  $ZPD_STATE_FILE    (default $ZPD_BASE/shared/deploy/state.json)
#   Backups:$ZPD_BACKUP_DIR    (default /var/backups/zedproxy/deploys)
# =============================================================================

if [ -n "${ZPD_DEPLOY_LIB_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
ZPD_DEPLOY_LIB_LOADED=1

# ── Path resolution ─────────────────────────────────────────────────────────

zpd_base()        { printf '%s' "${ZPD_BASE:-/var/www/zedproxy}"; }
zpd_releases_dir(){ printf '%s/releases' "$(zpd_base)"; }
zpd_shared_dir()  { printf '%s/shared' "$(zpd_base)"; }
zpd_current_link(){ printf '%s/current' "$(zpd_base)"; }
zpd_lock_file()   { printf '%s' "${ZPD_LOCK_FILE:-/var/run/zedproxy-deploy.lock}"; }
zpd_log_dir()     { printf '%s' "${ZPD_LOG_DIR:-/var/log/zedproxy}"; }
zpd_state_file()  { printf '%s' "${ZPD_STATE_FILE:-$(zpd_shared_dir)/deploy/state.json}"; }
zpd_backup_dir()  { printf '%s' "${ZPD_BACKUP_DIR:-/var/backups/zedproxy/deploys}"; }

# -----------------------------------------------------------------------------
# zpd_release_id SHA [EPOCH]
#
# Immutable release identifier: YYYYMMDDHHMMSS-<first-12-of-sha>.
#
# The SHA of the commit that will actually be deployed MUST be supplied by the
# caller — it is resolved from the remote (zpd_resolve_sha) BEFORE the release is
# named, so a production release can never be named from the caller's current
# working directory (root cause of the "-nogit" bug). A release ending in
# "nogit" is only produced under the explicit test fixture ZPD_ALLOW_NOGIT=1.
# Returns 1 (no output) when no SHA is available and nogit is not permitted.
# -----------------------------------------------------------------------------
zpd_release_id() {
    local sha="${1:-}" epoch="${2:-}"
    # Keep only the first 12 hex chars.
    sha="$(printf '%s' "$sha" | tr -cd '0-9a-fA-F' | cut -c1-12)"
    if [ -z "$sha" ]; then
        if [ "${ZPD_ALLOW_NOGIT:-0}" = "1" ]; then
            sha="nogit"
        else
            return 1
        fi
    fi
    local ts
    if [ -n "$epoch" ]; then
        ts="$(date -u -d "@${epoch}" +%Y%m%d%H%M%S 2>/dev/null || date -u +%Y%m%d%H%M%S)"
    else
        ts="$(date -u +%Y%m%d%H%M%S)"
    fi
    printf '%s-%s' "$ts" "$sha"
}

# -----------------------------------------------------------------------------
# zpd_valid_release_id ID — return 0 for a well-formed release id.
# -----------------------------------------------------------------------------
zpd_valid_release_id() {
    printf '%s' "${1:-}" | grep -Eq '^[0-9]{14}-[0-9a-fA-F]{1,12}$'
}

# ── Secret masking ──────────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_mask_secrets — filter stdin→stdout, redacting credentials/secrets so logs
# and manifests never contain them.
# -----------------------------------------------------------------------------
zpd_mask_secrets() {
    sed -E \
        -e 's/(PGPASSWORD)=[^[:space:]]*/\1=***/g' \
        -e 's/(APP_KEY|DB_PASSWORD|REDIS_PASSWORD|MAIL_PASSWORD)=[^[:space:]]*/\1=***/gI' \
        -e 's/((password|passwd|pass|secret|token|api[_-]?key|auth)[[:space:]]*[=:][[:space:]]*)[^[:space:]"'"'"']+/\1***/gI' \
        -e 's#(://)[^/@[:space:]:]+:[^/@[:space:]]+@#\1***:***@#g'
}

# -----------------------------------------------------------------------------
# zpd_redact_file FILE — print FILE through the secret-redaction layer so a
# captured git error (which may embed an authenticated URL) never leaks a
# credential. Missing/empty file prints nothing.
# -----------------------------------------------------------------------------
zpd_redact_file() {
    local f="${1:-}"
    [ -f "$f" ] || return 0
    zpd_mask_secrets < "$f"
}

# ── Persistent deploy configuration (/etc/zedproxy/deploy.env) ───────────────
#
# Non-secret deployment configuration shared by update/rollback/deploy-status.
# It MUST NEVER contain passwords, tokens, APP_KEY, DB credentials, or an
# authenticated repository URL.

zpd_deploy_env_file()  { printf '%s' "${ZPD_DEPLOY_ENV:-/etc/zedproxy/deploy.env}"; }
zpd_default_repo_url() { printf '%s' 'https://github.com/Mhoseinshah1/zed_web.git'; }
zpd_default_ref()      { printf '%s' "${ZPD_DEFAULT_REF:-main}"; }

# Keys that may appear in deploy.env. Anything else in the file is ignored.
_zpd_deploy_env_keys() {
    printf '%s\n' ZPD_BASE ZPD_REPO_URL ZPD_REF ZPD_HEALTH_URL ZPD_KEEP_RELEASES ZPD_MIN_DISK_MB
}

# -----------------------------------------------------------------------------
# zpd_load_deploy_env — populate deployment configuration from deploy.env, but
# only for whitelisted keys that are NOT already set in the environment, so an
# explicit environment variable always overrides the file. Safe to call from
# every entrypoint before any default assignment. Ignores unknown/secret-looking
# keys entirely.
# -----------------------------------------------------------------------------
zpd_load_deploy_env() {
    local f; f="$(zpd_deploy_env_file)"
    [ -f "$f" ] || return 0
    local line key val allowed
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in ''|\#*) continue ;; esac
        case "$line" in *=*) ;; *) continue ;; esac
        key="${line%%=*}"; val="${line#*=}"
        key="$(printf '%s' "$key" | tr -d '[:space:]')"
        # Strip one layer of surrounding quotes and trailing CR.
        val="${val%$'\r'}"
        val="${val#\"}"; val="${val%\"}"
        val="${val#\'}"; val="${val%\'}"
        allowed=0
        case "$key" in
            ZPD_BASE|ZPD_REPO_URL|ZPD_REF|ZPD_HEALTH_URL|ZPD_KEEP_RELEASES|ZPD_MIN_DISK_MB) allowed=1 ;;
        esac
        [ "$allowed" = "1" ] || continue
        # Explicit environment wins over the file.
        if [ -z "${!key:-}" ]; then
            export "$key=$val"
        fi
    done < "$f"
}

# -----------------------------------------------------------------------------
# zpd_write_deploy_env FILE key=value...
#
# Atomically write a root-only (600) non-secret deploy.env. Any key that looks
# like a secret is refused (never written). Existing custom values are the
# caller's responsibility to pass through (the installer merges before calling).
# -----------------------------------------------------------------------------
zpd_write_deploy_env() {
    local file="$1"; shift
    [ -n "$file" ] || return 1
    mkdir -p "$(dirname "$file")" 2>/dev/null || return 1
    local tmp; tmp="$(cd "$(dirname "$file")" && mktemp ".zpdenv.XXXXXX" 2>/dev/null)" || return 1
    tmp="$(dirname "$file")/$tmp"
    {
        printf '# ZedProxy deployment configuration (non-secret).\n'
        printf '# Managed by the installer; safe to edit. NEVER put passwords/tokens/APP_KEY here.\n'
        local pair key val
        for pair in "$@"; do
            key="${pair%%=*}"; val="${pair#*=}"
            case "$key" in
                *PASSWORD*|*PASSWD*|*SECRET*|*TOKEN*|*APP_KEY*|*APIKEY*|*API_KEY*) continue ;;
            esac
            printf '%s=%s\n' "$key" "$val"
        done
    } > "$tmp" || { rm -f "$tmp"; return 1; }
    chmod 600 "$tmp" 2>/dev/null || true
    chown 0:0 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$file" 2>/dev/null || { rm -f "$tmp"; return 1; }
}

# ── Repository source resolution ─────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_is_safe_repo_url URL
#
# Return 0 only for a repository source safe to use as a production fallback:
#   - non-empty
#   - NOT "." and NOT a relative filesystem path (the "clone the caller's CWD"
#     bug must be impossible)
#   - a remote URL (scheme://… or scp-style user@host:path) is always allowed
#   - an ABSOLUTE local path is allowed ONLY under the explicit test/dev flag
#     ZPD_ALLOW_LOCAL_REPO=1
# -----------------------------------------------------------------------------
zpd_is_safe_repo_url() {
    local url="${1:-}"
    [ -n "$url" ] || return 1
    case "$url" in
        .|./*|../*|..) return 1 ;;
    esac
    case "$url" in
        *://*)  return 0 ;;   # https://, git://, ssh://, file:// …
        *@*:*)  return 0 ;;   # scp-style git@github.com:owner/repo.git
    esac
    case "$url" in
        /*) [ "${ZPD_ALLOW_LOCAL_REPO:-0}" = "1" ] && return 0 || return 1 ;;
        *)  return 1 ;;       # bare/relative name — never an implicit fallback
    esac
}

# zpd_git_origin_of DIR — print the `origin` remote URL of a git work tree.
zpd_git_origin_of() {
    local dir="${1:-}"
    [ -n "$dir" ] || return 1
    [ -e "$dir/.git" ] || return 1
    "${ZPD_GIT:-git}" -C "$dir" remote get-url origin 2>/dev/null
}

# -----------------------------------------------------------------------------
# zpd_resolve_repo_url
#
# Resolve the repository source by precedence:
#   1. explicit $ZPD_REPO_URL   (also carries a deploy.env value, since
#      zpd_load_deploy_env exports it into the environment when unset)
#   2. git origin of the active release (current/)
#   3. git origin of a detected legacy install ($ZPD_BASE)
#   4. built-in public default (https://github.com/Mhoseinshah1/zed_web.git)
# The resolved URL must pass zpd_is_safe_repo_url; otherwise returns 1 so the
# caller aborts (never silently clones ".").
# -----------------------------------------------------------------------------
zpd_resolve_repo_url() {
    local url=""
    if [ -n "${ZPD_REPO_URL:-}" ]; then
        url="$ZPD_REPO_URL"
    else
        url="$(zpd_git_origin_of "$(zpd_current_link)" 2>/dev/null || true)"
        [ -n "$url" ] || url="$(zpd_git_origin_of "$(zpd_base)" 2>/dev/null || true)"
        [ -n "$url" ] || url="$(zpd_default_repo_url)"
    fi
    zpd_is_safe_repo_url "$url" || return 1
    printf '%s' "$url"
}

# ── Ref → exact SHA resolution ───────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_resolve_sha REPO REF [ERRFILE]
#
# Resolve REF (branch / lightweight tag / annotated tag / full 40-hex SHA) to
# the exact full commit SHA using a bounded `git ls-remote`. Annotated tags are
# peeled to their commit (the ^{} line wins). A full-SHA REF with no matching
# remote ref is accepted as-is (direct commit deploy). git output goes to
# ERRFILE for redacted display. Prints the SHA; returns 1 if REF cannot be
# resolved (so the migration never starts against an unknown ref).
# -----------------------------------------------------------------------------
zpd_resolve_sha() {
    local repo="$1" ref="$2" errfile="${3:-/dev/null}"
    [ -n "$repo" ] && [ -n "$ref" ] || return 1
    local out rc
    out="$("${ZPD_GIT:-git}" ls-remote "$repo" "$ref" "${ref}^{}" 2>"$errfile")"; rc=$?
    if [ "$rc" -ne 0 ]; then
        # Remote unreachable/denied. Only a full SHA can still be deployed.
        printf '%s' "$ref" | grep -Eq '^[0-9a-f]{40}$' && { printf '%s' "$ref"; return 0; }
        return 1
    fi
    local sha
    sha="$(printf '%s\n' "$out" | awk '/\^\{\}$/ {print $1; exit}')"
    [ -n "$sha" ] || sha="$(printf '%s\n' "$out" | awk 'NF {print $1; exit}')"
    if [ -z "$sha" ]; then
        # No ref matched. Accept a full SHA directly; otherwise fail.
        printf '%s' "$ref" | grep -Eq '^[0-9a-f]{40}$' && { printf '%s' "$ref"; return 0; }
        return 1
    fi
    printf '%s' "$sha"
}

# ── Clone the exact ref ──────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_git_clone_ref REPO REF DEST [ERRFILE]
#
# Clone REPO into DEST and check out the EXACT REF (branch/tag/SHA). A full clone
# (not --depth 1) is used so any branch, tag, or commit can be checked out. All
# git output is appended to ERRFILE for redacted reporting — never sent to
# /dev/null. On any failure DEST is removed so no partial/`.nogit` release is
# left behind. Returns 0 with DEST checked out at REF.
# -----------------------------------------------------------------------------
zpd_git_clone_ref() {
    local repo="$1" ref="$2" dest="$3" err="${4:-/dev/null}"
    local git="${ZPD_GIT:-git}"
    [ -n "$repo" ] && [ -n "$ref" ] && [ -n "$dest" ] || return 1
    rm -rf "$dest" 2>/dev/null || true
    if ! "$git" clone "$repo" "$dest" >>"$err" 2>&1; then
        rm -rf "$dest" 2>/dev/null || true
        return 1
    fi
    # Ensure tags are present (annotated + lightweight) for tag refs.
    "$git" -C "$dest" fetch --tags --force --quiet origin >>"$err" 2>&1 || true
    if ! "$git" -C "$dest" checkout --quiet --detach "$ref" >>"$err" 2>&1; then
        rm -rf "$dest" 2>/dev/null || true
        return 1
    fi
    return 0
}

# zpd_git_head_sha DIR — full 40-hex HEAD SHA of a checked-out release.
zpd_git_head_sha() {
    "${ZPD_GIT:-git}" -C "${1:?}" rev-parse HEAD 2>/dev/null
}

# ── Legacy (pre-atomic) installation detection ───────────────────────────────

# -----------------------------------------------------------------------------
# zpd_is_atomic_layout — return 0 when $ZPD_BASE already uses the release layout
# (a `current` symlink exists). Used to distinguish a normal update from a
# first-time legacy migration.
# -----------------------------------------------------------------------------
zpd_is_atomic_layout() {
    [ -L "$(zpd_current_link)" ]
}

# -----------------------------------------------------------------------------
# zpd_is_legacy_layout — return 0 when $ZPD_BASE looks like a legacy single-dir
# Laravel app (artisan + .env present at the base, no `current` symlink).
# -----------------------------------------------------------------------------
zpd_is_legacy_layout() {
    local base; base="$(zpd_base)"
    ! zpd_is_atomic_layout || return 1
    [ -f "${base}/artisan" ] && [ -f "${base}/.env" ]
}

# ── Nginx / Supervisor / Scheduler cutover to current/ ───────────────────────
#
# These rewrite operational config to serve the active release through
# `<base>/current`. They are pure text transforms on a given file (injectable
# for tests) — the caller runs `nginx -t`, backs up, and reloads.

# zpd_nginx_conf_path — the ZedProxy Nginx site config (overridable in tests).
zpd_nginx_conf_path()      { printf '%s' "${ZPD_NGINX_CONF:-/etc/nginx/sites-available/zedproxy}"; }
zpd_supervisor_conf_path() { printf '%s' "${ZPD_SUPERVISOR_CONF:-/etc/supervisor/conf.d/zedproxy-worker.conf}"; }
zpd_scheduler_cron_path()  { printf '%s' "${ZPD_SCHED_CRON:-/etc/cron.d/zedproxy-scheduler}"; }

# -----------------------------------------------------------------------------
# zpd_nginx_rewrite_root CONF BASE
#
# Rewrite every `root <BASE>...public;` directive (legacy direct path OR a
# release path) to `root <BASE>/current/public;`, in both the HTTP and HTTPS
# server blocks, leaving SSL and all other directives untouched. Idempotent.
# The caller is responsible for backup + `nginx -t` + reload/restore.
# -----------------------------------------------------------------------------
zpd_nginx_rewrite_root() {
    local conf="$1" base="$2"
    [ -f "$conf" ] || return 1
    [ -n "$base" ] || return 1
    # `#` delimiter: BASE is a filesystem path with no `#`.
    sed -i "s#root[[:space:]]\{1,\}${base}[^;]*;#root ${base}/current/public;#g" "$conf"
}

# zpd_nginx_root_ok CONF BASE — return 0 iff every zedproxy root goes through
# current/ (no legacy direct-root directive remains).
zpd_nginx_root_ok() {
    local conf="$1" base="$2"
    [ -f "$conf" ] || return 1
    grep -Eq "root[[:space:]]+${base}/current/public;" "$conf" || return 1
    # Fail if any zedproxy root does NOT go through current/.
    if grep -E "root[[:space:]]+${base}[^;]*;" "$conf" | grep -vq "/current/public;"; then
        return 1
    fi
    return 0
}

# -----------------------------------------------------------------------------
# zpd_supervisor_rewrite CONF BASE
#
# Point the worker at `<BASE>/current/artisan` and its log at shared storage,
# preserving all other supervisor directives. Idempotent.
# -----------------------------------------------------------------------------
zpd_supervisor_rewrite() {
    local conf="$1" base="$2"
    [ -f "$conf" ] || return 1
    [ -n "$base" ] || return 1
    sed -i "s#\(command=[^ ]* \)[^ ]*/artisan #\1${base}/current/artisan #g" "$conf"
    sed -i "s#stdout_logfile=.*#stdout_logfile=${base}/shared/storage/logs/worker.log#g" "$conf"
}

# zpd_supervisor_ok CONF BASE — worker command runs current/artisan AND the
# legacy direct path is absent (fail-closed: a config still referencing
# <base>/artisan is rejected even if a current/ line also exists).
zpd_supervisor_ok() {
    local conf="$1" base="$2"
    [ -f "$conf" ] || return 1
    grep -Eq "command=[^ ]* ${base}/current/artisan " "$conf" || return 1
    # Reject any command line still pointing at the LEGACY <base>/artisan path.
    if grep -E "command=" "$conf" | grep -Eq "[= ]${base}/artisan[ /]"; then
        return 1
    fi
    return 0
}

# zpd_supervisor_worker_group — the Supervisor program group name.
zpd_supervisor_worker_group() { printf '%s' "${ZPD_WORKER_GROUP:-zedproxy-worker}"; }

# -----------------------------------------------------------------------------
# zpd_supervisor_conf_content BASE — the complete worker program config pointing
# at <BASE>/current/artisan with logs in shared storage. Used to (re)create the
# config explicitly when it is missing during a cutover.
# -----------------------------------------------------------------------------
zpd_supervisor_conf_content() {
    local base="$1"
    cat <<CONF
[program:$(zpd_supervisor_worker_group)]
process_name=%(program_name)s_%(process_num)02d
command=php ${base}/current/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=${base}/shared/storage/logs/worker.log
stopwaitsecs=3600
CONF
}

# ── Scheduler cron (atomic, single-entry, current/-based) ────────────────────

# zpd_scheduler_cron_content BASE — the COMPLETE expected cron file: exactly one
# every-minute schedule:run executing <BASE>/current/artisan as www-data.
zpd_scheduler_cron_content() {
    local base="$1" log="${ZPD_SCHED_LOG:-/var/log/zedproxy-scheduler.log}"
    printf '* * * * * www-data cd %s/current && php %s/current/artisan schedule:run >> %s 2>&1\n' \
        "$base" "$base" "$log"
}

# -----------------------------------------------------------------------------
# zpd_scheduler_cron_ok CRON BASE — validate the scheduler cron fail-closed:
#   - file exists and is non-empty
#   - EXACTLY one `artisan schedule:run` line (reject duplicates)
#   - it runs <BASE>/current/artisan schedule:run
#   - no line references the LEGACY <BASE>/artisan path
# Returns 0 only when all hold.
# -----------------------------------------------------------------------------
zpd_scheduler_cron_ok() {
    local cron="$1" base="$2" n
    [ -f "$cron" ] && [ -s "$cron" ] || return 1
    n="$(grep -c 'artisan schedule:run' "$cron" 2>/dev/null || echo 0)"
    [ "$n" = "1" ] || return 1
    grep -q "php ${base}/current/artisan schedule:run" "$cron" || return 1
    grep -Eq "php ${base}/artisan schedule:run" "$cron" && return 1
    return 0
}

# ── First-cutover (legacy → first release) rollback bookkeeping ──────────────
#
# On the very first legacy→release migration there is no previous release id.
# The legacy application itself is the rollback target. We record the legacy
# operational paths so a failed first activation can restore them exactly.

zpd_legacy_marker_file() { printf '%s/shared/deploy/legacy-rollback.json' "$(zpd_base)"; }

# zpd_save_legacy_rollback BASE NGINX_CONF SUPERVISOR_CONF SCHED_CRON — snapshot
# the pre-cutover config files so first-cutover rollback can restore them.
zpd_save_legacy_rollback() {
    local base="$1" nginx="$2" super="$3" cron="$4"
    local dir; dir="$(dirname "$(zpd_legacy_marker_file)")"
    mkdir -p "$dir" 2>/dev/null || return 1
    [ -f "$nginx" ] && cp -a "$nginx" "${dir}/nginx.legacy" 2>/dev/null || true
    [ -f "$super" ] && cp -a "$super" "${dir}/supervisor.legacy" 2>/dev/null || true
    [ -f "$cron" ]  && cp -a "$cron"  "${dir}/scheduler.legacy" 2>/dev/null || true
    zpd_write_manifest "$(zpd_legacy_marker_file)" \
        "legacy_base=${base}" "nginx_conf=${nginx}" "supervisor_conf=${super}" \
        "scheduler_cron=${cron}" "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

# zpd_has_legacy_rollback — return 0 if a saved legacy snapshot exists.
zpd_has_legacy_rollback() { [ -f "$(zpd_legacy_marker_file)" ]; }

# zpd_restore_legacy_rollback — restore the snapshotted nginx/supervisor/cron
# files (used when the first cutover fails). Returns 0 on success.
zpd_restore_legacy_rollback() {
    local marker; marker="$(zpd_legacy_marker_file)"
    [ -f "$marker" ] || return 1
    local dir nginx super cron
    dir="$(dirname "$marker")"
    nginx="$(zpd_manifest_get "$marker" nginx_conf)"
    super="$(zpd_manifest_get "$marker" supervisor_conf)"
    cron="$(zpd_manifest_get "$marker" scheduler_cron)"
    [ -f "${dir}/nginx.legacy" ] && [ -n "$nginx" ] && cp -a "${dir}/nginx.legacy" "$nginx" 2>/dev/null || true
    [ -f "${dir}/supervisor.legacy" ] && [ -n "$super" ] && cp -a "${dir}/supervisor.legacy" "$super" 2>/dev/null || true
    [ -f "${dir}/scheduler.legacy" ] && [ -n "$cron" ] && cp -a "${dir}/scheduler.legacy" "$cron" 2>/dev/null || true
    return 0
}

# ── JSON manifest / state (no secrets) ──────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_json_escape STRING — minimal JSON string escaping.
# -----------------------------------------------------------------------------
zpd_json_escape() {
    local s="${1:-}"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\t'/\\t}"
    printf '%s' "$s"
}

# -----------------------------------------------------------------------------
# zpd_write_manifest FILE key=value...
#
# Write a flat JSON manifest to FILE (atomically, mode 600). Values are masked
# and JSON-escaped so no secret can leak into the manifest. Creates parent dirs.
# -----------------------------------------------------------------------------
zpd_write_manifest() {
    local file="$1"; shift
    [ -n "$file" ] || return 1
    mkdir -p "$(dirname "$file")" 2>/dev/null || return 1

    local tmp; tmp="$(mktemp)" || return 1
    {
        printf '{\n'
        local first=1 pair key val
        for pair in "$@"; do
            key="${pair%%=*}"
            val="${pair#*=}"
            val="$(printf '%s' "$val" | zpd_mask_secrets)"
            [ "$first" -eq 1 ] || printf ',\n'
            first=0
            printf '  "%s": "%s"' "$(zpd_json_escape "$key")" "$(zpd_json_escape "$val")"
        done
        printf '\n}\n'
    } > "$tmp" || { rm -f "$tmp"; return 1; }

    chmod 600 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$file" 2>/dev/null || { rm -f "$tmp"; return 1; }
}

# -----------------------------------------------------------------------------
# zpd_manifest_get FILE KEY — read a value from a flat JSON manifest. Prints the
# value (empty if absent). Returns 1 if FILE is missing.
# -----------------------------------------------------------------------------
zpd_manifest_get() {
    local file="$1" key="$2"
    [ -f "$file" ] || return 1
    # Match:  "key": "value"
    sed -nE "s/^[[:space:]]*\"$(printf '%s' "$key" | sed 's/[][\.*^$/]/\\&/g')\"[[:space:]]*:[[:space:]]*\"(.*)\"[[:space:]]*,?[[:space:]]*$/\1/p" "$file" | head -n1
}

# -----------------------------------------------------------------------------
# zpd_manifest_valid FILE — return 0 if FILE looks like a valid manifest that
# carries a non-empty release_id. Used to reject corrupt/partial manifests.
# -----------------------------------------------------------------------------
zpd_manifest_valid() {
    local file="$1"
    [ -f "$file" ] || return 1
    grep -q '^{' "$file" 2>/dev/null || return 1
    grep -q '}[[:space:]]*$' "$file" 2>/dev/null || return 1
    [ -n "$(zpd_manifest_get "$file" release_id)" ] || return 1
    return 0
}

# ── Release listing / current / previous ────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_current_release — basename of the release the `current` symlink points to
# (empty if none). Resolves one level only (the immediate target).
# -----------------------------------------------------------------------------
zpd_current_release() {
    local link; link="$(zpd_current_link)"
    [ -L "$link" ] || return 0
    local target; target="$(readlink "$link" 2>/dev/null)" || return 0
    basename "$target"
}

# -----------------------------------------------------------------------------
# zpd_list_releases — release ids newest-first (name sort; ids are time-prefixed).
# -----------------------------------------------------------------------------
zpd_list_releases() {
    local dir; dir="$(zpd_releases_dir)"
    [ -d "$dir" ] || return 0
    find "$dir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
        | grep -E '^[0-9]{14}-' | sort -r
}

# -----------------------------------------------------------------------------
# zpd_previous_release — the release immediately before `current` in the
# newest-first list (empty if none / current is oldest).
# -----------------------------------------------------------------------------
zpd_previous_release() {
    local current; current="$(zpd_current_release)"
    local found_current=0 rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        if [ "$found_current" -eq 1 ]; then
            printf '%s' "$rel"
            return 0
        fi
        [ "$rel" = "$current" ] && found_current=1
    done < <(zpd_list_releases)
    return 0
}

# ── Atomic symlink switch ───────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_switch_current RELEASE_ID
#
# Atomically point `current` at releases/RELEASE_ID via a temp symlink + rename
# (rename(2) is atomic), so a reader never sees a missing/half link. The release
# directory must exist. Returns 1 on any failure.
# -----------------------------------------------------------------------------
zpd_switch_current() {
    local id="$1"
    local base rel_dir link tmp
    base="$(zpd_base)"
    rel_dir="$(zpd_releases_dir)/${id}"
    link="$(zpd_current_link)"
    [ -d "$rel_dir" ] || return 1
    tmp="${link}.tmp.$$"

    # Relative target keeps the tree relocatable.
    ln -s "releases/${id}" "$tmp" 2>/dev/null || return 1
    # mv -T renames the symlink itself atomically over the existing `current`.
    if mv -Tf "$tmp" "$link" 2>/dev/null; then
        return 0
    fi
    rm -f "$tmp" 2>/dev/null || true
    return 1
}

# ── Disk space ──────────────────────────────────────────────────────────────

# zpd_disk_free_mb PATH — available MB on the filesystem holding PATH (0 on error).
zpd_disk_free_mb() {
    local path="${1:-/}"
    [ -e "$path" ] || path="$(dirname "$path")"
    df -Pm "$path" 2>/dev/null | awk 'NR==2 {print $4+0}' || printf '0'
}

# zpd_check_disk_space PATH MIN_MB — return 0 iff at least MIN_MB is free.
zpd_check_disk_space() {
    local path="$1" min_mb="$2" free
    free="$(zpd_disk_free_mb "$path")"
    [ "${free:-0}" -ge "${min_mb:-0}" ]
}

# ── Release retention ───────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_prunable_releases KEEP
#
# Print release ids that may be deleted: keep the newest KEEP releases, and
# NEVER the active or immediately-previous release. Input is the newest-first
# list; everything past position KEEP (and not active/previous) is prunable.
# -----------------------------------------------------------------------------
zpd_prunable_releases() {
    local keep="${1:-5}"
    local current previous
    current="$(zpd_current_release)"
    previous="$(zpd_previous_release)"

    local i=0 rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        i=$((i + 1))
        [ "$i" -le "$keep" ] && continue
        [ "$rel" = "$current" ] && continue
        [ "$rel" = "$previous" ] && continue
        printf '%s\n' "$rel"
    done < <(zpd_list_releases)
}

# ── Migration classification ────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_migration_is_destructive FILE
#
# Return 0 if a Laravel migration file contains potentially destructive schema
# operations (dropColumn/dropTable/drop/delete/truncate/dropIfExists). A
# backward-compatible migration (add columns/indexes only) returns 1.
# -----------------------------------------------------------------------------
zpd_migration_is_destructive() {
    local file="$1"
    [ -f "$file" ] || return 1
    grep -Eiq 'dropColumn|dropColumns|drop\(|dropIfExists|dropTable|->delete\(|truncate|renameColumn' "$file"
}

# -----------------------------------------------------------------------------
# zpd_migration_preflight DIR [DIR2...]
#
# Print a report line per *.php migration file: "<name> SAFE|DESTRUCTIVE".
# Returns 0 if all safe, 2 if any destructive (so a caller can require approval).
# -----------------------------------------------------------------------------
zpd_migration_preflight() {
    local rc=0 f name
    for dir in "$@"; do
        [ -d "$dir" ] || continue
        while IFS= read -r f; do
            name="$(basename "$f")"
            if zpd_migration_is_destructive "$f"; then
                printf '%s DESTRUCTIVE\n' "$name"
                rc=2
            else
                printf '%s SAFE\n' "$name"
            fi
        done < <(find "$dir" -maxdepth 1 -name '*.php' 2>/dev/null | sort)
    done
    return $rc
}

# ── Shared-storage compatibility migration ──────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_init_shared_from_existing OLD_DIR SHARED_DIR
#
# Migrate an existing single-directory install into shared storage WITHOUT
# destroying data:
#   - move OLD_DIR/.env → SHARED_DIR/.env (only if not already in shared)
#   - move OLD_DIR/storage → SHARED_DIR/storage (preserving storage/app/public)
# Existing files are moved (not deleted); if a shared copy already exists the old
# one is left untouched. Returns 0 on success.
# -----------------------------------------------------------------------------
zpd_init_shared_from_existing() {
    local old="$1" shared="$2"
    [ -n "$old" ] && [ -n "$shared" ] || return 1
    mkdir -p "$shared/persistent" 2>/dev/null || return 1

    if [ -f "$old/.env" ] && [ ! -e "$shared/.env" ]; then
        cp -a "$old/.env" "$shared/.env" 2>/dev/null || return 1
        chmod 600 "$shared/.env" 2>/dev/null || true
    fi

    if [ -d "$old/storage" ] && [ ! -e "$shared/storage" ]; then
        cp -a "$old/storage" "$shared/storage" 2>/dev/null || return 1
    fi

    return 0
}

# -----------------------------------------------------------------------------
# zpd_link_shared RELEASE_DIR SHARED_DIR
#
# Wire a release to shared state: replace the release's .env and storage with
# symlinks into shared, so every release sees the same .env/uploads and the
# encryption key never changes. Returns 0 on success.
# -----------------------------------------------------------------------------
zpd_link_shared() {
    local rel="$1" shared="$2"
    [ -d "$rel" ] && [ -d "$shared" ] || return 1

    rm -rf "$rel/.env" 2>/dev/null || true
    ln -s "$shared/.env" "$rel/.env" 2>/dev/null || return 1

    if [ -d "$shared/storage" ]; then
        rm -rf "$rel/storage" 2>/dev/null || true
        ln -s "$shared/storage" "$rel/storage" 2>/dev/null || return 1
    fi

    # public/storage must resolve to the shared public disk so uploads survive
    # every release/rollback (equivalent to `php artisan storage:link`).
    if [ -d "$rel/public" ] || mkdir -p "$rel/public" 2>/dev/null; then
        mkdir -p "$shared/storage/app/public" 2>/dev/null || true
        rm -rf "$rel/public/storage" 2>/dev/null || true
        ln -s "$shared/storage/app/public" "$rel/public/storage" 2>/dev/null || return 1
    fi
    return 0
}

# ── Locking ─────────────────────────────────────────────────────────────────

# -----------------------------------------------------------------------------
# zpd_run_locked LOCKFILE -- CMD...
#
# Run CMD holding an exclusive, non-blocking flock on LOCKFILE. If the lock is
# already held, return 200 immediately (a second deployment must fail fast). The
# lock is released automatically when the subshell exits (success/failure/signal).
# -----------------------------------------------------------------------------
zpd_run_locked() {
    local lock="$1"; shift
    [ "${1:-}" = "--" ] && shift
    mkdir -p "$(dirname "$lock")" 2>/dev/null || true

    (
        # Open the lock file on fd 9 for this subshell only.
        exec 9>"$lock" || exit 201
        if ! flock -n 9; then
            exit 200
        fi
        "$@"
    )
}

# -----------------------------------------------------------------------------
# zpd_lock_busy_message — the Persian message shown when another deploy runs.
# -----------------------------------------------------------------------------
zpd_lock_busy_message() {
    printf 'یک عملیات به‌روزرسانی دیگر در حال اجرا است.'
}

# ── User-facing Persian result messages ─────────────────────────────────────

zpd_msg_success()          { printf 'به‌روزرسانی با موفقیت انجام شد.'; }
zpd_msg_rolled_back()      { printf 'به‌روزرسانی ناموفق بود و نسخه قبلی بازیابی شد.'; }
zpd_msg_previous_active()  { printf 'نسخه قبلی با موفقیت فعال شد.'; }
zpd_msg_db_needs_review()  { printf 'بازیابی خودکار کد انجام شد، اما مهاجرت‌های دیتابیس نیاز به بررسی دارند.'; }
zpd_msg_no_repo()          { printf 'آدرس مخزن پروژه قابل تشخیص نیست. فایل /etc/zedproxy/deploy.env را بررسی کنید.'; }
zpd_msg_git_fetch_failed() { printf 'دریافت کد پروژه از GitHub ناموفق بود.\nدلیل غیرحساس خطا در خروجی بالا نمایش داده شد و نسخه فعال تغییر نکرد.'; }
zpd_msg_nginx_restored()   { printf 'تغییر مسیر Nginx ناموفق بود و تنظیمات قبلی بازیابی شد.'; }
zpd_msg_legacy_restored()  { printf 'فعال‌سازی نسخه جدید ناموفق بود و نصب قبلی (legacy) بازیابی شد.'; }
zpd_msg_supervisor_failed(){ printf 'به‌روزرسانی تنظیمات صف پردازش ناموفق بود و نسخه قبلی بازیابی می‌شود.'; }
zpd_msg_scheduler_failed() { printf 'انتقال زمان‌بندی Laravel به نسخه جدید ناموفق بود و تنظیمات قبلی بازیابی می‌شود.'; }
zpd_msg_update_ref_bad()   { printf 'نسخه درخواستی به‌روزرسان قابل بازیابی نیست. عملیات متوقف شد.'; }
