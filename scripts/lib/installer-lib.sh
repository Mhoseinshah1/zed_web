#!/usr/bin/env bash
# =============================================================================
# ZedProxy installer helper library.
#
# Pure, side-effect-free helper functions shared by install.sh and covered by
# the shell tests in tests/installer/. Sourcing this file MUST NOT run any
# installation step, print anything, or mutate global state beyond defining
# functions and the load sentinel — so it is safe to source from both the
# installer and the test runner.
#
# All function names are prefixed with `zp_` to avoid collisions.
# =============================================================================

# Guard against double-sourcing.
if [ -n "${ZP_INSTALLER_LIB_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
ZP_INSTALLER_LIB_LOADED=1

# -----------------------------------------------------------------------------
# zp_env_get FILE KEY
#
# Print the value of KEY from a dotenv FILE. Strips one layer of surrounding
# single/double quotes and a trailing CR. Prints nothing (empty) when the key
# is absent or has an empty value. Returns 1 only when FILE does not exist.
# -----------------------------------------------------------------------------
zp_env_get() {
    local file="$1" key="$2" line val
    [ -f "$file" ] || return 1
    # Last matching assignment wins (mirrors dotenv override semantics).
    line=$(grep -E "^[[:space:]]*${key}=" "$file" 2>/dev/null | tail -n1) || true
    [ -n "$line" ] || return 0
    val="${line#*=}"
    val="${val%$'\r'}"
    # Remove one layer of matching surrounding quotes, if present.
    if [ "${val#\"}" != "$val" ] && [ "${val%\"}" != "$val" ]; then
        val="${val#\"}"; val="${val%\"}"
    elif [ "${val#\'}" != "$val" ] && [ "${val%\'}" != "$val" ]; then
        val="${val#\'}"; val="${val%\'}"
    fi
    printf '%s' "$val"
}

# -----------------------------------------------------------------------------
# zp_detect_existing_installation DIR
#
# Return 0 when DIR looks like an already-configured ZedProxy installation:
# it must contain .env AND artisan AND composer.json. A freshly-cloned repo
# that has never been configured has no .env and is therefore NOT existing.
# -----------------------------------------------------------------------------
zp_detect_existing_installation() {
    local dir="$1"
    [ -n "$dir" ] || return 1
    [ -f "$dir/.env" ] && [ -f "$dir/artisan" ] && [ -f "$dir/composer.json" ]
}

# -----------------------------------------------------------------------------
# zp_is_git_repo DIR — return 0 when DIR is a git working tree.
# -----------------------------------------------------------------------------
zp_is_git_repo() {
    [ -n "${1:-}" ] && [ -d "${1}/.git" ]
}

# -----------------------------------------------------------------------------
# zp_appkey_is_valid VALUE
#
# Return 0 when VALUE is a structurally valid Laravel APP_KEY: a "base64:"
# prefix whose payload decodes to a 16/24/32-byte key (AES-128/192/256). The
# actual value is never printed. This validates SHAPE only — it cannot prove
# the key still matches existing ciphertext (that is what the app-level
# zedproxy:verify-encryption command is for).
# -----------------------------------------------------------------------------
zp_appkey_is_valid() {
    local key="$1" b64 len
    [ -n "$key" ] || return 1
    case "$key" in
        base64:*) ;;
        *) return 1 ;;
    esac
    b64="${key#base64:}"
    [ -n "$b64" ] || return 1
    len=$(printf '%s' "$b64" | base64 -d 2>/dev/null | wc -c | tr -d '[:space:]') || return 1
    [ "$len" = "32" ] || [ "$len" = "24" ] || [ "$len" = "16" ]
}

# -----------------------------------------------------------------------------
# zp_appkey_present_and_valid FILE
#
# Return 0 when FILE has a non-empty, structurally valid APP_KEY.
# -----------------------------------------------------------------------------
zp_appkey_present_and_valid() {
    local file="$1" key
    key=$(zp_env_get "$file" "APP_KEY") || return 1
    zp_appkey_is_valid "$key"
}

# -----------------------------------------------------------------------------
# zp_backup_env SRC BACKUP_ROOT TIMESTAMP
#
# Copy SRC (an .env file) to BACKUP_ROOT/TIMESTAMP/.env with mode 600 and the
# containing directories locked to 700. Prints the backup file path on success.
# Returns 1 on any failure (missing source, unwritable destination, …) so the
# caller can abort before touching anything.
# -----------------------------------------------------------------------------
zp_backup_env() {
    local src="$1" root="$2" ts="$3" dest
    [ -f "$src" ] || return 1
    [ -n "$root" ] && [ -n "$ts" ] || return 1
    dest="${root}/${ts}"
    mkdir -p "$dest" 2>/dev/null || return 1
    chmod 700 "$root" 2>/dev/null || true
    chmod 700 "$dest" 2>/dev/null || true
    cp "$src" "${dest}/.env" 2>/dev/null || return 1
    chmod 600 "${dest}/.env" 2>/dev/null || return 1
    printf '%s' "${dest}/.env"
}

# -----------------------------------------------------------------------------
# zp_mask_secret VALUE — return a masked form safe to print in logs.
# Short values collapse to ****; longer ones keep the first/last two chars.
# -----------------------------------------------------------------------------
zp_mask_secret() {
    local s="${1:-}" n
    n=${#s}
    if [ "$n" -le 4 ]; then
        printf '****'
        return 0
    fi
    printf '%s****%s' "${s:0:2}" "${s: -2}"
}

# -----------------------------------------------------------------------------
# zp_git_has_local_changes DIR
#
# Return 0 when the git tree at DIR has local modifications OR untracked,
# non-ignored files (anything `git reset --hard`/`git clean -fd` would discard).
# Ignored runtime files (.env, storage/*, uploads) are not reported.
# -----------------------------------------------------------------------------
zp_git_has_local_changes() {
    local dir="$1"
    zp_is_git_repo "$dir" || return 1
    [ -n "$(git -C "$dir" status --porcelain 2>/dev/null)" ]
}

# -----------------------------------------------------------------------------
# zp_git_backup_local_changes DIR BACKUP_ROOT TIMESTAMP
#
# Copy every locally-modified tracked file and untracked non-ignored file at
# DIR into BACKUP_ROOT/TIMESTAMP/local_changes/, preserving relative paths, so
# a `git reset --hard` can be undone by hand. Prints the backup dir when files
# were copied; prints nothing when there was nothing to back up. Never fails
# the caller (best-effort protection).
# -----------------------------------------------------------------------------
zp_git_backup_local_changes() {
    local dir="$1" root="$2" ts="$3" dest copied=0 rel
    zp_is_git_repo "$dir" || return 0
    dest="${root}/${ts}/local_changes"
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        [ -f "${dir}/${rel}" ] || continue
        mkdir -p "${dest}/$(dirname "$rel")" 2>/dev/null || continue
        if cp "${dir}/${rel}" "${dest}/${rel}" 2>/dev/null; then
            copied=$((copied + 1))
        fi
    done < <(git -C "$dir" status --porcelain 2>/dev/null | sed -E 's/^.{3}//' | tr -d '"')
    if [ "$copied" -gt 0 ]; then
        chmod -R 700 "${root}/${ts}" 2>/dev/null || true
        printf '%s' "$dest"
    fi
    return 0
}
