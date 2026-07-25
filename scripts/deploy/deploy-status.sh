#!/usr/bin/env bash
# =============================================================================
# ZedProxy deployment status — report the active/previous release, last result,
# migration + health state, and list known releases. Read-only.
#
#   bash scripts/deploy/deploy-status.sh [--json]
#
# Remains useful even when the state file or the active release's manifest is
# missing/incomplete (historical installs): fields fall back to OBSERVED git
# facts (marked "observed") and incomplete historical metadata produces explicit
# warnings instead of a wall of silent "<unknown>".
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

# ds_result_label RESULT — normalize a manifest result to the documented set:
#   success | failed | activating | adopted | recovered | unknown
ds_result_label() {
    case "${1:-}" in
        success|failed|activating|adopted|recovered) printf '%s' "$1" ;;
        rolled_back_manual) printf 'success' ;;
        '') printf 'unknown' ;;
        *)  printf '%s' "$1" ;;
    esac
}

ds_report() {
    local current manifest link_target
    current="$(zpd_current_release)"
    manifest="$(zpd_releases_dir)/${current}/RELEASE_MANIFEST.json"

    local sha ref repo result mig health prev
    local sha_src="manifest" warn_hist=0
    sha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
    ref="$(zpd_manifest_get "$manifest" git_ref 2>/dev/null)"
    repo="$(zpd_manifest_get "$manifest" repo_url 2>/dev/null)"
    result="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
    mig="$(zpd_manifest_get "$manifest" migration_status 2>/dev/null)"
    health="$(zpd_manifest_get "$manifest" health 2>/dev/null)"
    prev="$(zpd_previous_release)"
    link_target="$(readlink "$(zpd_current_link)" 2>/dev/null || echo '<none>')"

    # ── Fallback to OBSERVED facts for historical/incomplete metadata ────────
    if [ -n "$current" ]; then
        if [ -z "$sha" ] || [ "$sha" = "unknown" ]; then
            local observed
            observed="$("${ZPD_GIT:-git}" -C "$(zpd_releases_dir)/${current}" rev-parse HEAD 2>/dev/null || true)"
            if [ -n "$observed" ]; then sha="$observed"; sha_src="observed"; warn_hist=1; fi
        fi
        if [ -z "$repo" ] || [ "$repo" = "unknown" ]; then
            local origin
            origin="$(zpd_git_origin_of "$(zpd_releases_dir)/${current}" 2>/dev/null || true)"
            # An observed origin may be an AUTHENTICATED URL — mask credentials
            # before it can reach a terminal or a captured support log.
            if [ -n "$origin" ]; then repo="$(printf '%s' "$origin" | zpd_mask_secrets)"; warn_hist=1; fi
        fi
        if [ -z "$result" ]; then
            # No manifest at all → the release predates the manifest system.
            result="recovered"; warn_hist=1
        fi
    fi
    result="$(ds_result_label "$result")"

    if [ "${1:-}" = "--json" ]; then
        # Direct stdout serialization — the manifest FILE writer must never target
        # /dev/stdout (its atomic rename would replace the symlink as root).
        zpd_print_manifest \
            "active_release=${current:-none}" "git_sha=${sha:-unknown}" "git_sha_source=${sha_src}" \
            "git_ref=${ref:-unknown}" "repo_url=${repo:-unknown}" "current_link=${link_target}" \
            "previous_release=${prev:-none}" "result=${result}" \
            "migration_status=${mig:-unknown}" "health=${health:-unknown}" \
            "historical_metadata_incomplete=${warn_hist}"
        return 0
    fi

    echo "ZedProxy deployment status"
    echo "  Base:             $(zpd_base)"
    echo "  Active release:   ${current:-<none>}"
    echo "  current ->        ${link_target}"
    if [ "$sha_src" = "observed" ]; then
        echo "  Git SHA:          ${sha:-<unknown>} (observed from the release checkout)"
    else
        echo "  Git SHA:          ${sha:-<unknown>}"
    fi
    echo "  Git ref:          ${ref:-<unknown>}"
    echo "  Repository:       ${repo:-<unknown>}"
    echo "  Previous release: ${prev:-<none>}"
    echo "  Last result:      ${result}"
    echo "  Migrations:       ${mig:-<unknown>}"
    echo "  Health:           ${health:-<unknown>}"
    if [ "$warn_hist" = "1" ]; then
        echo ""
        echo "  WARNING: the active release predates the manifest system or its"
        echo "  metadata is incomplete. Fields marked (observed) were read from"
        echo "  the release checkout. Run 'zedproxy-deploy-repair --scan' to see"
        echo "  what a repair would backfill, or the next update will adopt it."
    fi
    echo ""
    echo "  Releases (newest first):"
    local rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        local r; r="$(zpd_manifest_get "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" result 2>/dev/null)"
        local marker="  "; [ "$rel" = "$current" ] && marker="* "
        echo "    ${marker}${rel} ($(ds_result_label "$r"))"
    done < <(zpd_list_releases)
}

if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -Euo pipefail
    ds_report "$@"
fi
