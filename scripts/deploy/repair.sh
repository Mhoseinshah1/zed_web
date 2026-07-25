#!/usr/bin/env bash
# =============================================================================
# zedproxy-deploy-repair — explicit, safe, FAIL-CLOSED repair of deployment state.
#
#   zedproxy-deploy-repair --scan            # READ-ONLY: report what would change
#   zedproxy-deploy-repair --apply           # back up + reconcile everything
#   zedproxy-deploy-repair --apply <flags>   # exact component scoping
#
# Component flags (each individual flag modifies ONLY its named component):
#   --nginx         public Nginx root
#   --supervisor    worker program config + daemon reload
#   --scheduler     canonical scheduler cron (+ source canonicalization)
#   --wrappers      zedproxy-* command wrappers
#   --health-vhost  loopback health vhost
#   --manifests     historical adoption + stale-activating finalization
#   --state         central state file
#   --operational   the full operational group (nginx+supervisor+scheduler+
#                   wrappers+health-vhost)
# No component flag means ALL components. Without --apply the command is ALWAYS
# read-only (--scan is the default).
#
# --apply is FAIL-CLOSED and SERIALIZED:
#   * acquires the SAME deployment lock as zedproxy-update / zedproxy-rollback
#     and refuses to run concurrently with them (busy → non-zero)
#   * snapshots every potentially modified resource first — operational config,
#     scheduler sources, wrappers, release manifests (absence recorded too),
#     and the state file
#   * stops on the FIRST required failure, automatically restores the snapshot
#     FOR THE COMPONENTS THAT WERE IN SCOPE (a metadata-only repair never
#     touches live services on restore), validates it, and exits non-zero
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
    while IFS= read -r entry; do
        [ -n "$entry" ] || continue
        echo "  CONFLICT unmanaged scheduler source: ${entry} (manual review required)"
        issues=$((issues + 1))
    done < <(dep_scheduler_scan_noncron "$base")
    return "$issues"
}

zrp_scan_nginx() {
    local base="$(zpd_base)" issues=0
    if zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base"; then
        echo "  ok   nginx root serves current/public"
    else
        echo "  FIX  nginx root is not on current/public"; issues=$((issues + 1))
    fi
    if zpd_nginx_robots_ok "$(zpd_nginx_conf_path)"; then
        echo "  ok   nginx robots.txt location reaches Laravel"
    else
        echo "  FIX  nginx robots.txt location lacks the Laravel fallback (dynamic robots.txt would 404)"; issues=$((issues + 1))
    fi
    return "$issues"
}

zrp_scan_supervisor() {
    local base="$(zpd_base)"
    if zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base"; then
        echo "  ok   supervisor worker runs current/artisan"; return 0
    fi
    echo "  FIX  supervisor config missing or not on current/"; return 1
}

zrp_scan_wrappers() {
    local base="$(zpd_base)"
    if declare -F zpw_verify_wrappers >/dev/null 2>&1 && zpw_verify_wrappers "$base" 2>/dev/null; then
        echo "  ok   command wrappers resolve current/"; return 0
    fi
    echo "  FIX  command wrappers missing or stale"; return 1
}

zrp_scan_health_vhost() {
    local base="$(zpd_base)"
    if zpd_local_health_conf_ok "$(zpd_local_health_conf_path)" "$base"; then
        echo "  ok   loopback health vhost is correct"; return 0
    fi
    echo "  FIX  loopback health vhost missing or not loopback-only"; return 1
}

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

