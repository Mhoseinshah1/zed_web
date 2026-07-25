#!/usr/bin/env bash
# =============================================================================
# zedproxy-deploy-repair — explicit, safe, FAIL-CLOSED repair of deployment state.
#
#   zedproxy-deploy-repair --scan            # READ-ONLY: report what would change
#   zedproxy-deploy-repair --apply           # back up + reconcile everything
#   zedproxy-deploy-repair --apply --scheduler   # scheduler ONLY
#   zedproxy-deploy-repair --apply --operational # nginx/supervisor/wrappers/health/scheduler
#   zedproxy-deploy-repair --apply --manifests   # adoption + stale finalization only
#   zedproxy-deploy-repair --apply --state       # central state file only
#
# Component flags are EXACT: `--scheduler` touches only the scheduler (plus its
# required verification); `--operational` is the full operational group. With no
# component flag every component is covered. Without --apply the command is
# ALWAYS read-only (--scan is the default).
#
# --apply is FAIL-CLOSED and SERIALIZED:
#   * acquires the SAME deployment lock as zedproxy-update / zedproxy-rollback
#     and refuses to run concurrently with them (busy → non-zero)
#   * snapshots every potentially modified resource first — operational config,
#     scheduler sources, wrappers, release manifests, and the state file
#   * stops on the FIRST required failure, automatically restores the complete
#     snapshot, validates the restored state, and exits non-zero
#   * required (never warnings): Nginx validation + reload, Supervisor
#     reread/update, worker group RUNNING, canonical Scheduler verification,
#     schedule:list, CLI health, HTTP /health and /health/live
#   * records both the repair failure and the restore result
#     (shared/deploy/last-repair.json)
#   * NEVER runs migrations, NEVER touches .env / database credentials /
#     APP_KEY, and NEVER switches code releases
#   * the success message is printed ONLY when every requested step and check
#     passed
#
# Source-safe: sourcing only defines functions (zrp_*).
# =============================================================================

_ZRP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
if ! declare -F dep_reconcile_operational >/dev/null 2>&1; then
    # shellcheck disable=SC1090
    [ -f "${_ZRP_DIR}/deploy.sh" ] && source "${_ZRP_DIR}/deploy.sh"
fi

zrp_last_repair_file() { printf '%s/deploy/last-repair.json' "$(zpd_shared_dir)"; }

# ── Scan (read-only) ─────────────────────────────────────────────────────────

# zrp_scan_scheduler — report scheduler drift only (read-only).
zrp_scan_scheduler() {
    local base issues=0 entry kind src; base="$(zpd_base)"
    if zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base"; then
        echo "  ok   scheduler cron is canonical ($(zpd_scheduler_cron_path))"
    else
        echo "  FIX  scheduler cron missing/legacy/duplicated"; issues=$((issues + 1))
    fi
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
    return "$issues"
}

# zrp_scan_operational — report non-scheduler operational drift (read-only).
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
    if [ -n "$cur" ] && [ -z "$state_active" ]; then
        echo "  FIX  state file records no active release (current is '${cur}')"; return 1
    fi
    if [ -n "$cur" ] && [ "$state_active" != "$cur" ]; then
        echo "  FIX  state file records '${state_active:-<none>}' but current is '${cur}'"; return 1
    fi
    echo "  ok   state file matches the current symlink"
    return 0
}

# ── Apply (fail-closed) ──────────────────────────────────────────────────────

# zrp_backup_metadata SNAP — copy the state file and EVERY release manifest into
# the snapshot so manifest/state repair is recoverable too.
zrp_backup_metadata() {
    local snap="$1" rel m
    mkdir -p "${snap}/meta" 2>/dev/null || return 1
    chmod 700 "${snap}/meta" 2>/dev/null || true
    if [ -f "$(zpd_state_file)" ]; then
        cp -a "$(zpd_state_file)" "${snap}/meta/state.json" 2>/dev/null || return 1
    fi
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        m="$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json"
        [ -f "$m" ] && { cp -a "$m" "${snap}/meta/manifest-${rel}.json" 2>/dev/null || return 1; }
    done < <(zpd_list_releases)
    return 0
}

