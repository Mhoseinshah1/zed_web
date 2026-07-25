#!/usr/bin/env bash
# =============================================================================
# zedproxy-doctor — comprehensive READ-ONLY deployment diagnostics.
#
#   zedproxy-doctor            # human-readable report (read-only)
#   zedproxy-doctor --json     # machine-readable summary (read-only)
#   zedproxy-doctor --deep     # adds per-release git/manifest inspection
#   zedproxy-doctor --bundle   # also writes a REDACTED root-only archive under
#                              #   <log-dir>/diagnostics/ (mode 600)
#
# Default mode is READ-ONLY: nothing on the system is created, modified, or
# deleted. Only --bundle writes, and only inside the diagnostics directory.
#
# Every external check is BOUNDED (timeouts) and every emitted line passes
# through the secret-redaction layer. The command NEVER prints .env contents,
# passwords, tokens, APP_KEY, authorization headers, cookies, database
# connection strings, or private keys — checks reference paths and statuses,
# never credential values.
#
# Source-safe: sourcing only defines functions (zdr_*).
# =============================================================================

_ZDR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo .)"
if ! declare -F dep_cli_health >/dev/null 2>&1; then
    # shellcheck disable=SC1090
    [ -f "${_ZDR_DIR}/deploy.sh" ] && source "${_ZDR_DIR}/deploy.sh"
fi

ZDR_TIMEOUT="${ZDR_TIMEOUT:-10}"

# Collected results: parallel arrays name / status(ok|warn|fail) / detail / dur.
ZDR_NAMES=(); ZDR_STATUS=(); ZDR_DETAIL=(); ZDR_DUR=()

# zdr_add NAME STATUS DETAIL [DURATION] — record one check result (redacted).
zdr_add() {
    ZDR_NAMES+=("$1")
    ZDR_STATUS+=("$2")
    ZDR_DETAIL+=("$(printf '%s' "${3:-}" | zpd_mask_secrets | head -c 300)")
    ZDR_DUR+=("${4:-0}")
}

# zdr_run NAME CMD... — run CMD bounded; ok on rc=0, fail otherwise (124 → timeout).
zdr_run() {
    local name="$1"; shift
    local t0 t1 rc out
    t0="$(date +%s)"
    out="$(timeout "${ZDR_TIMEOUT}s" "$@" </dev/null 2>&1)"; rc=$?
    t1="$(date +%s)"
    if [ "$rc" -eq 0 ]; then
        zdr_add "$name" ok "$(printf '%s' "$out" | head -n1)" "$((t1 - t0))"
    elif [ "$rc" -eq 124 ]; then
        zdr_add "$name" fail "timed out after ${ZDR_TIMEOUT}s" "$((t1 - t0))"
    else
        zdr_add "$name" fail "rc=${rc} $(printf '%s' "$out" | head -n1)" "$((t1 - t0))"
    fi
    return 0
}

# ── Individual check groups (all read-only) ──────────────────────────────────

zdr_check_system() {
    zdr_add "os" ok "$(uname -sr 2>/dev/null)"
    local free_mb; free_mb="$(zpd_disk_free_mb "$(zpd_base)")"
    if [ "${free_mb:-0}" -lt 512 ]; then zdr_add "disk_free_mb" warn "$free_mb"; else zdr_add "disk_free_mb" ok "$free_mb"; fi
    local inode_pct
    inode_pct="$(df -Pi "$(zpd_base)" 2>/dev/null | awk 'NR==2 {gsub("%","",$5); print $5}')"
    if [ -n "$inode_pct" ] && [ "$inode_pct" -gt 90 ] 2>/dev/null; then
        zdr_add "inodes_used_pct" warn "$inode_pct"
    else
        zdr_add "inodes_used_pct" ok "${inode_pct:-unknown}"
    fi
    local mem_avail
    mem_avail="$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null)"
    zdr_add "memory_available_mb" ok "${mem_avail:-unknown}"
}

