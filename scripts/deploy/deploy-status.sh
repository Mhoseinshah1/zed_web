#!/usr/bin/env bash
# =============================================================================
# ZedProxy deployment status — report the active/previous release, last result,
# migration + health state, and list known releases. Read-only.
#
#   bash scripts/deploy/deploy-status.sh [--json]
#
# Source-safe: sourcing only defines functions.
# =============================================================================

_DS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
for _cand in "${_DS_DIR}/../lib/deploy-lib.sh" "${ZPD_LIB:-}"; do
    if [ -n "${_cand:-}" ] && [ -f "$_cand" ]; then
        # shellcheck disable=SC1090
        source "$_cand"; break
    fi
done

# Load persistent non-secret deploy config so the reported base/repo match the
# configured values regardless of the caller's working directory.
zpd_load_deploy_env

ds_report() {
    local current manifest link_target
    current="$(zpd_current_release)"
    manifest="$(zpd_releases_dir)/${current}/RELEASE_MANIFEST.json"

    local sha ref repo result mig health prev
    sha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
    ref="$(zpd_manifest_get "$manifest" git_ref 2>/dev/null)"
    repo="$(zpd_manifest_get "$manifest" repo_url 2>/dev/null)"
    result="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
    mig="$(zpd_manifest_get "$manifest" migration_status 2>/dev/null)"
    health="$(zpd_manifest_get "$manifest" health 2>/dev/null)"
    prev="$(zpd_previous_release)"
    link_target="$(readlink "$(zpd_current_link)" 2>/dev/null || echo '<none>')"

    if [ "${1:-}" = "--json" ]; then
        zpd_write_manifest /dev/stdout \
            "active_release=${current:-none}" "git_sha=${sha}" "git_ref=${ref}" \
            "repo_url=${repo}" "current_link=${link_target}" \
            "previous_release=${prev}" "result=${result}" \
            "migration_status=${mig}" "health=${health}"
        return 0
    fi

    echo "ZedProxy deployment status"
    echo "  Base:             $(zpd_base)"
    echo "  Active release:   ${current:-<none>}"
    echo "  current ->        ${link_target}"
    echo "  Git SHA:          ${sha:-<unknown>}"
    echo "  Git ref:          ${ref:-<unknown>}"
    echo "  Repository:       ${repo:-<unknown>}"
    echo "  Previous release: ${prev:-<none>}"
    echo "  Last result:      ${result:-<unknown>}"
    echo "  Migrations:       ${mig:-<unknown>}"
    echo "  Health:           ${health:-<unknown>}"
    echo ""
    echo "  Releases (newest first):"
    local rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        local r; r="$(zpd_manifest_get "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" result 2>/dev/null)"
        local marker="  "; [ "$rel" = "$current" ] && marker="* "
        echo "    ${marker}${rel} (${r:-unknown})"
    done < <(zpd_list_releases)
}

if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -Euo pipefail
    ds_report "$@"
fi