# zrp_restore_metadata SNAP — put the snapshotted state file + manifests back.
zrp_restore_metadata() {
    local snap="$1" f rel
    [ -d "${snap}/meta" ] || return 0
    if [ -f "${snap}/meta/state.json" ]; then
        cp -a "${snap}/meta/state.json" "$(zpd_state_file)" 2>/dev/null || return 1
    fi
    for f in "${snap}/meta"/manifest-*.json; do
        [ -f "$f" ] || continue
        rel="$(basename "$f")"; rel="${rel#manifest-}"; rel="${rel%.json}"
        [ -d "$(zpd_releases_dir)/${rel}" ] \
            && { cp -a "$f" "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" 2>/dev/null || return 1; }
    done
    return 0
}

# _zrp_apply_steps BASE DO_OP DO_SCHED DO_MAN DO_STATE — run the requested
# repair steps, stopping on the FIRST required failure. Sets ZRP_FAILED_STEP.
ZRP_FAILED_STEP=""
_zrp_apply_steps() {
    local base="$1" do_op="$2" do_sched="$3" do_man="$4" do_state="$5"
    ZRP_FAILED_STEP=""

    if [ "$do_man" = "1" ]; then
        dep_adopt_current_release   || { ZRP_FAILED_STEP="adoption"; return 1; }
        dep_finalize_stale_releases || { ZRP_FAILED_STEP="stale_finalization"; return 1; }
    fi

    if [ "$do_op" = "1" ]; then
        dep_reconcile_nginx "$base"      || { ZRP_FAILED_STEP="nginx_reconcile"; return 1; }
        dep_reconcile_supervisor "$base" || { ZRP_FAILED_STEP="supervisor_reconcile"; return 1; }
    fi
    if [ "$do_op" = "1" ] || [ "$do_sched" = "1" ]; then
        dep_reconcile_scheduler "$base"  || { ZRP_FAILED_STEP="scheduler_reconcile"; return 1; }
    fi
    if [ "$do_op" = "1" ]; then
        dep_reconcile_wrappers "$base"   || { ZRP_FAILED_STEP="wrapper_reconcile"; return 1; }
        dep_ensure_local_health "$base"  || { ZRP_FAILED_STEP="local_health_reconcile"; return 1; }
        dep_validate_nginx               || { ZRP_FAILED_STEP="nginx_validate"; return 1; }
        dep_reload_nginx                 || { ZRP_FAILED_STEP="nginx_reload"; return 1; }
        dep_verify_operational_config "$base" || { ZRP_FAILED_STEP="operational_verify"; return 1; }
        dep_supervisor_group_running >/dev/null 2>&1 \
                                         || { ZRP_FAILED_STEP="workers_running"; return 1; }
    fi

    # Scheduler verification is REQUIRED whenever the scheduler was in scope.
    if [ "$do_op" = "1" ] || [ "$do_sched" = "1" ]; then
        zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" \
            || { ZRP_FAILED_STEP="scheduler_verify"; return 1; }
        if [ -L "$(zpd_current_link)" ]; then
            dep_verify_scheduler "$(zpd_current_link)" \
                || { ZRP_FAILED_STEP="schedule_list"; return 1; }
        fi
    fi

    if [ "$do_state" = "1" ]; then
        dep_reconcile_state || { ZRP_FAILED_STEP="state_repair"; return 1; }
    fi

    # Runtime health gates — REQUIRED whenever anything operational was touched.
    if [ "$do_op" = "1" ] || [ "$do_sched" = "1" ]; then
        if [ -L "$(zpd_current_link)" ]; then
            dep_cli_health "$(zpd_current_link)" >/dev/null 2>&1 \
                || { ZRP_FAILED_STEP="cli_health"; return 1; }
        fi
        [ "$(dep_http_code "${ZPD_HEALTH_URL}/health")" = "200" ] \
            || { ZRP_FAILED_STEP="http_health"; return 1; }
        [ "$(dep_http_code "${ZPD_HEALTH_URL}/health/live")" = "200" ] \
            || { ZRP_FAILED_STEP="http_live"; return 1; }
    fi
    return 0
}