zdr_check_releases() {
    local deep="${1:-0}"
    local cur; cur="$(zpd_current_release)"
    if [ -n "$cur" ]; then
        zdr_add "current_symlink" ok "current -> releases/${cur}"
    else
        zdr_add "current_symlink" fail "current symlink missing"
    fi
    # State file vs symlink consistency. A state file that exists but carries
    # NO active identity is a repairable inconsistency, not "ok with unknown".
    local state_active; state_active="$(zpd_manifest_get "$(zpd_state_file)" active_release 2>/dev/null)"
    if [ ! -f "$(zpd_state_file)" ]; then
        zdr_add "state_file" warn "missing ($(zpd_state_file))"
    elif [ -n "$cur" ] && [ -z "$state_active" ]; then
        zdr_add "state_file" fail "incomplete: no active_release recorded while current is ${cur}"
    elif [ -n "$cur" ] && [ "$state_active" != "$cur" ] && [ "$state_active" != "none" ]; then
        zdr_add "state_file" fail "stale: records ${state_active}, current is ${cur}"
    else
        zdr_add "state_file" ok "active_release=${state_active:-unknown}"
    fi
    zdr_add "deploy_env" "$([ -f "$(zpd_deploy_env_file)" ] && echo ok || echo warn)" "$(zpd_deploy_env_file)"

    # Active-release manifest + identity, classified exactly like rollback
    # verification (dep_release_verify_mode) so the doctor cannot report ok on
    # a manifest that strict verification would reject.
    local manifest="$(zpd_releases_dir)/${cur}/RELEASE_MANIFEST.json"
    if [ -n "$cur" ]; then
        local vmode; vmode="$(dep_release_verify_mode "$cur")"
        local mansha headsha res
        res="$(zpd_manifest_get "$manifest" result 2>/dev/null)"
        mansha="$(zpd_manifest_get "$manifest" git_sha 2>/dev/null)"
        headsha="$(zpd_git_head_sha "$(zpd_releases_dir)/${cur}" 2>/dev/null)"
        case "$vmode" in
            strict)
                if [ -z "$mansha" ] || [ "$mansha" = "unknown" ]; then
                    zdr_add "active_manifest" fail "modern manifest missing its git_sha (result=${res})"
                elif [ -z "$headsha" ]; then
                    # Strict verification REQUIRES a readable deployed HEAD —
                    # rollback readiness would reject this release too.
                    zdr_add "active_manifest" fail "deployed git HEAD unreadable for strict manifest (result=${res})"
                elif [ "$mansha" != "$headsha" ]; then
                    zdr_add "active_manifest" fail "manifest SHA != deployed HEAD (result=${res})"
                else
                    zdr_add "active_manifest" ok "result=${res:-unknown} sha=${mansha}"
                fi
                ;;
            adopted)
                if [ -n "$mansha" ] && [ "$mansha" != "unknown" ] && [ -n "$headsha" ] && [ "$mansha" != "$headsha" ]; then
                    zdr_add "active_manifest" fail "adopted manifest SHA no longer matches deployed HEAD"
                else
                    zdr_add "active_manifest" ok "adopted sha=${mansha:-unknown}"
                fi
                ;;
            *)
                zdr_add "active_manifest" warn "missing/incomplete — release predates the manifest system (adoptable)"
                ;;
        esac
    fi

    # Stale / failed attempts.
    local rel res n_act=0 n_fail=0
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        res="$(zpd_manifest_get "$(zpd_releases_dir)/${rel}/RELEASE_MANIFEST.json" result 2>/dev/null)"
        [ "$res" = "activating" ] && [ "$rel" != "$cur" ] && n_act=$((n_act + 1))
        [ "$res" = "failed" ] && n_fail=$((n_fail + 1))
        if [ "$deep" = "1" ]; then
            zdr_add "release.${rel}" ok "result=${res:-none} head=$(zpd_git_head_sha "$(zpd_releases_dir)/${rel}" 2>/dev/null | head -c 12)"
        fi
    done < <(zpd_list_releases)
    if [ "$n_act" -gt 0 ]; then
        zdr_add "stale_activating_releases" fail "${n_act} release(s) stuck 'activating' (repairable)"
    else
        zdr_add "stale_activating_releases" ok "none"
    fi
    zdr_add "failed_releases" ok "${n_fail}"

    # Lock + running deployments.
    local lock; lock="$(zpd_lock_file)"
    if [ -e "$lock" ] && command -v flock >/dev/null 2>&1; then
        if ( exec 9>>"$lock"; flock -n 9 ) 2>/dev/null; then
            zdr_add "deploy_lock" ok "free"
        else
            zdr_add "deploy_lock" warn "held (a deployment appears to be running)"
        fi
    else
        zdr_add "deploy_lock" ok "no lock file"
    fi
    local nproc; nproc="$(pgrep -fc 'deploy/deploy.sh' 2>/dev/null || echo 0)"
    zdr_add "running_deployments" ok "${nproc:-0}"
}

