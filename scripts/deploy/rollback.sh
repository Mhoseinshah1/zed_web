#!/usr/bin/env bash
# =============================================================================
# ZedProxy manual rollback — atomically switch `current` back to a previous
# release. Never rotates APP_KEY, never removes uploaded files (only the symlink
# moves); shared .env/storage are untouched. Requires explicit confirmation and
# runs post-rollback health checks.
#
#   sudo bash scripts/deploy/rollback.sh [RELEASE_ID] [--yes]
#
# Source-safe: sourcing only defines functions.
# =============================================================================

_RB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
for _cand in "${_RB_DIR}/../lib/deploy-lib.sh" "${ZPD_LIB:-}"; do
    if [ -n "${_cand:-}" ] && [ -f "$_cand" ]; then
        # shellcheck disable=SC1090
        source "$_cand"; break
    fi
done
# shellcheck disable=SC1090
[ -f "${_RB_DIR}/deploy.sh" ] && source "${_RB_DIR}/deploy.sh"

# Load persistent non-secret deploy config (base, health URL) for the shortcuts.
zpd_load_deploy_env

# rb_list — print "<release-id> <result>" newest-first from each manifest.
rb_list() {
    local rel manifest result
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        manifest="$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json"
        result="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
        printf '%s %s\n' "$rel" "${result:-unknown}"
    done < <(zpd_list_releases)
}

# rb_default_target — the immediately-previous HEALTHY release (result=success),
# else the immediately previous release. When there is no previous release but a
# legacy install was migrated (a saved legacy snapshot exists), the special
# target "legacy" restores the pre-atomic application.
rb_default_target() {
    local current previous rel result found=0
    current="$(zpd_current_release)"
    previous="$(zpd_previous_release)"

    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        if [ "$found" -eq 1 ]; then
            result="$(zpd_manifest_get "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" result 2>/dev/null)"
            if [ "$result" = "success" ]; then
                printf '%s' "$rel"; return 0
            fi
        fi
        [ "$rel" = "$current" ] && found=1
    done < <(zpd_list_releases)

    if [ -n "$previous" ]; then
        printf '%s' "$previous"; return 0
    fi
    if zpd_has_legacy_rollback; then
        printf 'legacy'; return 0
    fi
    printf ''
}

# rb_rollback TARGET FPM — switch current to TARGET and reload services. The
# special TARGET "legacy" restores the pre-atomic application via the saved
# first-cutover snapshot instead of a release symlink switch.
rb_rollback() {
    local target="$1" fpm="$2"
    [ -n "$target" ] || { echo "no target release" >&2; return 1; }

    if [ "$target" = "legacy" ]; then
        if declare -F dep_first_cutover_rollback >/dev/null 2>&1; then
            dep_first_cutover_rollback "$(zpd_base)" "$fpm"
            return $?
        fi
        echo "legacy rollback helper unavailable" >&2
        return 1
    fi

    [ -d "$(zpd_releases_dir)/${target}" ] || { echo "release ${target} not found" >&2; return 1; }

    zpd_switch_current "$target" || return 1
    dep_reload_php_fpm "$fpm" || true
    dep_reload_nginx          || true
    dep_restart_workers       || true
    ( cd "$(zpd_current_link)" 2>/dev/null && "${ZPD_PHP:-php}" artisan up 2>/dev/null ) || true

    dep_health "${ZPD_HEALTH_URL:-http://localhost}"
}

rb_main() {
    local target="" assume_yes=0 arg
    for arg in "$@"; do
        case "$arg" in
            --yes|-y) assume_yes=1 ;;
            *) target="$arg" ;;
        esac
    done

    echo "Available releases (newest first):"
    rb_list
    echo ""

    [ -n "$target" ] || target="$(rb_default_target)"
    if [ -z "$target" ]; then
        echo "No previous release to roll back to." >&2
        return 1
    fi
    echo "Rollback target: ${target}"

    if [ "$assume_yes" -ne 1 ]; then
        printf 'Type the release id to confirm rollback: '
        read -r confirm
        if [ "$confirm" != "$target" ]; then
            echo "Confirmation did not match. Aborted." >&2
            return 1
        fi
    fi

    local ver fpm
    ver="$("${ZPD_PHP:-php}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.3)"
    fpm="php${ver}-fpm"

    if rb_rollback "$target" "$fpm"; then
        zpd_write_manifest "$(zpd_state_file)" "active_release=${target}" "result=rolled_back_manual"
        echo "$(zpd_msg_previous_active)"
        return 0
    fi
    echo "Rollback health check failed." >&2
    return 1
}

if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -Euo pipefail
    _lock="$(zpd_lock_file)"
    zpd_run_locked "$_lock" -- rb_main "$@"
    _rc=$?
    [ "$_rc" -eq 200 ] && { echo "$(zpd_lock_busy_message)" >&2; exit 200; }
    exit "$_rc"
fi