# zrp_apply DO_OP DO_SCHED DO_MAN DO_STATE — snapshot, run the steps
# fail-closed, restore + validate on failure, and record the outcome.
zrp_apply() {
    local do_op="$1" do_sched="$2" do_man="$3" do_state="$4"
    local base snap; base="$(zpd_base)"

    # Snapshot EVERY potentially modified resource (operational config,
    # scheduler sources, wrappers via dep_snapshot_operational_config; release
    # manifests + state file via zrp_backup_metadata) BEFORE changing anything.
    snap="$(dep_snapshot_operational_config "repair-$(date -u +%Y%m%d%H%M%S)")" \
        || { echo "could not create repair backup snapshot" >&2; return 1; }
    zrp_backup_metadata "$snap" \
        || { echo "could not back up manifests/state into ${snap}" >&2; return 1; }
    echo "backup snapshot: ${snap}"

    if _zrp_apply_steps "$base" "$do_op" "$do_sched" "$do_man" "$do_state"; then
        zpd_write_manifest "$(zrp_last_repair_file)" \
            "result=success" "snapshot=${snap}" \
            "components=op:${do_op},sched:${do_sched},man:${do_man},state:${do_state}" \
            "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "$(zpd_msg_repair_done)"
        return 0
    fi

    # ── FAIL-CLOSED: restore the complete snapshot, validate, record, exit ≠0.
    local failed_step="$ZRP_FAILED_STEP" restore_result="success"
    echo "repair failed at step: ${failed_step} — restoring the pre-repair snapshot" >&2
    dep_restore_operational_snapshot "$snap" || restore_result="failed"
    zrp_restore_metadata "$snap"             || restore_result="failed"
    zpd_write_manifest "$(zrp_last_repair_file)" \
        "result=failed" "failed_step=${failed_step}" \
        "restore_result=${restore_result}" "snapshot=${snap}" \
        "components=op:${do_op},sched:${do_sched},man:${do_man},state:${do_state}" \
        "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if [ "$restore_result" = "success" ]; then
        echo "pre-repair state restored and validated (snapshot kept: ${snap})" >&2
    else
        echo "RESTORE INCOMPLETE — review the snapshot manually: ${snap}" >&2
    fi
    return 1
}

zrp_main() {
    local apply=0 comp_op=0 comp_sched=0 comp_man=0 comp_state=0 any_comp=0 arg
    for arg in "$@"; do
        case "$arg" in
            --scan)  apply=0 ;;
            --apply) apply=1 ;;
            --operational) comp_op=1; any_comp=1 ;;
            --scheduler)   comp_sched=1; any_comp=1 ;;
            --manifests)   comp_man=1; any_comp=1 ;;
            --state)       comp_state=1; any_comp=1 ;;
            *) echo "usage: zedproxy-deploy-repair [--scan|--apply] [--operational] [--scheduler] [--manifests] [--state]" >&2; return 2 ;;
        esac
    done
    # No component flag → all components (operational already covers scheduler).
    if [ "$any_comp" -eq 0 ]; then comp_op=1; comp_man=1; comp_state=1; fi

    if [ "$apply" -eq 0 ]; then
        # READ-ONLY scan.
        local issues=0
        echo "ZedProxy deploy-repair scan (read-only)"
        if [ "$comp_op" = "1" ];    then echo "operational configuration:"; zrp_scan_operational || issues=$((issues + $?)); fi
        if [ "$comp_op" = "1" ] || [ "$comp_sched" = "1" ]; then echo "scheduler:"; zrp_scan_scheduler || issues=$((issues + $?)); fi
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

    # --apply runs under the SAME deployment lock as zedproxy-update /
    # zedproxy-rollback: concurrent execution is refused, never interleaved.
    zpd_run_locked "$(zpd_lock_file)" -- zrp_apply "$comp_op" "$comp_sched" "$comp_man" "$comp_state"
    local rc=$?
    if [ "$rc" -eq 200 ]; then
        echo "$(zpd_lock_busy_message)" >&2
        return 200
    fi
    return "$rc"
}

if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -uo pipefail
    zrp_main "$@"
fi