zdr_check_app() {
    local cur; cur="$(zpd_current_link)"
    [ -L "$cur" ] || { zdr_add "app_checks" warn "no active release"; return 0; }

    if zpd_is_in_maintenance "$cur"; then
        zdr_add "maintenance_mode" warn "app is IN maintenance mode"
    else
        zdr_add "maintenance_mode" ok "off"
    fi
    # Symlink INTEGRITY only — never the contents. Links must be SYMLINKS that
    # RESOLVE into shared/ (mere existence hides isolated local storage).
    if [ -L "${cur}/.env" ] && [ -e "${cur}/.env" ]; then
        zdr_add "env_link" ok "current/.env -> $(readlink "${cur}/.env" 2>/dev/null)"
    else
        zdr_add "env_link" fail "current/.env is not a valid symlink into shared/"
    fi
    if dep_check_shared_links "$cur" 2>/dev/null; then
        zdr_add "storage_links" ok ".env/storage/public-storage resolve into shared/"
    else
        zdr_add "storage_links" fail "storage links missing or not resolving into shared/"
    fi
    # Permission-bit inspection ONLY (read-only — never a probe file), evaluated
    # for the SERVICE ACCOUNT: as root, [ -w ] is always true, which would hide
    # a permission outage for the www-data workers/PHP-FPM.
    local sdir appuser mode owner group ok_w=0
    sdir="$(zpd_shared_dir)/storage"
    appuser="${ZPD_APP_USER:-www-data}"
    if [ -d "$sdir" ]; then
        mode="$(stat -c '%a' "$sdir" 2>/dev/null)"; owner="$(stat -c '%U' "$sdir" 2>/dev/null)"; group="$(stat -c '%G' "$sdir" 2>/dev/null)"
        case "$mode" in *[2367]) ok_w=1 ;; esac                                  # world-writable
        [ "$owner" = "$appuser" ] && case "$mode" in [2367]*) ok_w=1 ;; esac     # owner-writable
        [ "$group" = "$appuser" ] && case "$mode" in ?[2367]?) ok_w=1 ;; esac    # group-writable
    fi
    if [ "$ok_w" = "1" ]; then
        zdr_add "shared_writable" ok "writable by ${appuser} (mode ${mode}, ${owner}:${group})"
    else
        zdr_add "shared_writable" fail "shared storage missing or not writable by ${appuser}"
    fi
    [ -f "${cur}/public/build/manifest.json" ] \
        && zdr_add "vite_manifest" ok "present" \
        || zdr_add "vite_manifest" fail "public/build/manifest.json missing"
}

