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
        -e 's/("(password|passwd|pass|secret|token|api[_-]?key|apikey|auth|access[_-]?token|refresh[_-]?token|client[_-]?secret)"[[:space:]]*:[[:space:]]*")[^"]*/\1***/gI' \
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
    # Network operation — bounded so a wedged remote can never hang the deploy.
    out="$(timeout -k "${ZPD_KILL_GRACE:-10}" "${ZPD_GIT_NET_TIMEOUT:-60}s" \
            env GIT_TERMINAL_PROMPT=0 \
            "${ZPD_GIT:-git}" ls-remote "$repo" "$ref" "${ref}^{}" </dev/null 2>"$errfile")"; rc=$?
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
    # Cloning legitimately takes time on a slow link, but must never wait
    # FOREVER (a stalled transfer would freeze the deploy). Non-interactive:
    # never prompts for credentials, stdin from /dev/null.
    local net_to="${ZPD_GIT_CLONE_TIMEOUT:-900}"
    [ -n "$repo" ] && [ -n "$ref" ] && [ -n "$dest" ] || return 1
    rm -rf "$dest" 2>/dev/null || true
    if ! timeout -k "${ZPD_KILL_GRACE:-10}" "${net_to}s" env GIT_TERMINAL_PROMPT=0 \
            "$git" clone "$repo" "$dest" </dev/null >>"$err" 2>&1; then
        rm -rf "$dest" 2>/dev/null || true
        return 1
    fi
    # Ensure tags are present (annotated + lightweight) for tag refs.
    timeout -k "${ZPD_KILL_GRACE:-10}" "${ZPD_GIT_NET_TIMEOUT:-60}s" env GIT_TERMINAL_PROMPT=0 \
        "$git" -C "$dest" fetch --tags --force --quiet origin </dev/null >>"$err" 2>&1 || true
    if ! "$git" -C "$dest" checkout --quiet --detach "$ref" </dev/null >>"$err" 2>&1; then
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
# Shared transactional writer for every nginx mutator (robots / www / gzip).
#
# zpd_nginx_mktemp CONF   — create the temp file IN THE TARGET DIRECTORY so the
#                           final rename is atomic (same filesystem — a bare
#                           /tmp mktemp would make `mv` a non-atomic copy).
# zpd_nginx_commit_file CONF TMP — copy mode+ownership from CONF to TMP
#                           FAIL-CLOSED (a stat/chmod/chown failure aborts and
#                           leaves CONF untouched), then atomically rename.
# -----------------------------------------------------------------------------
zpd_nginx_mktemp() {
    mktemp "$(dirname "$1")/.zpd-nginx.XXXXXX"
}

zpd_nginx_commit_file() {
    local conf="$1" tmp="$2" mode owner
    mode="$(stat -c '%a' "$conf")" || { rm -f "$tmp"; return 1; }
    owner="$(stat -c '%u:%g' "$conf")" || { rm -f "$tmp"; return 1; }
    chmod "$mode" "$tmp" || { rm -f "$tmp"; return 1; }
    chown "$owner" "$tmp" || { rm -f "$tmp"; return 1; }
    mv -f "$tmp" "$conf" || { rm -f "$tmp"; return 1; }
}

# -----------------------------------------------------------------------------
# zpd_nginx_robots_ok CONF
#
# Return 0 iff the config contains a `location = /robots.txt` block AND every
# such block carries EXACTLY the Laravel front-controller fallback
# `try_files $uri /index.php?$query_string;`. robots.txt is DYNAMIC
# (RobotsController) and no static file exists — a static-only block (or a
# `try_files $uri =404;`) terminates `/robots.txt` with a hard 404, which
# crawlers treat as "no robots.txt". error_page 404 does NOT rescue this:
# without `=` nginx keeps the 404 status and only takes the body from Laravel.
#
# Conservative parsing: an UNCLOSED robots block, a wrong/missing fallback, or
# DUPLICATE exact-match robots blocks inside one server block (an nginx config
# error) all fail the predicate.
# -----------------------------------------------------------------------------
zpd_nginx_robots_ok() {
    local conf="$1"
    [ -f "$conf" ] || return 1
    grep -q 'location = /robots.txt' "$conf" || return 1
    awk '
        BEGIN { sdepth = 0; server = 0; bad = 0; inb = 0 }
        {
            if (!inb && $0 ~ /location = \/robots\.txt/) {
                inb = 1; ok = 0; depth = 0
                nrobots[server]++
                if (nrobots[server] > 1) bad = 1        # duplicate exact location
            }
            if (inb) {
                if ($0 ~ /try_files/) {
                    if ($0 ~ /try_files[[:space:]]+\$uri[[:space:]]+\/index\.php\?\$query_string;/) ok = 1
                    else bad = 1                        # wrong fallback (e.g. =404)
                }
                depth += gsub(/{/, "{") - gsub(/}/, "}")
                if (depth <= 0 && $0 ~ /}/) { if (!ok) bad = 1; inb = 0 }
            } else {
                prev = sdepth
                sdepth += gsub(/{/, "{") - gsub(/}/, "}")
                if (prev == 0 && sdepth > 0) server++   # entered a new server block
            }
        }
        END { if (inb) bad = 1; exit (bad ? 1 : 0) }    # unclosed block → fail
    ' "$conf"
}