zrp_scan_state() {
    local cur state_active
    cur="$(zpd_current_release)"
    if [ ! -f "$(zpd_state_file)" ]; then
        echo "  FIX  state file missing ($(zpd_state_file))"; return 1
    fi
    state_active="$(zpd_manifest_get "$(zpd_state_file)" active_release 2>/dev/null)"
    # A missing `current` with a state file still naming an active release is
    # STALE state, not consistency.
    if [ -z "$cur" ] && [ -n "$state_active" ] && [ "$state_active" != "none" ]; then
        echo "  FIX  no current symlink but state records active '${state_active}'"; return 1
    fi
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
# the snapshot. The state file's EXISTENCE is recorded explicitly (with its
# exact mode + ownership when present) so a failed repair can either restore
# the original bytes or REMOVE a state file the repair newly created — never
# leave half-written state behind. Releases WITHOUT a manifest are recorded in
# an absence list so a failed repair also removes any manifest it newly created
# (e.g. by adoption). NOTE: last-repair.json is deliberately NOT part of this
# transaction — it is the audit record OF the repair attempt itself and must
# survive a rolled-back repair.
zrp_backup_metadata() {
    local snap="$1" rel m st
    st="$(zpd_state_file)"
    mkdir -p "${snap}/meta" 2>/dev/null || return 1
    chmod 700 "${snap}/meta" 2>/dev/null || true
    : > "${snap}/meta/absent.list" || return 1
    if [ -f "$st" ]; then
        printf '1\n' > "${snap}/meta/state.existed" || return 1
        # A symlinked state path keeps its TYPE: record the link target so the
        # restore recreates the symlink instead of flattening it to a regular
        # file (content still restored through to the target).
        if [ -L "$st" ]; then
            readlink "$st" > "${snap}/meta/state.link" 2>/dev/null || return 1
        else
            rm -f "${snap}/meta/state.link" 2>/dev/null || true
        fi
        cp "$st" "${snap}/meta/state.json" 2>/dev/null || return 1
        cmp -s "$st" "${snap}/meta/state.json" || return 1
        stat -c '%a %u %g' "$st" > "${snap}/meta/state.stat" 2>/dev/null || return 1
    else
        printf '0\n' > "${snap}/meta/state.existed" || return 1
    fi
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        m="$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json"
        if [ -f "$m" ]; then
            cp -a "$m" "${snap}/meta/manifest-${rel}.json" 2>/dev/null || return 1
            cmp -s "$m" "${snap}/meta/manifest-${rel}.json" || return 1
        else
            printf '%s\n' "$rel" >> "${snap}/meta/absent.list" || return 1
        fi
    done < <(zpd_list_releases)
    return 0
}

# zrp_restore_metadata SNAP — put the snapshotted state file + manifests back
# EXACTLY (bytes verified with cmp, state mode/ownership restored to the
# recorded values), REMOVE a state file or manifests that did not exist before
# the repair (absence verified), and accumulate EVERY copy/remove/chmod/chown
# failure into the return code. last-repair.json is intentionally untouched —
# it is the audit record of the attempt, not repaired state.
zrp_restore_metadata() {
    local snap="$1" f rel rc=0 st existed mode uid gid
    st="$(zpd_state_file)"
    [ -d "${snap}/meta" ] || return 0
    existed="$(cat "${snap}/meta/state.existed" 2>/dev/null || echo "")"
    if [ "$existed" = "0" ]; then
        # The state file did not exist before the repair — anything there now
        # was created by the failed transaction and must be removed.
        rm -f "$st" 2>/dev/null || rc=1
        [ -e "$st" ] && { echo "restore: ${st} still exists (created by the failed repair)" >&2; rc=1; }
    elif [ -f "${snap}/meta/state.json" ]; then
        if [ -f "${snap}/meta/state.link" ]; then
            # Recreate the ORIGINAL symlink type/target, then restore content
            # through it to the target.
            rm -f "$st" 2>/dev/null || rc=1
            ln -s "$(cat "${snap}/meta/state.link")" "$st" 2>/dev/null \
                || { echo "restore: could not recreate the state symlink" >&2; rc=1; }
            [ "$(readlink "$st" 2>/dev/null)" = "$(cat "${snap}/meta/state.link")" ] \
                || { echo "restore: state symlink target differs from the recorded value" >&2; rc=1; }
        fi
        if ! cp "${snap}/meta/state.json" "$st" 2>/dev/null || ! cmp -s "${snap}/meta/state.json" "$st"; then
            echo "restore: state file was not restored exactly" >&2; rc=1
        else
            read -r mode uid gid < "${snap}/meta/state.stat" 2>/dev/null || { mode=""; uid=""; gid=""; }
            if [ -n "$mode" ]; then
                chmod "$mode" "$st" 2>/dev/null || { echo "restore: chmod state file failed" >&2; rc=1; }
                chown "${uid}:${gid}" "$st" 2>/dev/null || { echo "restore: chown state file failed" >&2; rc=1; }
                [ "$(stat -c '%a %u %g' "$st" 2>/dev/null)" = "${mode} ${uid} ${gid}" ] \
                    || { echo "restore: state file mode/ownership differ from the recorded values" >&2; rc=1; }
            fi
        fi
    fi
    for f in "${snap}/meta"/manifest-*.json; do
        [ -f "$f" ] || continue
        rel="$(basename "$f")"; rel="${rel#manifest-}"; rel="${rel%.json}"
        if [ -d "$(zpd_releases_dir)/${rel}" ]; then
            if ! cp -a "$f" "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" 2>/dev/null \
               || ! cmp -s "$f" "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json"; then
                echo "restore: manifest for ${rel} was not restored exactly" >&2; rc=1
            fi
        fi
    done
    if [ -f "${snap}/meta/absent.list" ]; then
        while IFS= read -r rel; do
            [ -n "$rel" ] || continue
            m="$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json"
            rm -f "$m" 2>/dev/null || rc=1
            [ -e "$m" ] && { echo "restore: ${m} still exists (created by the failed repair)" >&2; rc=1; }
        done < "${snap}/meta/absent.list"
    fi
    return "$rc"
}

# _zrp_apply_steps BASE — run the requested repair steps (component booleans in
# the ZRP_C_* globals), stopping on the FIRST required failure.
# Sets ZRP_FAILED_STEP.
ZRP_FAILED_STEP=""
_zrp_apply_steps() {
    local base="$1"
    ZRP_FAILED_STEP=""

    if [ "$ZRP_C_MAN" = "1" ]; then
        dep_adopt_current_release   || { ZRP_FAILED_STEP="adoption"; return 1; }
        # A manifest repair that leaves the active release with NO usable
        # manifest did not repair anything — surface it instead of "success".
        local cur; cur="$(zpd_current_release)"
        if [ -n "$cur" ] && [ "$(dep_release_verify_mode "$cur")" = "historical" ]; then
            ZRP_FAILED_STEP="adoption_incomplete"; return 1
        fi
        dep_finalize_stale_releases || { ZRP_FAILED_STEP="stale_finalization"; return 1; }
    fi

    [ "$ZRP_C_NGINX" = "1" ] && { dep_reconcile_nginx "$base"      || { ZRP_FAILED_STEP="nginx_reconcile"; return 1; }; }
    [ "$ZRP_C_SUPER" = "1" ] && { dep_reconcile_supervisor "$base" || { ZRP_FAILED_STEP="supervisor_reconcile"; return 1; }; }
    [ "$ZRP_C_SCHED" = "1" ] && { dep_reconcile_scheduler "$base"  || { ZRP_FAILED_STEP="scheduler_reconcile"; return 1; }; }
    [ "$ZRP_C_WRAP"  = "1" ] && { dep_reconcile_wrappers "$base"   || { ZRP_FAILED_STEP="wrapper_reconcile"; return 1; }; }
    [ "$ZRP_C_HV"    = "1" ] && { dep_ensure_local_health "$base"  || { ZRP_FAILED_STEP="local_health_reconcile"; return 1; }; }

    # Per-component REQUIRED verification.
    if [ "$ZRP_C_NGINX" = "1" ] || [ "$ZRP_C_HV" = "1" ]; then
        dep_validate_nginx || { ZRP_FAILED_STEP="nginx_validate"; return 1; }
        dep_reload_nginx   || { ZRP_FAILED_STEP="nginx_reload"; return 1; }
    fi
    if [ "$ZRP_C_NGINX" = "1" ]; then
        zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base" || { ZRP_FAILED_STEP="nginx_verify"; return 1; }
    fi
    if [ "$ZRP_C_SUPER" = "1" ]; then
        zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base" || { ZRP_FAILED_STEP="supervisor_verify"; return 1; }
        dep_supervisor_group_running >/dev/null 2>&1            || { ZRP_FAILED_STEP="workers_running"; return 1; }
    fi
    if [ "$ZRP_C_SCHED" = "1" ]; then
        zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" || { ZRP_FAILED_STEP="scheduler_verify"; return 1; }
        if [ -L "$(zpd_current_link)" ]; then
            dep_verify_scheduler "$(zpd_current_link)" || { ZRP_FAILED_STEP="schedule_list"; return 1; }
        fi
    fi
    if [ "$ZRP_C_WRAP" = "1" ] && declare -F zpw_verify_wrappers >/dev/null 2>&1 && [ -L "$(zpd_current_link)" ]; then
        zpw_verify_wrappers "$base" || { ZRP_FAILED_STEP="wrapper_verify"; return 1; }
    fi
    if [ "$ZRP_C_HV" = "1" ]; then
        zpd_local_health_conf_ok "$(zpd_local_health_conf_path)" "$base" || { ZRP_FAILED_STEP="health_vhost_verify"; return 1; }
    fi

    if [ "$ZRP_C_STATE" = "1" ]; then
        dep_reconcile_state || { ZRP_FAILED_STEP="state_repair"; return 1; }
    fi

    # Runtime health gates — REQUIRED whenever anything operational was touched.
    if [ "$ZRP_OP_TOUCHED" = "1" ]; then
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

# zrp_apply — snapshot, run the steps fail-closed, restore (scoped) + validate
# on failure, and record the outcome. Component booleans in ZRP_C_* globals.
zrp_apply() {
    local base snap; base="$(zpd_base)"
    ZRP_OP_TOUCHED=0
    if [ "$ZRP_C_NGINX" = "1" ] || [ "$ZRP_C_SUPER" = "1" ] || [ "$ZRP_C_SCHED" = "1" ] \
        || [ "$ZRP_C_WRAP" = "1" ] || [ "$ZRP_C_HV" = "1" ]; then
        ZRP_OP_TOUCHED=1
    fi

    # Snapshot ONLY the resources of the SELECTED components before changing
    # anything — a targeted repair must not fail because an UNRELATED resource
    # (broken wrappers, unreadable service config, uncopyable manifest) cannot
    # be captured, and a metadata-only repair captures no operational config.
    local cap_scope=""
    [ "$ZRP_C_NGINX" = "1" ] && cap_scope="$cap_scope nginx"
    [ "$ZRP_C_SUPER" = "1" ] && cap_scope="$cap_scope supervisor"
    [ "$ZRP_C_SCHED" = "1" ] && cap_scope="$cap_scope scheduler"
    [ "$ZRP_C_WRAP"  = "1" ] && cap_scope="$cap_scope wrappers"
    [ "$ZRP_C_HV"    = "1" ] && cap_scope="$cap_scope hv"
    cap_scope="${cap_scope# }"
    if [ -n "$cap_scope" ]; then
        snap="$(dep_snapshot_operational_config "repair-$(date -u +%Y%m%d%H%M%S)" "$cap_scope")" \
            || { echo "could not create repair backup snapshot" >&2; return 1; }
    else
        # Metadata-only repair: a plain protected directory for the metadata
        # transaction — no operational resources are captured or restored.
        snap="$(zpd_snapshots_dir)/repair-$(date -u +%Y%m%d%H%M%S)-meta"
        mkdir -p "$snap" 2>/dev/null || { echo "could not create repair backup snapshot" >&2; return 1; }
        chmod 700 "$snap" 2>/dev/null || true
    fi
    if [ "$ZRP_C_MAN" = "1" ] || [ "$ZRP_C_STATE" = "1" ]; then
        zrp_backup_metadata "$snap" \
            || { echo "could not back up manifests/state into ${snap}" >&2; return 1; }
    fi
    echo "backup snapshot: ${snap}"

    local components="nginx:${ZRP_C_NGINX},super:${ZRP_C_SUPER},sched:${ZRP_C_SCHED},wrap:${ZRP_C_WRAP},hv:${ZRP_C_HV},man:${ZRP_C_MAN},state:${ZRP_C_STATE}"

    if _zrp_apply_steps "$base"; then
        zpd_write_manifest "$(zrp_last_repair_file)" \
            "result=success" "snapshot=${snap}" "components=${components}" \
            "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "$(zpd_msg_repair_done)"
        return 0
    fi

    # ── FAIL-CLOSED: restore the snapshot FOR THE SCOPE THAT WAS TOUCHED,
    # validate, record, exit non-zero. A metadata-only repair must never reload
    # live services during its restore.
    local failed_step="$ZRP_FAILED_STEP" restore_result="success" restore_scope=""
    echo "repair failed at step: ${failed_step} — restoring the pre-repair snapshot" >&2
    # Restore ONLY the components this repair was allowed to touch: a
    # wrapper-only failure must not rewrite Nginx/Supervisor or restart
    # workers, a scheduler-only repair must not reload unrelated services,
    # and a metadata-only repair must not touch live services at all.
    [ "$ZRP_C_NGINX" = "1" ] && restore_scope="$restore_scope nginx"
    [ "$ZRP_C_SUPER" = "1" ] && restore_scope="$restore_scope supervisor"
    [ "$ZRP_C_SCHED" = "1" ] && restore_scope="$restore_scope scheduler"
    [ "$ZRP_C_WRAP"  = "1" ] && restore_scope="$restore_scope wrappers"
    [ "$ZRP_C_HV"    = "1" ] && restore_scope="$restore_scope hv"
    restore_scope="${restore_scope# }"
    if [ -n "$restore_scope" ]; then
        dep_restore_operational_snapshot "$snap" "$restore_scope" || restore_result="failed"
    fi
    if [ "$ZRP_C_MAN" = "1" ] || [ "$ZRP_C_STATE" = "1" ]; then
        zrp_restore_metadata "$snap" || restore_result="failed"
    fi
    zpd_write_manifest "$(zrp_last_repair_file)" \
        "result=failed" "failed_step=${failed_step}" \
        "restore_result=${restore_result}" "snapshot=${snap}" \
        "components=${components}" \
        "finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if [ "$restore_result" = "success" ]; then
        echo "pre-repair state restored and validated (snapshot kept: ${snap})" >&2
    else
        echo "RESTORE INCOMPLETE — review the snapshot manually: ${snap}" >&2
    fi
    return 1
}

zrp_main() {
    local apply=0 any_comp=0 arg
    ZRP_C_NGINX=0; ZRP_C_SUPER=0; ZRP_C_SCHED=0; ZRP_C_WRAP=0; ZRP_C_HV=0
    ZRP_C_MAN=0; ZRP_C_STATE=0
    for arg in "$@"; do
        case "$arg" in
            --scan)  apply=0 ;;
            --apply) apply=1 ;;
            --nginx)        ZRP_C_NGINX=1; any_comp=1 ;;
            --supervisor)   ZRP_C_SUPER=1; any_comp=1 ;;
            --scheduler)    ZRP_C_SCHED=1; any_comp=1 ;;
            --wrappers)     ZRP_C_WRAP=1;  any_comp=1 ;;
            --health-vhost) ZRP_C_HV=1;    any_comp=1 ;;
            --manifests)    ZRP_C_MAN=1;   any_comp=1 ;;
            --state)        ZRP_C_STATE=1; any_comp=1 ;;
            --operational)  ZRP_C_NGINX=1; ZRP_C_SUPER=1; ZRP_C_SCHED=1; ZRP_C_WRAP=1; ZRP_C_HV=1; any_comp=1 ;;
            *) echo "usage: zedproxy-deploy-repair [--scan|--apply] [--nginx] [--supervisor] [--scheduler] [--wrappers] [--health-vhost] [--manifests] [--state] [--operational]" >&2; return 2 ;;
        esac
    done
    # No component flag → all components.
    if [ "$any_comp" -eq 0 ]; then
        ZRP_C_NGINX=1; ZRP_C_SUPER=1; ZRP_C_SCHED=1; ZRP_C_WRAP=1; ZRP_C_HV=1
        ZRP_C_MAN=1; ZRP_C_STATE=1
    fi

    if [ "$apply" -eq 0 ]; then
        # READ-ONLY scan.
        local issues=0
        echo "ZedProxy deploy-repair scan (read-only)"
        [ "$ZRP_C_NGINX" = "1" ] && { echo "nginx:";        zrp_scan_nginx        || issues=$((issues + $?)); }
        [ "$ZRP_C_SUPER" = "1" ] && { echo "supervisor:";   zrp_scan_supervisor   || issues=$((issues + $?)); }
        [ "$ZRP_C_SCHED" = "1" ] && { echo "scheduler:";    zrp_scan_scheduler    || issues=$((issues + $?)); }
        [ "$ZRP_C_WRAP"  = "1" ] && { echo "wrappers:";     zrp_scan_wrappers     || issues=$((issues + $?)); }
        [ "$ZRP_C_HV"    = "1" ] && { echo "health vhost:"; zrp_scan_health_vhost || issues=$((issues + $?)); }
        [ "$ZRP_C_MAN"   = "1" ] && { echo "release manifests:"; zrp_scan_manifests || issues=$((issues + $?)); }
        [ "$ZRP_C_STATE" = "1" ] && { echo "deployment state:";  zrp_scan_state     || issues=$((issues + $?)); }
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
    zpd_run_locked "$(zpd_lock_file)" -- zrp_apply
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