zdr_check_services() {
    local base; base="$(zpd_base)"
    zpd_nginx_root_ok "$(zpd_nginx_conf_path)" "$base" \
        && zdr_add "nginx_root" ok "current/public" \
        || zdr_add "nginx_root" fail "Nginx root is not on current/public"
    zpd_local_health_conf_ok "$(zpd_local_health_conf_path)" "$base" \
        && zdr_add "local_health_vhost" ok "loopback :$(zpd_local_health_port)" \
        || zdr_add "local_health_vhost" fail "missing or not loopback-only"
    zdr_run "nginx_validate" "$ZPD_NGINX" -t
    local sock; sock="$(dep_fpm_socket)"
    [ -S "$sock" ] && zdr_add "fpm_socket" ok "$sock" || zdr_add "fpm_socket" warn "socket not found: ${sock}"
    zpd_supervisor_ok "$(zpd_supervisor_conf_path)" "$base" \
        && zdr_add "supervisor_conf" ok "current/artisan" \
        || zdr_add "supervisor_conf" fail "Supervisor config missing or not on current/"
    dep_supervisor_group_running >/dev/null 2>&1 \
        && zdr_add "workers" ok "worker group RUNNING" \
        || zdr_add "workers" fail "worker group not fully RUNNING"
}

zdr_check_scheduler() {
    local base; base="$(zpd_base)"
    zpd_scheduler_cron_ok "$(zpd_scheduler_cron_path)" "$base" \
        && zdr_add "scheduler_cron" ok "$(zpd_scheduler_cron_path)" \
        || zdr_add "scheduler_cron" fail "canonical cron missing/legacy/duplicated"
    # Every discovered scheduler source outside the canonical file — cron AND
    # non-cron homes (systemd timers/services, Supervisor programs).
    # Repeated findings get NUMBERED check names so the flat JSON report keeps
    # one distinct key per finding (repeated keys would silently collapse).
    local entry kind src ours=0 foreign=0 ndup=0 nconf=0 nleft=0
    while IFS= read -r entry; do
        [ -n "$entry" ] || continue
        kind="${entry%% *}"; src="${entry#* }"
        if [ "$kind" = "OURS" ]; then
            ours=$((ours + 1)); ndup=$((ndup + 1))
            zdr_add "scheduler_duplicate_${ndup}" fail "ZedProxy schedule:run also in ${src}"
        fi
        [ "$kind" = "FOREIGN" ] && foreign=$((foreign + 1))
    done < <(dep_scheduler_scan "$base")
    # Non-cron homes: an ACTIVE source is a live duplicate scheduler (fail); an
    # INACTIVE leftover unit executes nothing (warn — cleanup candidate).
    while IFS= read -r entry; do
        [ -n "$entry" ] || continue
        if printf '%s\n' "$entry" | dep_scheduler_filter_active | grep -q .; then
            ours=$((ours + 1)); nconf=$((nconf + 1))
            zdr_add "scheduler_conflict_${nconf}" fail "ACTIVE unmanaged scheduler source: ${entry}"
        else
            nleft=$((nleft + 1))
            zdr_add "scheduler_leftover_${nleft}" warn "inactive leftover scheduler source: ${entry}"
        fi
    done < <(dep_scheduler_scan_noncron "$base")
    [ "$ours" -eq 0 ] && zdr_add "scheduler_sources" ok "single canonical source"
    [ "$foreign" -gt 0 ] && zdr_add "scheduler_foreign" warn "${foreign} unrelated Laravel scheduler line(s) found (left untouched)"
    # Heartbeat age (optional file, e.g. written by the schedule itself).
    local hb="${ZPD_SCHED_HEARTBEAT:-$(zpd_shared_dir)/storage/framework/schedule-heartbeat}"
    if [ -f "$hb" ]; then
        local age; age=$(( $(date +%s) - $(stat -c %Y "$hb" 2>/dev/null || echo 0) ))
        if [ "$age" -le 300 ]; then zdr_add "scheduler_heartbeat" ok "${age}s ago"; else zdr_add "scheduler_heartbeat" warn "${age}s ago"; fi
    else
        zdr_add "scheduler_heartbeat" warn "no heartbeat file"
    fi
    if [ -L "$(zpd_current_link)" ]; then
        dep_verify_scheduler "$(zpd_current_link)" >/dev/null 2>&1 \
            && zdr_add "schedule_list" ok "schedule:list ok" \
            || zdr_add "schedule_list" fail "schedule:list failed"
    fi
}

