#!/usr/bin/env bash
# =============================================================================
# ZedProxy stable command-wrapper installer.
#
# ONE implementation of the `/usr/local/bin/zedproxy-*` wrappers + the shared
# `/usr/local/lib/zedproxy/bootstrap.sh` resolver, used by BOTH the installer
# (install.sh) and the atomic deployer (scripts/deploy/deploy.sh). Installing the
# wrappers from deploy.sh is what lets a LEGACY server — which migrates through
# the downloaded update.sh → deploy.sh, never install.sh — replace its old,
# broken `zedproxy-update` wrapper (which executed the legacy base deployer with
# the original `.` repository fallback).
#
# Source-safe: sourcing only defines functions (zpw_*). Executing it installs the
# wrappers directly.
#
# Overridable install locations (tests point these at temp dirs):
#   ZPD_WRAPPER_BIN   (default /usr/local/bin)
#   ZPD_WRAPPER_LIB   (default /usr/local/lib/zedproxy)
# =============================================================================

if [ -n "${ZPW_WRAPPERS_LIB_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
ZPW_WRAPPERS_LIB_LOADED=1

zpw_bin_dir() { printf '%s' "${ZPD_WRAPPER_BIN:-/usr/local/bin}"; }
zpw_lib_dir() { printf '%s' "${ZPD_WRAPPER_LIB:-/usr/local/lib/zedproxy}"; }

# The five managed paths (bootstrap first).
zpw_managed_paths() {
    printf '%s\n' \
        "$(zpw_lib_dir)/bootstrap.sh" \
        "$(zpw_bin_dir)/zedproxy-update" \
        "$(zpw_bin_dir)/zedproxy-rollback" \
        "$(zpw_bin_dir)/zedproxy-deploy-status" \
        "$(zpw_bin_dir)/zedproxy-sanitize-install-log"
}

# -----------------------------------------------------------------------------
# _zpw_atomic_install DEST MODE   (content read from stdin)
#
# Write stdin to DEST atomically: a private temp file in the SAME directory,
# chmod MODE, chown root:root (best-effort — no-op without privilege), then
# rename over DEST. Existing unrelated files are never touched.
# -----------------------------------------------------------------------------
_zpw_atomic_install() {
    local dest="$1" mode="$2" dir tmp
    dir="$(dirname "$dest")"
    mkdir -p "$dir" 2>/dev/null || return 1
    tmp="$(cd "$dir" && mktemp ".zpw.XXXXXX" 2>/dev/null)" || return 1
    tmp="${dir}/${tmp}"
    if ! cat > "$tmp"; then rm -f "$tmp"; return 1; fi
    chmod "$mode" "$tmp" 2>/dev/null || true
    chown 0:0 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$dest" 2>/dev/null || { rm -f "$tmp"; return 1; }
}

# ── Content generators ───────────────────────────────────────────────────────

zpw_bootstrap_content() {
    cat <<'BOOTSTRAP'
#!/usr/bin/env bash
# Shared resolver for the zedproxy-* command shortcuts. Sourced, never executed.
# Resolves ZPD_BASE from deploy.env (parsed, not sourced) and locates a script
# from the ACTIVE release first, then a detected install — independent of $PWD.
_zpd_deploy_env="${ZPD_DEPLOY_ENV:-/etc/zedproxy/deploy.env}"
if [ -z "${ZPD_BASE:-}" ] && [ -r "$_zpd_deploy_env" ]; then
    ZPD_BASE="$(sed -nE 's/^[[:space:]]*ZPD_BASE[[:space:]]*=[[:space:]]*"?([^"#[:space:]]+)"?.*/\1/p' "$_zpd_deploy_env" | tail -n1)"
fi
ZPD_BASE="${ZPD_BASE:-/var/www/zedproxy}"
export ZPD_BASE

# zpd_resolve_script REL — echo the first existing "<dir>/REL" among the active
# release (current/) and the base install; return 1 if none exists.
zpd_resolve_script() {
    local rel="$1" d
    for d in "${ZPD_BASE}/current" "${ZPD_BASE}"; do
        if [ -f "${d}/${rel}" ]; then printf '%s' "${d}/${rel}"; return 0; fi
    done
    return 1
}

# zpd_exec_root SCRIPT ARGS... — run SCRIPT as root without unnecessary nested
# sudo (exec directly when already root). Arguments are preserved exactly.
zpd_exec_root() {
    local s="$1"; shift
    if [ "$(id -u)" = "0" ]; then exec bash "$s" "$@"; else exec sudo -E bash "$s" "$@"; fi
}
BOOTSTRAP
}

# zpw_wrapper_content NAME — print the wrapper script for NAME.
zpw_wrapper_content() {
    local name="$1" libdir; libdir="$(zpw_lib_dir)"
    case "$name" in
        zedproxy-update)
            cat <<WRAP
#!/usr/bin/env bash
# Self-bootstrapping atomic update (works from any directory).
. ${libdir}/bootstrap.sh
s="\$(zpd_resolve_script update.sh)" \\
  || { echo "به‌روزرسان یافت نشد. برای بازیابی از دستور نصب curl استفاده کنید." >&2; exit 1; }
zpd_exec_root "\$s" "\$@"
WRAP
            ;;
        zedproxy-rollback)
            cat <<WRAP
#!/usr/bin/env bash
. ${libdir}/bootstrap.sh
s="\$(zpd_resolve_script scripts/deploy/rollback.sh)" \\
  || { echo "اسکریپت بازگردانی یافت نشد." >&2; exit 1; }
zpd_exec_root "\$s" "\$@"
WRAP
            ;;
        zedproxy-deploy-status)
            cat <<WRAP
#!/usr/bin/env bash
. ${libdir}/bootstrap.sh
s="\$(zpd_resolve_script scripts/deploy/deploy-status.sh)" \\
  || { echo "اسکریپت وضعیت استقرار یافت نشد." >&2; exit 1; }
exec bash "\$s" "\$@"
WRAP
            ;;
        zedproxy-sanitize-install-log)
            cat <<WRAP