# -----------------------------------------------------------------------------
# zpd_nginx_robots_repairable CONF
#
# Fail-closed gate for the mutator: layouts we cannot repair without guessing
# are REPORTED and refused, never silently modified — a regex/prefix robots
# location, an unclosed robots block, or duplicate exact-match robots blocks
# inside the same server block.
# -----------------------------------------------------------------------------
zpd_nginx_robots_repairable() {
    local conf="$1"
    if grep -Eq 'location[[:space:]]+(~\*?|\^~)[^{]*robots' "$conf"; then
        echo "nginx robots: unsupported regex/prefix robots location found — refusing to modify (adjust the config manually)" >&2
        return 1
    fi
    awk '
        BEGIN { sdepth = 0; server = 0; bad = 0; inb = 0 }
        {
            if (!inb && $0 ~ /location = \/robots\.txt/) {
                inb = 1; depth = 0
                nrobots[server]++
                if (nrobots[server] > 1) bad = 1
            }
            if (inb) {
                depth += gsub(/{/, "{") - gsub(/}/, "}")
                if (depth <= 0 && $0 ~ /}/) inb = 0
            } else {
                prev = sdepth
                sdepth += gsub(/{/, "{") - gsub(/}/, "}")
                if (prev == 0 && sdepth > 0) server++
            }
        }
        END { exit ((bad || inb) ? 1 : 0) }
    ' "$conf" || {
        echo "nginx robots: malformed robots.txt location (unclosed or duplicated in one server block) — refusing to modify" >&2
        return 1
    }
}

# -----------------------------------------------------------------------------
# zpd_nginx_rewrite_robots CONF
#
# Idempotent, conservative mutator for the robots.txt location (companion of
# zpd_nginx_rewrite_root). States handled:
#   a) block present with a wrong/missing fallback → NORMALIZED in place to
#      `try_files $uri /index.php?$query_string;` (single-line legacy and
#      multi-line forms both handled; `try_files $uri =404;` is normalized,
#      not failed)
#   b) block absent entirely → a fresh block is inserted after the favicon
#      location — skipping over a complete MULTI-LINE favicon block — or after
#      the first `root …;` line when no favicon block exists
#   c) block already correct → no-op, return 0, byte-identical
# Unsupported layouts (regex robots locations, unclosed/duplicate blocks) are
# refused fail-closed with a diagnostic. Certbot-managed ssl_certificate /
# listen 443 lines are never modified. The temp file is created in the target
# directory and committed with fail-closed mode/ownership preservation.
# -----------------------------------------------------------------------------
zpd_nginx_rewrite_robots() {
    local conf="$1" tmp
    [ -f "$conf" ] || { echo "nginx robots: config missing: ${conf}" >&2; return 1; }
    zpd_nginx_robots_ok "$conf" && return 0
    zpd_nginx_robots_repairable "$conf" || return 1
    tmp="$(zpd_nginx_mktemp "$conf")" || return 1
    if grep -q 'location = /robots.txt' "$conf"; then
        # a) normalize every exact-match robots block to the canonical fallback.
        awk '
            !inb && /location = \/robots\.txt/ {
                if ($0 ~ /}/) {
                    # single-line legacy block
                    if ($0 ~ /try_files[^;]*;/)
                        sub(/try_files[^;]*;/, "try_files $uri /index.php?$query_string;")
                    else
                        sub(/}[^}]*$/, "try_files $uri /index.php?$query_string; }")
                    print; next
                }
                inb = 1; seen = 0; print; next
            }
            inb && /try_files/ {
                indent = $0; sub(/[^ \t].*$/, "", indent)
                print indent "try_files $uri /index.php?$query_string;"
                seen = 1; next
            }
            inb && /}/ {
                if (!seen) print "        try_files $uri /index.php?$query_string;"
                inb = 0; print; next
            }
            { print }
        ' "$conf" > "$tmp" || { rm -f "$tmp"; return 1; }
    elif grep -q 'location = /favicon.ico' "$conf"; then
        # b) insert AFTER the favicon block's closing brace (a multi-line
        #    favicon block must be skipped, never split).
        awk '
            function emit_robots() {
                print "    location = /robots.txt {"
                print "        access_log off;"
                print "        log_not_found off;"
                print "        try_files $uri /index.php?$query_string;"
                print "    }"
                done = 1
            }
            !done && infav {
                d += gsub(/{/, "{") - gsub(/}/, "}")
                print
                if (d <= 0 && $0 ~ /}/) { infav = 0; emit_robots() }
                next
            }
            !done && /location = \/favicon\.ico/ {
                d = gsub(/{/, "{") - gsub(/}/, "}")
                print
                if (d <= 0 && $0 ~ /}/) emit_robots()
                else infav = 1
                next
            }
            { print }
        ' "$conf" > "$tmp" || { rm -f "$tmp"; return 1; }
    else
        # b-fallback) no favicon anchor: insert after the first `root …;` line.
        awk '
            { print }
            !done && /^[[:space:]]*root[[:space:]]/ {
                print "    location = /robots.txt {"
                print "        access_log off;"
                print "        log_not_found off;"
                print "        try_files $uri /index.php?$query_string;"
                print "    }"
                done = 1
            }
        ' "$conf" > "$tmp" || { rm -f "$tmp"; return 1; }
    fi
    zpd_nginx_commit_file "$conf" "$tmp" || return 1
    zpd_nginx_robots_ok "$conf"
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

# -----------------------------------------------------------------------------
# Canonical www→apex host routing (managed ZPD-WWW-REDIRECT block).
#
# Drift = an app-serving server block whose server_name lists BOTH the apex
# and its www variant (the historical `server_name domain www.domain;`), which
# serves the site with status 200 on two hosts. The mutator splits them:
# www gets a minimal redirect server that (1) keeps serving
# /.well-known/acme-challenge/ from the app webroot BEFORE the redirect (a
# blanket :80 redirect silently breaks HTTP-01 renewals for www) and
# (2) 301s everything else to the literal https://APEX$request_uri (never
# $host — that would retain www). A `listen 443 ssl` www redirect is created
# ONLY when the live certificate provably covers www (SAN check on the actual
# file referenced by ssl_certificate) — a redirect cannot repair a failed TLS
# handshake. Custom www-only server blocks an operator wrote are left alone.
# -----------------------------------------------------------------------------