zdr_check_health() {
    local cur; cur="$(zpd_current_link)"
    if [ -L "$cur" ]; then
        dep_cli_health "$cur" >/dev/null 2>&1 \
            && zdr_add "cli_health" ok "zedproxy:health ok" \
            || zdr_add "cli_health" fail "zedproxy:health failed or timed out"
    fi
    local code
    code="$(dep_http_code "${ZPD_HEALTH_URL}/health")"
    [ "$code" = "200" ] && zdr_add "http_health" ok "200" || zdr_add "http_health" fail "HTTP ${code}"
    code="$(dep_http_code "${ZPD_HEALTH_URL}/health/live")"
    [ "$code" = "200" ] && zdr_add "http_live" ok "200" || zdr_add "http_live" fail "HTTP ${code}"
    dep_check_pg "$(zpd_shared_dir)/.env" >/dev/null 2>&1 \
        && zdr_add "postgresql" ok "reachable" || zdr_add "postgresql" fail "unreachable"
    dep_check_redis "$(zpd_shared_dir)/.env" >/dev/null 2>&1 \
        && zdr_add "redis" ok "reachable" || zdr_add "redis" fail "unreachable"
}

zdr_check_tooling() {
    zdr_add "php_version" ok "$(dep_probe_version "$ZPD_PHP" -r 'echo PHP_VERSION;')"
    zdr_add "composer_version" ok "$(dep_probe_version "$ZPD_COMPOSER" --version --no-ansi)"
    zdr_add "node_version" ok "$(dep_probe_version "$ZPD_NODE" --version)"
    zdr_add "npm_version" ok "$(dep_probe_version "$ZPD_NPM" --version)"
}

# zdr_collect [DEEP] — run every check group (read-only).
zdr_collect() {
    local deep="${1:-0}"
    ZDR_NAMES=(); ZDR_STATUS=(); ZDR_DETAIL=(); ZDR_DUR=()
    zdr_check_system
    zdr_check_releases "$deep"
    zdr_check_app
    zdr_check_services
    zdr_check_scheduler
    zdr_check_health
    zdr_check_tooling
}

# zdr_report_text — human-readable report from the collected results.
zdr_report_text() {
    local i fails=0 warns=0
    echo "ZedProxy doctor — $(date -u +%Y-%m-%dT%H:%M:%SZ) (read-only)"
    echo "  Base: $(zpd_base)"
    echo ""
    for i in "${!ZDR_NAMES[@]}"; do
        printf '  %-28s %-5s %s\n' "${ZDR_NAMES[$i]}" "${ZDR_STATUS[$i]}" "${ZDR_DETAIL[$i]}"
        [ "${ZDR_STATUS[$i]}" = "fail" ] && fails=$((fails + 1))
        [ "${ZDR_STATUS[$i]}" = "warn" ] && warns=$((warns + 1))
    done
    echo ""
    echo "  summary: ${#ZDR_NAMES[@]} checks, ${fails} failed, ${warns} warnings"
    [ "$fails" -eq 0 ]
}

