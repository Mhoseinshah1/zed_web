#!/usr/bin/env bash
# =============================================================================
# zedproxy-deploy-repair — explicit, safe repair of deployment state.
#
#   zedproxy-deploy-repair --scan            # READ-ONLY: report what would change
#   zedproxy-deploy-repair --apply           # back up + reconcile everything
#   zedproxy-deploy-repair --apply --scheduler
#   zedproxy-deploy-repair --apply --manifests
#   zedproxy-deploy-repair --apply --state
#
# Component flags (--scheduler / --manifests / --state / --operational) limit
# the repair scope; with no component flag every component is covered. Without
# --apply the command is ALWAYS read-only (--scan is the default).
#
# --apply:
#   * backs up every file it modifies (per-run snapshot under shared/deploy/
#     snapshots/repair-<timestamp>/)
#   * reconciles Scheduler, Supervisor, Nginx, wrappers, local health vhost,
#     release manifests (adoption + stale-activating finalization), and the
#     central state file
#   * NEVER runs migrations, NEVER touches .env, database credentials, or
#     APP_KEY, and NEVER switches code releases
#   * verifies site + worker health after the repair
#
# Source-safe: sourcing only defines functions (zrp_*).
# =============================================================================

_ZRP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
if ! declare -F dep_reconcile_operational >/dev/null 2>&1; then
    # shellcheck disable=SC1090
    [ -f "${_ZRP_DIR}/deploy.sh" ] && source "${_ZRP_DIR}/deploy.sh"
fi

# ── Scan (read-only) ─────────────────────────────────────────────────────────