# zpd_nginx_www_drift_domain CONF — print the apex when some server_name line
# serves both APEX and www.APEX; empty otherwise.
zpd_nginx_www_drift_domain() {
    awk '
        /^[[:space:]]*server_name[[:space:]]/ {
            line = $0; sub(/;.*/, "", line)
            n = split(line, t, /[[:space:]]+/)
            apex = ""; www = ""
            for (i = 1; i <= n; i++) {
                if (t[i] == "" || t[i] == "server_name") continue
                if (t[i] ~ /^www\./) www = substr(t[i], 5)
                else if (apex == "") apex = t[i]
            }
            if (apex != "" && www == apex) { print apex; exit }
        }
    ' "$1"
}

# zpd_cert_covers_www CONF APEX — 0 iff the certificate file referenced by the
# live config's ssl_certificate directive covers www.APEX (bounded SAN check
# on the REAL file — never inferred from DNS or a certbot filename).
zpd_cert_covers_www() {
    local conf="$1" apex="$2" cert
    cert="$(grep -m1 -E '^[[:space:]]*ssl_certificate[[:space:]]' "$conf" | awk '{print $2}' | tr -d ';')"
    [ -n "$cert" ] && [ -f "$cert" ] || return 1
    timeout -k 5 "${ZPD_SVC_TIMEOUT:-20}s" "${ZPD_OPENSSL:-openssl}" x509 -in "$cert" -noout -checkhost "www.${apex}" </dev/null 2>/dev/null \
        | grep -q 'does match'
}

zpd_nginx_www_ok() {
    local conf="$1"
    [ -f "$conf" ] || return 1
    # An app block still serving both hosts is drift.
    [ -n "$(zpd_nginx_www_drift_domain "$conf")" ] && return 1
    # A managed redirect block, when present, must keep the ACME exemption and
    # the literal-apex 301.
    if grep -q 'ZPD-WWW-REDIRECT-BEGIN' "$conf"; then
        awk '
            /ZPD-WWW-REDIRECT-BEGIN/ { inm = 1 }
            inm && /acme-challenge/ { a = 1 }
            inm && /return 301 https:\/\// { r = 1 }
            /ZPD-WWW-REDIRECT-END/ { inm = 0 }
            END { exit (a && r) ? 0 : 1 }
        ' "$conf" || return 1
    fi
    return 0
}

zpd_nginx_rewrite_www() {
    local conf="$1" apex webroot tmp
    [ -f "$conf" ] || { echo "nginx www: config missing: ${conf}" >&2; return 1; }
    zpd_nginx_www_ok "$conf" && return 0
    apex="$(zpd_nginx_www_drift_domain "$conf")"
    if [ -z "$apex" ]; then
        echo "nginx www: managed ZPD-WWW-REDIRECT block is malformed — refusing to modify (restore or delete the managed block manually)" >&2
        return 1
    fi
    webroot="$(grep -m1 -E '^[[:space:]]*root[[:space:]]' "$conf" | awk '{print $2}' | tr -d ';')"
    if [ -z "$webroot" ]; then
        echo "nginx www: cannot determine the app webroot for the ACME exemption — refusing to modify" >&2
        return 1
    fi
    tmp="$(zpd_nginx_mktemp "$conf")" || return 1
    # 1) Drop www.APEX from every server_name line (the app blocks keep apex).
    awk -v apex="$apex" '
        BEGIN { esc = apex; gsub(/\./, "\\.", esc) }
        /^[[:space:]]*server_name[[:space:]]/ { gsub("[[:space:]]+www\\." esc, "") }
        { print }
    ' "$conf" > "$tmp" || { rm -f "$tmp"; return 1; }
    # 2) Append the managed redirect block once (idempotent via the marker).
    if ! grep -q 'ZPD-WWW-REDIRECT-BEGIN' "$tmp"; then
        {
            printf '\n# ZPD-WWW-REDIRECT-BEGIN (managed by ZedProxy deploy — canonical host routing)\n'
            printf 'server {\n'
            printf '    listen 80;\n'
            printf '    server_name www.%s;\n' "$apex"
            printf '    # HTTP-01 renewals for www must keep working: serve ACME challenges\n'
            printf '    # from the app webroot BEFORE the catch-all redirect.\n'
            printf '    location ^~ /.well-known/acme-challenge/ {\n'
            printf '        root %s;\n' "$webroot"
            printf '    }\n'
            printf '    location / {\n'
            printf '        return 301 https://%s$request_uri;\n' "$apex"
            printf '    }\n'
            printf '}\n'
            if zpd_cert_covers_www "$conf" "$apex"; then
                # Reuse the live cert paths, but strip trailing comments — the
                # copied lines in OUR block are not certbot-managed.
                local strip='s/[[:space:]]*#.*$//' sslc sslk sslinc ssldh
                sslc="$(grep -m1 -E '^[[:space:]]*ssl_certificate[[:space:]]' "$conf" | sed "$strip")"
                sslk="$(grep -m1 -E '^[[:space:]]*ssl_certificate_key[[:space:]]' "$conf" | sed "$strip")"
                sslinc="$(grep -m1 -E '^[[:space:]]*include[[:space:]].*options-ssl' "$conf" | sed "$strip")"
                ssldh="$(grep -m1 -E '^[[:space:]]*ssl_dhparam[[:space:]]' "$conf" | sed "$strip")"
                printf 'server {\n'
                printf '    listen 443 ssl;\n'
                printf '    server_name www.%s;\n' "$apex"
                [ -n "$sslc" ] && printf '%s\n' "$sslc"
                [ -n "$sslk" ] && printf '%s\n' "$sslk"
                [ -n "$sslinc" ] && printf '%s\n' "$sslinc"
                [ -n "$ssldh" ] && printf '%s\n' "$ssldh"
                printf '    return 301 https://%s$request_uri;\n' "$apex"
                printf '}\n'
            fi
            printf '# ZPD-WWW-REDIRECT-END\n'
        } >> "$tmp"
    fi
    zpd_nginx_commit_file "$conf" "$tmp" || return 1
    zpd_nginx_www_ok "$conf"
}