# zdr_report_json — flat JSON summary (name=status pairs + counts). Serialized
# DIRECTLY to the current stdout (zpd_print_manifest): the atomic file writer
# must never be pointed at /dev/stdout, whose symlink it would replace when
# running as root.
zdr_report_json() {
    local i fails=0 pairs=()
    for i in "${!ZDR_NAMES[@]}"; do
        pairs+=("${ZDR_NAMES[$i]}=${ZDR_STATUS[$i]}: ${ZDR_DETAIL[$i]}")
        [ "${ZDR_STATUS[$i]}" = "fail" ] && fails=$((fails + 1))
    done
    zpd_print_manifest \
        "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "active_release=$(zpd_current_release)" \
        "checks_total=${#ZDR_NAMES[@]}" "checks_failed=${fails}" \
        "${pairs[@]}"
    [ "$fails" -eq 0 ]
}

# zdr_bundle — REDACTED root-only diagnostic archive. The ONLY writing mode.
# Contains: report.txt, summary durations, machine-readable summary.json, and
# redacted tails of recent deployment logs. NEVER contains .env, uploads,
# database dumps, cookies, or secret values. Prints the archive path last.
zdr_bundle() {
    zdr_collect 1
    local outdir stamp work archive
    outdir="$(zpd_log_dir)/diagnostics"
    stamp="$(date -u +%Y%m%d%H%M%S)"
    work="$(mktemp -d)" || return 1
    mkdir -p "$outdir" 2>/dev/null || { rm -rf "$work"; return 1; }
    chmod 700 "$outdir" 2>/dev/null || true
    # Collision-free archive name (two bundles within the same second must
    # never overwrite each other) — mktemp reserves the unique path.
    archive="$(mktemp --suffix=.tar.gz "${outdir}/zedproxy-diagnostic-${stamp}-XXXXXX" 2>/dev/null)" \
        || { rm -rf "$work"; return 1; }

    { zdr_report_text || true; } > "${work}/report.txt" 2>&1
    {
        printf '# check exit-status and duration (seconds)\n'
        local i
        for i in "${!ZDR_NAMES[@]}"; do
            printf '%s\t%s\t%ss\n' "${ZDR_NAMES[$i]}" "${ZDR_STATUS[$i]}" "${ZDR_DUR[$i]}"
        done
    } > "${work}/durations.tsv"
    { zdr_report_json || true; } > "${work}/summary.json" 2>&1
    zpd_write_manifest "${work}/context.json" \
        "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "active_release=$(zpd_current_release)" \
        "previous_release=$(zpd_previous_release)" \
        "attempted_releases=$(zpd_list_releases | head -n5 | tr '\n' ' ')" \
        "base=$(zpd_base)"
    # Recent deployment logs — REDACTED line-by-line; never .env / dumps /
    # uploads. Cookie and Authorization header VALUES are stripped in addition
    # to the standard secret masking (a raw session cookie must never ship).
    local logf n=0
    for logf in "$(zpd_log_dir)"/*.log; do
        [ -f "$logf" ] || continue
        n=$((n + 1)); [ "$n" -gt 5 ] && break
        tail -n 200 "$logf" 2>/dev/null \
            | zpd_mask_secrets \
            | sed -E 's/((set-)?cookie|authorization)([[:space:]]*[:=]).*/\1\3 [REDACTED]/I' \
            > "${work}/log-$(basename "$logf").redacted"
    done

    tar -czf "$archive" -C "$work" . 2>/dev/null || { rm -rf "$work"; return 1; }
    chmod 600 "$archive" 2>/dev/null || true
    chown 0:0 "$archive" 2>/dev/null || true
    rm -rf "$work"
    printf '%s\n' "$archive"
}

zdr_main() {
    local mode="text" deep=0 arg
    for arg in "$@"; do
        case "$arg" in
            --json)   mode="json" ;;
            --bundle) mode="bundle" ;;
            --deep)   deep=1 ;;
            *) echo "usage: zedproxy-doctor [--json] [--bundle] [--deep]" >&2; return 2 ;;
        esac
    done
    case "$mode" in
        json)   zdr_collect "$deep"; zdr_report_json ;;
        bundle)
            local path rc=0
            path="$(zdr_bundle)" || rc=1
            if [ "$rc" -eq 0 ] && [ -n "$path" ]; then
                echo "$(zpd_msg_doctor_bundle)"
                echo "$path"
            else
                echo "diagnostic bundle generation failed" >&2
                return 1
            fi
            ;;
        *)      zdr_collect "$deep"; zdr_report_text ;;
    esac
}

if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    set -uo pipefail
    zdr_main "$@"
fi