#!/usr/bin/env bash
# Removes plaintext credentials older installers may have written into the log.
. ${libdir}/bootstrap.sh
s="\$(zpd_resolve_script scripts/zedproxy-sanitize-install-log.sh)" \\
  || { echo "اسکریپت پاک‌سازی لاگ یافت نشد." >&2; exit 1; }
zpd_exec_root "\$s" "\$@"
WRAP
            ;;
        *) return 1 ;;
    esac
}

# -----------------------------------------------------------------------------
# zpw_install_wrappers — (re)install all five managed files atomically. Root
# owned; bootstrap 644, wrappers 755. Returns non-zero if any write fails.
# -----------------------------------------------------------------------------
zpw_install_wrappers() {
    local bin lib name
    bin="$(zpw_bin_dir)"; lib="$(zpw_lib_dir)"
    zpw_bootstrap_content | _zpw_atomic_install "${lib}/bootstrap.sh" 644 || return 1
    for name in zedproxy-update zedproxy-rollback zedproxy-deploy-status zedproxy-sanitize-install-log; do
        zpw_wrapper_content "$name" | _zpw_atomic_install "${bin}/${name}" 755 || return 1
    done
    return 0
}

# -----------------------------------------------------------------------------
# zpw_backup_wrappers DIR — copy any existing managed files into DIR (preserving
# their basenames) so a failed first cutover can restore them. Missing files are
# skipped. Returns 0.
# -----------------------------------------------------------------------------
zpw_backup_wrappers() {
    local dir="$1" p
    [ -n "$dir" ] || return 1
    mkdir -p "$dir" 2>/dev/null || return 1
    while IFS= read -r p; do
        [ -e "$p" ] && cp -a "$p" "${dir}/$(basename "$p")" 2>/dev/null || true
    done < <(zpw_managed_paths)
    return 0
}

# -----------------------------------------------------------------------------
# zpw_restore_wrappers DIR — restore managed files previously saved by
# zpw_backup_wrappers. Files absent from the backup are removed so the wrapper
# set matches the pre-cutover state exactly. Returns 0.
# -----------------------------------------------------------------------------
zpw_restore_wrappers() {
    local dir="$1" p b
    [ -d "$dir" ] || return 1
    while IFS= read -r p; do
        b="${dir}/$(basename "$p")"
        if [ -e "$b" ]; then
            mkdir -p "$(dirname "$p")" 2>/dev/null || true
            cp -a "$b" "$p" 2>/dev/null || true
        else
            rm -f "$p" 2>/dev/null || true
        fi
    done < <(zpw_managed_paths)
    return 0
}

# -----------------------------------------------------------------------------
# zpw_verify_wrappers BASE — confirm the installed wrappers resolve through the
# ACTIVE release for BASE. Sources the INSTALLED bootstrap in a subshell and
# checks that `zedproxy-update` resolves `<BASE>/current/update.sh` and that
# rollback/status resolve their scripts from `<BASE>/current/...`. Returns 1 if
# any wrapper is missing or resolves outside current/.
# -----------------------------------------------------------------------------
zpw_verify_wrappers() {
    local base="$1" bin lib
    bin="$(zpw_bin_dir)"; lib="$(zpw_lib_dir)"
    [ -n "$base" ] || return 1
    [ -f "${lib}/bootstrap.sh" ] || return 1
    local name
    for name in zedproxy-update zedproxy-rollback zedproxy-deploy-status zedproxy-sanitize-install-log; do
        [ -x "${bin}/${name}" ] || return 1
        grep -q 'zpd_resolve_script' "${bin}/${name}" || return 1
    done
    # Functional resolution against the active release.
    (
        ZPD_BASE="$base"
        # shellcheck disable=SC1090
        . "${lib}/bootstrap.sh"
        [ "$(zpd_resolve_script update.sh 2>/dev/null)" = "${base}/current/update.sh" ] || exit 1
        [ "$(zpd_resolve_script scripts/deploy/rollback.sh 2>/dev/null)" = "${base}/current/scripts/deploy/rollback.sh" ] || exit 1
        [ "$(zpd_resolve_script scripts/deploy/deploy-status.sh 2>/dev/null)" = "${base}/current/scripts/deploy/deploy-status.sh" ] || exit 1
    ) || return 1
    return 0
}

# Execute directly → install the wrappers.
if [ "${BASH_SOURCE[0]:-}" = "${0:-}" ]; then
    zpw_install_wrappers
fi