# -----------------------------------------------------------------------------
# Managed gzip directives (ZPD-GZIP block) for application-serving blocks.
#
# Ubuntu's default nginx gzips HTML only — CSS/JS/JSON/SVG/XML/sitemap were
# served uncompressed. The managed segment enables gzip for text assets only:
# no text/html (nginx compresses it implicitly; declaring it warns) and no
# already-compressed formats (woff2/png/jpg/webp/…). Custom operator gzip
# directives outside our markers are NEVER duplicated or modified — the config
# is treated as operator-managed and left alone.
# -----------------------------------------------------------------------------
ZPD_GZIP_TYPES='text/css text/plain text/xml application/javascript application/json application/ld+json application/xml application/rss+xml image/svg+xml application/manifest+json'

# zpd_nginx_gzip_managed_lines INDENT — print the canonical managed segment.
zpd_nginx_gzip_managed_lines() {
    local i="${1:-    }"
    printf '%s# ZPD-GZIP-BEGIN (managed by ZedProxy deploy)\n' "$i"
    printf '%sgzip on;\n' "$i"
    printf '%sgzip_vary on;\n' "$i"
    printf '%sgzip_comp_level 5;\n' "$i"
    printf '%sgzip_min_length 1024;\n' "$i"
    printf '%sgzip_types %s;\n' "$i" "$ZPD_GZIP_TYPES"
    printf '%s# ZPD-GZIP-END\n' "$i"
}

# zpd_nginx_has_custom_gzip CONF — any gzip directive OUTSIDE our markers.
zpd_nginx_has_custom_gzip() {
    awk '
        /ZPD-GZIP-BEGIN/ { inm = 1 }
        /ZPD-GZIP-END/   { inm = 0; next }
        !inm && /^[[:space:]]*gzip(_[a-z_]+)?[[:space:]]/ { found = 1 }
        END { exit found ? 0 : 1 }
    ' "$1"
}

zpd_nginx_gzip_ok() {
    local conf="$1"
    [ -f "$conf" ] || return 1
    if ! grep -q 'ZPD-GZIP-BEGIN' "$conf"; then
        # Operator-managed gzip is not our drift; NO gzip at all is.
        zpd_nginx_has_custom_gzip "$conf" && return 0
        return 1
    fi
    # Every managed segment must contain the canonical directives, and the
    # managed gzip_types must never list text/html or compressed formats.
    awk -v types="$ZPD_GZIP_TYPES" '
        /ZPD-GZIP-BEGIN/ { inm = 1; g = v = t = 0; segs++ }
        inm && /^[[:space:]]*gzip on;/       { g = 1 }
        inm && /^[[:space:]]*gzip_vary on;/  { v = 1 }
        inm && /^[[:space:]]*gzip_types /    {
            t = 1
            if ($0 ~ /text\/html/ || $0 ~ /woff2/ || $0 ~ /image\/(png|jpeg|webp|gif)/) bad = 1
            if (index($0, types) == 0) bad = 1
        }
        /ZPD-GZIP-END/ { if (!(g && v && t)) bad = 1; inm = 0 }
        END { exit (segs > 0 && !bad && !inm) ? 0 : 1 }
    ' "$conf"
}

