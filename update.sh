#!/usr/bin/env bash
# =============================================================================
# ZedProxy standalone updater — self-bootstrapping entrypoint for the atomic,
# release-based deployment system.
#
#   sudo bash update.sh            # or the installed `zedproxy-update` shortcut
#
# This script is intentionally SELF-CONTAINED and does NOT depend on:
#   - the caller's current working directory (works from /root, /tmp, /, ~, the
#     legacy app dir, or the active release dir), or
#   - any deploy script already present on the server (which may be an older,
#     broken copy).
#
# It resolves the repository + ref from persistent configuration (or safe
# defaults), fetches the EXACT updater source into a protected temporary
# directory, and runs the deploy script from that freshly-fetched revision — so
# the deployment logic that executes always matches current, fixed code. The
# deploy script itself then builds and activates the release atomically.
# =============================================================================
set -Eeuo pipefail

DEPLOY_ENV="${ZPD_DEPLOY_ENV:-/etc/zedproxy/deploy.env}"
DEFAULT_REPO='https://github.com/Mhoseinshah1/zed_web.git'

log()  { printf '[update] %s\n' "$*"; }
err()  { printf '[update] ERROR: %s\n' "$*" >&2; }

# ── Load non-secret deploy config (explicit environment always wins) ──────────
if [ -f "$DEPLOY_ENV" ]; then
    while IFS= read -r _line || [ -n "$_line" ]; do
        case "$_line" in ''|\#*) continue ;; esac
        case "$_line" in *=*) ;; *) continue ;; esac
        _k="${_line%%=*}"; _v="${_line#*=}"
        _k="$(printf '%s' "$_k" | tr -d '[:space:]')"
        _v="${_v%$'\r'}"; _v="${_v#\"}"; _v="${_v%\"}"; _v="${_v#\'}"; _v="${_v%\'}"
        case "$_k" in
            ZPD_BASE|ZPD_REPO_URL|ZPD_REF|ZPD_HEALTH_URL|ZPD_KEEP_RELEASES|ZPD_MIN_DISK_MB)
                if [ -z "${!_k:-}" ]; then export "$_k=$_v"; fi ;;
        esac
    done < "$DEPLOY_ENV"
fi

ZPD_BASE="${ZPD_BASE:-/var/www/zedproxy}"
REF="${ZPD_REF:-main}"
GIT="${ZPD_GIT:-git}"

# ── Resolve a SAFE repository source (never "." / the caller's CWD) ───────────
# Precedence: explicit ZPD_REPO_URL / deploy.env → active-release origin →
# legacy-install origin → built-in public default.
repo=""
if [ -n "${ZPD_REPO_URL:-}" ]; then
    repo="$ZPD_REPO_URL"
elif [ -e "${ZPD_BASE}/current/.git" ]; then
    repo="$("$GIT" -C "${ZPD_BASE}/current" remote get-url origin 2>/dev/null || true)"
elif [ -e "${ZPD_BASE}/.git" ]; then
    repo="$("$GIT" -C "${ZPD_BASE}" remote get-url origin 2>/dev/null || true)"
fi
[ -n "$repo" ] || repo="$DEFAULT_REPO"

case "$repo" in
    .|./*|../*|..)
        err "آدرس مخزن پروژه قابل تشخیص نیست. فایل ${DEPLOY_ENV} را بررسی کنید."
        exit 1 ;;
esac

# ── Redact credentials that might appear in an authenticated URL ─────────────
redact() { sed -E 's#(://)[^/@[:space:]:]+:[^/@[:space:]]+@#\1***:***@#g'; }

BOOTSTRAP="$(mktemp -d)"
chmod 700 "$BOOTSTRAP" 2>/dev/null || true
cleanup() { rm -rf "$BOOTSTRAP" 2>/dev/null || true; }
trap cleanup EXIT INT TERM
_err="${BOOTSTRAP}/git.log"

# ── Resolve the EXACT updater commit BEFORE fetching (strict, no silent
#    fallback to the default branch when a custom ref was requested) ──────────
# Supports branch / lightweight tag / annotated tag (peeled) / full 40-hex SHA.
resolve_sha() {
    local out sha
    out="$("$GIT" ls-remote "$repo" "$REF" "${REF}^{}" 2>>"$_err")" || {
        printf '%s' "$REF" | grep -Eq '^[0-9a-f]{40}$' && { printf '%s' "$REF"; return 0; }
        return 1
    }
    sha="$(printf '%s\n' "$out" | awk '/\^\{\}$/ {print $1; exit}')"
    [ -n "$sha" ] || sha="$(printf '%s\n' "$out" | awk 'NF {print $1; exit}')"
    if [ -z "$sha" ]; then
        printf '%s' "$REF" | grep -Eq '^[0-9a-f]{40}$' && { printf '%s' "$REF"; return 0; }
        return 1
    fi
    printf '%s' "$sha"
}

log "Resolving updater ref '${REF}' from ${repo}…"
if ! RESOLVED_SHA="$(resolve_sha)" || [ -z "$RESOLVED_SHA" ]; then
    err "نسخه درخواستی به‌روزرسان قابل بازیابی نیست. عملیات متوقف شد."
    redact < "$_err" >&2 || true
    exit 1
fi

# ── Fetch the exact updater source into the protected temp dir ────────────────
log "Fetching updater source (${REF} → ${RESOLVED_SHA:0:12})…"
if ! "$GIT" clone "$repo" "${BOOTSTRAP}/src" >>"$_err" 2>&1; then
    err "دریافت کد به‌روزرسان از GitHub ناموفق بود."
    redact < "$_err" >&2 || true
    exit 1
fi
# Tags may be needed for a tag ref. A fetch failure here is not fatal on its own,
# but the checkout below is STRICT.
"$GIT" -C "${BOOTSTRAP}/src" fetch --tags --force --quiet origin >>"$_err" 2>&1 || true

# STRICT checkout — a missing/invalid ref must stop immediately (no `|| true`).
if ! "$GIT" -C "${BOOTSTRAP}/src" checkout --quiet --detach "$REF" >>"$_err" 2>&1; then
    err "نسخه درخواستی به‌روزرسان قابل بازیابی نیست. عملیات متوقف شد."
    redact < "$_err" >&2 || true
    exit 1
fi

# Verify the fetched updater HEAD equals the resolved commit.
GOT_SHA="$("$GIT" -C "${BOOTSTRAP}/src" rev-parse HEAD 2>>"$_err" || true)"
if [ "$GOT_SHA" != "$RESOLVED_SHA" ]; then
    err "نسخه درخواستی به‌روزرسان قابل بازیابی نیست. عملیات متوقف شد."
    err "(checked out ${GOT_SHA:-<none>}, expected ${RESOLVED_SHA})"
    exit 1
fi

DEPLOY="${BOOTSTRAP}/src/scripts/deploy/deploy.sh"
if [ ! -f "$DEPLOY" ]; then
    err "deploy script not found in fetched source (${DEPLOY})"
    exit 1
fi

# The fetched deploy script re-resolves + re-clones the exact release itself.
log "Running atomic deployment from verified updater ${RESOLVED_SHA:0:12}…"
exec bash "$DEPLOY" "$@"