# zrp_scan_operational — report operational drift without changing anything.
zrp_scan_operational() {
    local base issues=0; base="$(zpd_base)"
    if zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base"; then
        echo "  ok   nginx root serves current/public"
    else
        echo "  FIX  nginx root is not on current/public"; issues=$((issues + 1))
    fi
    if zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base"; then
        echo "  ok   supervisor worker runs current/artisan"
    else
        echo "  FIX  supervisor config missing or not on current/"; issues=$((issues + 1))
    fi
    if zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base"; then
        echo "  ok   scheduler cron is canonical ($(zpd_scheduler_cron_path))"
    else
        echo "  FIX  scheduler cron missing/legacy/duplicated"; issues=$((issues + 1))
    fi
    local entry kind src
    while IFS= read -r entry; do
        [ -n "$entry" ] || continue
        kind="${entry%% *}"; src="${entry#* }"
        if [ "$kind" = "OURS" ]; then
            case "$src" in
                "$(zpd_cron_spool_dir)"/*)
                    echo "  CONFLICT ZedProxy scheduler entry in user crontab: ${src} (manual review required)"
                    issues=$((issues + 1)) ;;
                *)
                    echo "  FIX  duplicate ZedProxy scheduler entry in ${src}"; issues=$((issues + 1)) ;;
            esac
        fi
    done < <(dep_scheduler_scan "$base")
    if declare -F zpw_verify_wrappers >/dev/null 2>&1 && zpw_verify_wrappers "$base" 2>/dev/null; then
        echo "  ok   command wrappers resolve current/"
    else
        echo "  FIX  command wrappers missing or stale"; issues=$((issues + 1))
    fi
    if zpd_local_health_conf_ok "$(zpd_local_health_conf_path)" "$base"; then
        echo "  ok   loopback health vhost is correct"
    else
        echo "  FIX  loopback health vhost missing or not loopback-only"; issues=$((issues + 1))
    fi
    return "$issues"
}

# zrp_scan_manifests — report manifest drift (adoption + stale activating).
zrp_scan_manifests() {
    local issues=0 cur rel res
    cur="$(zpd_current_release)"
    if [ -n "$cur" ]; then
        case "$(dep_release_verify_mode "$cur")" in
            historical) echo "  FIX  active release ${cur} has no usable manifest (adoptable)"; issues=$((issues + 1)) ;;
            adopted)    echo "  ok   active release ${cur} already adopted" ;;
            strict)     echo "  ok   active release ${cur} has a modern manifest" ;;
        esac
    fi
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        [ "$rel" = "$cur" ] && continue
        res="$(zpd_manifest_get "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" result 2>/dev/null)"
        if [ "$res" = "activating" ]; then
            echo "  FIX  stale release ${rel} still marked 'activating'"; issues=$((issues + 1))
        fi
    done < <(zpd_list_releases)
    return "$issues"
}

# zrp_scan_state — report central state-file drift.
zrp_scan_state() {
    local cur state_active
    cur="$(zpd_current_release)"
    if [ ! -f "$(zpd_state_file)" ]; then
        echo "  FIX  state file missing ($(zpd_state_file))"; return 1
    fi
    state_active="$(zpd_manifest_get "$(zpd_state_file)" active_release 2>/dev/null)"
    if [ -n "$cur" ] && [ "$state_active" != "$cur" ]; then
        echo "  FIX  state file records '${state_active:-<none>}' but current is '${cur}'"; return 1
    fi
    echo "  ok   state file matches the current symlink"
    return 0
}

# ── Apply ────────────────────────────────────────────────────────────────────

zrp_apply() {
    local do_op="$1" do_man="$2" do_state="$3"
    local base rc=0; base="$(zpd_base)"

    # Per-run backup of every managed file (never overwrites another snapshot).
    local snap
    snap="$(dep_snapshot_operational_config "repair-$(date -u +%Y%m%d%H%M%S)")" \
        || { echo "could not create repair backup snapshot" >&2; return 1; }
    echo "backup snapshot: ${snap}"

    if [ "$do_man" = "1" ]; then
        dep_adopt_current_release   || rc=1
        dep_finalize_stale_releases || rc=1
    fi
    if [ "$do_op" = "1" ]; then
        dep_reconcile_nginx "$base"        || { echo "nginx reconcile failed" >&2; rc=1; }
        dep_reconcile_supervisor "$base"   || { echo "supervisor reconcile failed" >&2; rc=1; }
        dep_reconcile_scheduler "$base"    || { echo "scheduler reconcile failed" >&2; rc=1; }
        dep_reconcile_wrappers "$base"     || { echo "wrapper reconcile failed" >&2; rc=1; }
        dep_ensure_local_health "$base"    || { echo "local-health reconcile failed" >&2; rc=1; }
        dep_reload_nginx                   || echo "nginx reload failed" >&2
    fi
    if [ "$do_state" = "1" ]; then
        dep_reconcile_state || { echo "state repair failed" >&2; rc=1; }
    fi

    # Post-repair verification (never claim success on a broken site).
    if [ "$do_op" = "1" ]; then
        dep_verify_operational_config "$base" || { echo "post-repair verification failed" >&2; rc=1; }
        dep_supervisor_group_running >/dev/null 2>&1 || echo "WARN: worker group not fully RUNNING" >&2
        if [ -L "$(zpd_current_link)" ]; then
            dep_cli_health "$(zpd_current_link)" >/dev/null 2>&1 || { echo "post-repair CLI health failed" >&2; rc=1; }
        fi
        local code; code="$(dep_http_code "${ZPD_HEALTH_URL}/health")"
        [ "$code" = "200" ] || echo "WARN: /health returned ${code} after repair" >&2
    fi

    if [ "$rc" -eq 0 ]; then
        echo "$(zpd_msg_repair_done)"
    else
        echo "repair finished with unresolved issues (backup: ${snap})" >&2
    fi
    return "$rc"
}

zrp_main() {
    local apply=0 comp_op=0 comp_man=0 comp_state=0 any_comp=0 arg
    for arg in "$@"; do
        case "$arg" in
            --scan)  apply=0 ;;
            --apply) apply=1 ;;
            --scheduler|--operational) comp_op=1; any_comp=1 ;;
            --manifests) comp_man=1; any_comp=1 ;;
            --state)     comp_state=1; any_comp=1 ;;
            *) echo "usage: zedproxy-deploy-repair [--scan|--apply] [--scheduler] [--manifests] [--state]" >&2; return 2 ;;
        esac
    done
    # No component flag → all components.
    if [ "$any_comp" -eq 0 ]; then comp_op=1; comp_man=1; comp_state=1; fi

    if [ "$apply" -eq 0 ]; then
        # READ-ONLY scan.
        local issues=0
        echo "ZedProxy deploy-repair scan (read-only)"
        if [ "$comp_op" = "1" ];    then echo "operational configuration:"; zrp_scan_operational || issues=$((issues + $?)); fi
        if [ "$comp_man" = "1" ];   then echo "release manifests:";        zrp_scan_manifests   || issues=$((issues + $?)); fi
        if [ "$comp_state" = "1" ]; then echo "deployment state:";         zrp_scan_state       || issues=$((issues + $?)); fi
        echo ""
        if [ "$issues" -eq 0 ]; then
            echo "no repairable inconsistencies found"
        else
            echo "${issues} repairable item(s) — run: zedproxy-deploy-repair --apply"
        fi
        return 0
    fi

    zrp_apply "$comp_op" "$comp_man" "$comp_state"
}

if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -uo pipefail
    zrp_main "$@"
fi