zpd_nginx_rewrite_gzip() {
    local conf="$1" tmp
    [ -f "$conf" ] || { echo "nginx gzip: config missing: ${conf}" >&2; return 1; }
    zpd_nginx_gzip_ok "$conf" && return 0
    if ! grep -q 'ZPD-GZIP-BEGIN' "$conf" && zpd_nginx_has_custom_gzip "$conf"; then
        # Never duplicate or fight operator directives.
        echo "nginx gzip: custom gzip directives present — leaving the config operator-managed" >&2
        return 0
    fi
    tmp="$(zpd_nginx_mktemp "$conf")" || return 1
    if grep -q 'ZPD-GZIP-BEGIN' "$conf"; then
        # Normalize every managed segment to the canonical content.
        awk -v types="$ZPD_GZIP_TYPES" '
            /ZPD-GZIP-BEGIN/ {
                indent = $0; sub(/[^ \t].*$/, "", indent)
                print indent "# ZPD-GZIP-BEGIN (managed by ZedProxy deploy)"
                print indent "gzip on;"
                print indent "gzip_vary on;"
                print indent "gzip_comp_level 5;"
                print indent "gzip_min_length 1024;"
                print indent "gzip_types " types ";"
                print indent "# ZPD-GZIP-END"
                skip = 1; next
            }
            /ZPD-GZIP-END/ { skip = 0; next }
            skip { next }
            { print }
        ' "$conf" > "$tmp" || { rm -f "$tmp"; return 1; }
    else
        # Insert after the first server-level root line of each app-serving
        # block — never inside the managed www redirect-only block.
        awk -v types="$ZPD_GZIP_TYPES" '
            /ZPD-WWW-REDIRECT-BEGIN/ { inwww = 1 }
            /ZPD-WWW-REDIRECT-END/   { inwww = 0 }
            { print }
            !inwww && /^[[:space:]]*root[[:space:]]/ && !donein {
                indent = $0; sub(/[^ \t].*$/, "", indent)
                print indent "# ZPD-GZIP-BEGIN (managed by ZedProxy deploy)"
                print indent "gzip on;"
                print indent "gzip_vary on;"
                print indent "gzip_comp_level 5;"
                print indent "gzip_min_length 1024;"
                print indent "gzip_types " types ";"
                print indent "# ZPD-GZIP-END"
                donein = 1
            }
            /^server[[:space:]]*{/ || /^}[[:space:]]*$/ { donein = 0 }
        ' "$conf" > "$tmp" || { rm -f "$tmp"; return 1; }
    fi
    zpd_nginx_commit_file "$conf" "$tmp" || return 1
    zpd_nginx_gzip_ok "$conf"
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

# ── Local loopback health virtual host ───────────────────────────────────────
#
# Deployment health is validated against an internal Nginx vhost bound to
# LOOPBACK ONLY (127.0.0.1:<port>), so it never depends on Cloudflare, public
# DNS, public TLS, or the public vhost. The installer creates it and the deployer
# repairs it.

zpd_local_health_conf_path() { printf '%s' "${ZPD_LOCAL_HEALTH_CONF:-/etc/nginx/conf.d/zedproxy-local-health.conf}"; }
zpd_local_health_port()      { printf '%s' "${ZPD_LOCAL_HEALTH_PORT:-18080}"; }
zpd_local_health_url()       { printf 'http://127.0.0.1:%s' "$(zpd_local_health_port)"; }

# -----------------------------------------------------------------------------
# zpd_local_health_conf_content BASE FPM_SOCK [ROOT] — the complete
# loopback-only vhost. Serves ROOT (default <BASE>/current/public) through
# index.php via the given PHP-FPM socket. The ROOT override exists for the
# first-cutover LEGACY rollback: after `current` is removed the loopback health
# target must serve the legacy webroot (<BASE>/public), never a dangling
# current/public. Binds ONLY to 127.0.0.1/[::1]; never to a public interface.
# -----------------------------------------------------------------------------
zpd_local_health_conf_content() {
    local base="$1" sock="$2" root="${3:-${1}/current/public}" port; port="$(zpd_local_health_port)"
    cat <<CONF
# ZedProxy INTERNAL loopback health vhost (managed). Loopback ONLY — never public.
# Used by the atomic deployer to validate a release without Cloudflare/public TLS.
server {
    listen 127.0.0.1:${port};
    listen [::1]:${port};
    server_name _;
    root ${root};
    index index.php;
    access_log off;
    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        fastcgi_pass unix:${sock};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 30;
    }

    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 2M;
}
CONF
}

# -----------------------------------------------------------------------------
# zpd_local_health_conf_ok CONF BASE PORT — validate a local-health vhost:
#   - listens on 127.0.0.1:PORT
#   - root goes through <BASE>/current/public
#   - EVERY `listen` directive is loopback (127.0.0.1 or [::1]) — the port must
#     never be exposed on an external interface.
# -----------------------------------------------------------------------------
zpd_local_health_conf_ok() {
    local conf="$1" base="$2" port="${3:-$(zpd_local_health_port)}" root="${4:-${2}/current/public}"
    [ -f "$conf" ] || return 1
    grep -Eq "listen[[:space:]]+127\.0\.0\.1:${port}\b" "$conf" || return 1
    grep -Eq "root[[:space:]]+${root};" "$conf" || return 1
    # Reject any `listen` directive that is NOT loopback (position-independent, so
    # a one-line `server { listen 0.0.0.0:PORT; }` is still caught).
    if grep -oE 'listen[[:space:]]+[^;]+' "$conf" | grep -vqE '127\.0\.0\.1:|\[::1\]:'; then
        return 1
    fi
    return 0
}

# ── Laravel maintenance-state file ───────────────────────────────────────────
#
# Laravel 8+ stores the "down" state in storage/framework/maintenance.php (the
# file-based maintenance driver). Detecting the file is framework-compatible and
# does NOT depend on translated console text.
zpd_maintenance_file()     { printf '%s/storage/framework/maintenance.php' "${1:?}"; }
zpd_maintenance_file_alt() { printf '%s/storage/framework/down' "${1:?}"; }

# zpd_is_in_maintenance APP_DIR — return 0 when APP_DIR is in maintenance mode.
zpd_is_in_maintenance() {
    local d="${1:?}"
    [ -f "$(zpd_maintenance_file "$d")" ] || [ -f "$(zpd_maintenance_file_alt "$d")" ]
}

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

# ── Scheduler discovery (all cron sources, not only the canonical file) ──────
#
# The scheduler may have been configured by an older installer in /etc/crontab,
# another /etc/cron.d file, or a user's spool crontab. Reconciliation must find
# EVERY `artisan schedule:run` invocation so exactly one canonical source
# survives — two sources running the same jobs simultaneously is never allowed.

zpd_cron_d_dir()     { printf '%s' "${ZPD_CRON_D_DIR:-/etc/cron.d}"; }
zpd_etc_crontab()    { printf '%s' "${ZPD_ETC_CRONTAB:-/etc/crontab}"; }
zpd_cron_spool_dir() { printf '%s' "${ZPD_CRON_SPOOL_DIR:-/var/spool/cron/crontabs}"; }
# Non-cron scheduler homes (read-only discovery; never modified automatically):
zpd_systemd_unit_dir()     { printf '%s' "${ZPD_SYSTEMD_UNIT_DIR:-/etc/systemd/system}"; }
# ALL effective systemd unit trees (admin, runtime, and vendor). Override with a
# colon-separated ZPD_SYSTEMD_UNIT_DIRS for tests. One dir per line.
zpd_systemd_unit_dirs() {
    if [ -n "${ZPD_SYSTEMD_UNIT_DIRS:-}" ]; then
        printf '%s' "$ZPD_SYSTEMD_UNIT_DIRS" | tr ':' '\n'
        printf '\n'
        return 0
    fi
    printf '%s\n' \
        "$(zpd_systemd_unit_dir)" \
        "/run/systemd/system" \
        "/usr/local/lib/systemd/system" \
        "/usr/lib/systemd/system" \
        "/lib/systemd/system"
}
zpd_supervisor_scan_dir()  { printf '%s' "${ZPD_SUPERVISOR_SCAN_DIR:-$(dirname "$(zpd_supervisor_conf_path)")}"; }
# The main supervisord config (its [include] globs define the EFFECTIVE program
# set — never assume a single conf.d directory).
zpd_supervisord_conf()     { printf '%s' "${ZPD_SUPERVISORD_CONF:-/etc/supervisor/supervisord.conf}"; }

# -----------------------------------------------------------------------------
# zpd_scheduler_sources — print every readable file that may carry cron entries:
# the canonical managed file, every other file in cron.d, /etc/crontab, and all
# user spool crontabs. One absolute path per line.
# -----------------------------------------------------------------------------
zpd_scheduler_sources() {
    local f
    [ -f "$(zpd_scheduler_cron_path)" ] && printf '%s\n' "$(zpd_scheduler_cron_path)"
    if [ -d "$(zpd_cron_d_dir)" ]; then
        for f in "$(zpd_cron_d_dir)"/*; do
            [ -f "$f" ] || continue
            [ "$f" = "$(zpd_scheduler_cron_path)" ] && continue
            # cron itself IGNORES /etc/cron.d entries whose basename contains a
            # dot (run-parts rule) — so backups like *.zpd-precutover are not
            # active sources and must not be scanned as such.
            case "$(basename "$f")" in *.*) continue ;; esac
            printf '%s\n' "$f"
        done
    fi
    [ -f "$(zpd_etc_crontab)" ] && printf '%s\n' "$(zpd_etc_crontab)"
    if [ -d "$(zpd_cron_spool_dir)" ]; then
        for f in "$(zpd_cron_spool_dir)"/*; do
            [ -f "$f" ] && printf '%s\n' "$f"
        done
    fi
    return 0
}

# -----------------------------------------------------------------------------
# zpd_scheduler_lines FILE — print every non-comment line of FILE containing an
# `artisan schedule:run` invocation (any Laravel scheduler entry).
# -----------------------------------------------------------------------------
zpd_scheduler_lines() {
    local file="$1"
    [ -f "$file" ] || return 0
    grep -E 'artisan[[:space:]]+schedule:run' "$file" 2>/dev/null \
        | grep -vE '^[[:space:]]*#' || true
}

# -----------------------------------------------------------------------------
# zpd_scheduler_ours_re BASE — the ERE matching OUR scheduler invocations: the
# EXECUTED artisan path must live under BASE (legacy base/artisan,
# current/artisan, or an individual releases/<id>/artisan), OR the installer's
# own relative form `cd <base> && php artisan schedule:run` (where the artisan
# actually executed resolves under BASE). Shared by classification AND removal
# so the two can never disagree. A foreign app's schedule:run that merely
# mentions BASE elsewhere on the line (e.g. `cd <base> && php /other/artisan
# schedule:run`) is NOT ours — the relative alternative requires the literal
# relative `php artisan` immediately after the cd into BASE.
# -----------------------------------------------------------------------------
zpd_scheduler_ours_re() {
    local esc
    esc="$(printf '%s' "$1" | sed 's/[][\.*^$(){}?+|]/\\&/g')"
    printf '(%s(/current|/releases/[^[:space:]]*)?/artisan[[:space:]]+schedule:run|cd[[:space:]]+%s(/current)?/?[[:space:]]*&&[[:space:]]*php[[:space:]]+artisan[[:space:]]+schedule:run)' "$esc" "$esc"
}

# zpd_scheduler_line_is_ours LINE BASE — 0 when LINE executes the ZedProxy
# application's scheduler (artisan path under BASE).
zpd_scheduler_line_is_ours() {
    local line="$1" base="$2"
    printf '%s' "$line" | grep -qE "$(zpd_scheduler_ours_re "$base")"
}

# ── Per-deployment operational snapshots ─────────────────────────────────────
#
# Every activation snapshots the effective operational configuration under a
# release-scoped directory, so a failed activation can restore EXACTLY the
# pre-deployment state — repeated deployments never clobber each other's
# backups (unlike the old static `.zpd-precutover` names).

zpd_snapshots_dir() { printf '%s/deploy/snapshots' "$(zpd_shared_dir)"; }

# ── Manifest schema ──────────────────────────────────────────────────────────
#
# Version 2 introduces adopted/failed finalization fields. A manifest carrying
# manifest_schema_version >= 2 with result=success/activating is MODERN and must
# always satisfy strict SHA verification; result=adopted marks a historical
# release backfilled from observed facts (compat verification applies).
zpd_manifest_schema_version() { printf '2'; }

# ── First-cutover (legacy → first release) rollback bookkeeping ──────────────
#
# On the very first legacy→release migration there is no previous release id.
# The legacy application itself is the rollback target. We record the legacy
# operational paths so a failed first activation can restore them exactly.

zpd_legacy_marker_file() { printf '%s/shared/deploy/legacy-rollback.json' "$(zpd_base)"; }

# Fresh per-attempt snapshots live under a dedicated directory; a pointer file
# names the COMMITTED snapshot for the current attempt. Old global snapshots
# (the pre-pointer layout above) are never used automatically — the doctor may
# list them for manual recovery.
zpd_legacy_snapshots_dir()   { printf '%s/shared/deploy/legacy-snapshots' "$(zpd_base)"; }
zpd_legacy_pointer_file()    { printf '%s/current.ptr' "$(zpd_legacy_snapshots_dir)"; }
zpd_legacy_snapshot_schema() { printf '2'; }

# zpd_save_legacy_rollback BASE NGINX_CONF SUPERVISOR_CONF SCHED_CRON [ID]
#
# FRESH, IMMUTABLE, PER-ATTEMPT snapshot of the pre-cutover configuration.
# Every first-cutover attempt captures its OWN release-scoped snapshot — a
# complete-looking snapshot from an earlier attempt is never reused (it may no
# longer match the current pre-cutover state). The capture is transactional:
#   1. everything is written into a TEMPORARY directory (<id>.tmp)
#   2. every source (nginx, supervisor, scheduler, local-health vhost,
#      deploy.env — plus the wrapper set when the wrapper library is loaded)
#      is copied, cmp-verified, SHA-256-fingerprinted, and recorded in the map
#      with its mode/ownership/existence
#   3. marker.json records snapshot_schema_version and snapshot_complete=false
#   4. every artifact + metadata record is re-verified
#   5. marker.json is rewritten with snapshot_complete=true and the directory
#      is ATOMICALLY renamed to its final name; only then is the pointer file
#      atomically updated to name this snapshot
# Only a pointer to a committed (snapshot_complete=true, current-schema)
# snapshot permits maintenance/migration to start. Returns non-zero on ANY
# capture failure.
zpd_save_legacy_rollback() {
    local base="$1" nginx="$2" super="$3" cron="$4" id="${5:-}"
    local snaps tmp final map lh envf ptr ptmp
    [ -n "$id" ] || id="$(date -u +%Y%m%d%H%M%S).$$"
    snaps="$(zpd_legacy_snapshots_dir)"
    tmp="${snaps}/${id}.tmp"
    final="${snaps}/${id}"
    lh="$(zpd_local_health_conf_path)"
    envf="$(zpd_deploy_env_file)"
    mkdir -p "$snaps" 2>/dev/null || return 1
    chmod 700 "$snaps" 2>/dev/null || true
    rm -rf "$tmp" 2>/dev/null || return 1
    [ -e "$final" ] && return 1                    # ids are per-attempt unique
    mkdir -p "$tmp" 2>/dev/null || return 1
    map="${tmp}/legacy-files.map"
    : > "$map" || return 1
    chmod 600 "$map" 2>/dev/null || true
    # name TAB path TAB mode TAB uid:gid TAB existed TAB sha256
    local name src snap sha
    for name in nginx supervisor scheduler localhealth deployenv; do
        case "$name" in
            nginx)       src="$nginx" ;;
            supervisor)  src="$super" ;;
            scheduler)   src="$cron" ;;
            localhealth) src="$lh" ;;
            deployenv)   src="$envf" ;;
        esac
        snap="${tmp}/${name}.legacy"
        if [ -f "$src" ]; then
            cp -a "$src" "$snap" 2>/dev/null || return 1
            cmp -s "$src" "$snap"            || return 1
            sha="$(sha256sum "$snap" 2>/dev/null | awk '{print $1}')"
            [ -n "$sha" ] || return 1
            printf '%s\t%s\t%s\t%s\t1\t%s\n' "$name" "$src" \
                "$(stat -c '%a' "$src" 2>/dev/null)" "$(stat -c '%u:%g' "$src" 2>/dev/null)" "$sha" >> "$map" || return 1
        else
            printf '%s\t%s\t\t\t0\t-\n' "$name" "$src" >> "$map" || return 1
        fi
    done
    # Wrapper commands + bootstrap library — REQUIRED verified capture.
    if declare -F zpw_backup_wrappers >/dev/null 2>&1; then
        zpw_backup_wrappers "${tmp}/wrappers" >/dev/null 2>&1 || return 1
    fi
    zpd_write_manifest "${tmp}/marker.json" \
        "legacy_base=${base}" "nginx_conf=${nginx}" "supervisor_conf=${super}" \
        "scheduler_cron=${cron}" "local_health_conf=${lh}" "deploy_env=${envf}" \
        "snapshot_schema_version=$(zpd_legacy_snapshot_schema)" \
        "snapshot_complete=false" \
        "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" || return 1
    # Re-verify EVERY recorded artifact before committing.
    _zpd_legacy_map_verified "$tmp" || return 1
    zpd_write_manifest "${tmp}/marker.json" \
        "legacy_base=${base}" "nginx_conf=${nginx}" "supervisor_conf=${super}" \
        "scheduler_cron=${cron}" "local_health_conf=${lh}" "deploy_env=${envf}" \
        "snapshot_schema_version=$(zpd_legacy_snapshot_schema)" \
        "snapshot_complete=true" \
        "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" || return 1
    mv -T "$tmp" "$final" 2>/dev/null || return 1   # atomic commit
    # Atomic pointer update — the pointer only ever names a COMMITTED snapshot.
    ptr="$(zpd_legacy_pointer_file)"
    ptmp="${ptr}.tmp"
    printf '%s\n' "$final" > "$ptmp" || return 1
    chmod 600 "$ptmp" 2>/dev/null || true
    mv -f "$ptmp" "$ptr" || return 1
    return 0
}

# _zpd_legacy_map_verified DIR — every map record must be present (all five
# managed names) and every existing artifact must be readable, non-empty, and
# match its recorded SHA-256. Used both before commit and before restore.
_zpd_legacy_map_verified() {
    local dir="$1" map name path mode owner existed sha have
    local seen_nginx=0 seen_super=0 seen_sched=0 seen_lh=0 seen_env=0
    map="${dir}/legacy-files.map"
    [ -f "$map" ] || return 1
    while IFS=$'\t' read -r name path mode owner existed sha; do
        [ -n "$name" ] || continue
        if [ "$existed" = "1" ]; then
            [ -s "${dir}/${name}.legacy" ] && [ -r "${dir}/${name}.legacy" ] || return 1
            have="$(sha256sum "${dir}/${name}.legacy" 2>/dev/null | awk '{print $1}')"
            [ -n "$have" ] && [ "$have" = "$sha" ] || return 1
        fi
        case "$name" in
            nginx)       seen_nginx=1 ;;
            supervisor)  seen_super=1 ;;
            scheduler)   seen_sched=1 ;;
            localhealth) seen_lh=1 ;;
            deployenv)   seen_env=1 ;;
        esac
    done < "$map"
    [ "$seen_nginx" = "1" ] && [ "$seen_super" = "1" ] && [ "$seen_sched" = "1" ] \
        && [ "$seen_lh" = "1" ] && [ "$seen_env" = "1" ]
}

# zpd_legacy_snapshot_dir — print the COMMITTED snapshot directory the pointer
# names, or nothing when no committed snapshot exists.
zpd_legacy_snapshot_dir() {
    local ptr dir
    ptr="$(zpd_legacy_pointer_file)"
    [ -f "$ptr" ] || return 1
    dir="$(cat "$ptr" 2>/dev/null)"
    [ -n "$dir" ] && [ -d "$dir" ] || return 1
    printf '%s' "$dir"
}

# zpd_has_legacy_rollback — a committed per-attempt snapshot exists. Old-layout
# global markers (legacy-rollback.json) do NOT count — they are listed by the
# doctor for manual recovery only, never used as an automatic rollback path.
zpd_has_legacy_rollback() { zpd_legacy_snapshot_dir >/dev/null 2>&1; }

# zpd_legacy_rollback_valid — the pointed-to snapshot is COMMITTED
# (snapshot_complete=true), carries the CURRENT snapshot schema version, has a
# record for every managed source, and every artifact matches its recorded
# SHA-256. An interrupted (.tmp / snapshot_complete=false), truncated,
# old-schema, or old-layout snapshot is NEVER valid.
zpd_legacy_rollback_valid() {
    local dir marker
    dir="$(zpd_legacy_snapshot_dir)" || return 1
    marker="${dir}/marker.json"
    [ -f "$marker" ] || return 1
    [ "$(zpd_manifest_get "$marker" snapshot_complete 2>/dev/null)" = "true" ] || return 1
    [ "$(zpd_manifest_get "$marker" snapshot_schema_version 2>/dev/null)" = "$(zpd_legacy_snapshot_schema)" ] || return 1
    _zpd_legacy_map_verified "$dir"
}

# zpd_restore_legacy_rollback — EXACT, FAIL-CLOSED restore of the pre-cutover
# state from THIS ATTEMPT's committed snapshot. The snapshot must validate
# (committed, current schema, complete, SHA-verified) — an old-layout or
# interrupted snapshot is refused, never silently applied. Every
# copy/remove/chmod/chown error accumulates into the return code; restored
# content is cmp-verified; files that did not exist before the cutover are
# removed (verified absent); the wrapper set is restored fail-closed.
zpd_restore_legacy_rollback() {
    local dir map rc=0
    dir="$(zpd_legacy_snapshot_dir)" || return 1
    zpd_legacy_rollback_valid       || return 1
    map="${dir}/legacy-files.map"
    local name path mode owner existed sha snap
    while IFS=$'\t' read -r name path mode owner existed sha; do
        [ -n "$name" ] && [ -n "$path" ] || continue
        snap="${dir}/${name}.legacy"
        if [ "$existed" = "1" ]; then
            if ! cp "$snap" "$path" 2>/dev/null || ! cmp -s "$snap" "$path"; then
                rc=1; continue
            fi
            [ -n "$mode" ]  && { chmod "$mode" "$path" 2>/dev/null || rc=1; }
            [ -n "$owner" ] && { chown "$owner" "$path" 2>/dev/null || rc=1; }
        else
            rm -f "$path" 2>/dev/null || rc=1
            [ -e "$path" ] && rc=1     # the cutover-created file must be GONE
        fi
    done < "$map"
    if declare -F zpw_restore_wrappers >/dev/null 2>&1 && [ -d "${dir}/wrappers" ]; then
        zpw_restore_wrappers "${dir}/wrappers" || rc=1
    fi
    return "$rc"
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
# zpd_print_manifest key=value... — serialize the SAME masked/escaped flat JSON
# directly to the CURRENT stdout. Used for machine-readable output (doctor
# --json, deploy-status --json): zpd_write_manifest must never be pointed at
# /dev/stdout, because its atomic temp-file rename would replace the
# /dev/stdout symlink (and chmod 600 it) when running as root.
# -----------------------------------------------------------------------------
zpd_print_manifest() {
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
zpd_msg_migrate_none()     { printf 'هیچ مهاجرت جدیدی اجرا نشد و دیتابیس تغییری نکرد.'; }
zpd_msg_migrate_applied()  { printf 'مهاجرت‌های جدید اجرا شده‌اند؛ در صورت بروز ناسازگاری، نسخه پشتیبان دیتابیس را بررسی کنید.'; }
zpd_msg_up_failed()        { printf 'خروج از حالت تعمیر و نگهداری ناموفق بود؛ نسخه جدید فعال نشد.'; }
zpd_msg_sched_conflict()   { printf 'چند زمان‌بندی متداخل برای Laravel شناسایی شد. برای جلوگیری از اجرای تکراری، عملیات متوقف شد.'; }
zpd_msg_adopted()          { printf 'اطلاعات نسخه فعال قدیمی با موفقیت شناسایی و برای سیستم انتشار جدید ثبت شد.'; }
zpd_msg_repair_done()      { printf 'تنظیمات انتشار بررسی و ناسازگاری‌های قابل اصلاح با موفقیت ترمیم شدند.'; }
zpd_msg_doctor_bundle()    { printf 'گزارش عیب‌یابی بدون اطلاعات حساس ایجاد شد:'; }
