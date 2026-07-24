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
# zp_scheduler_cron_line APP_DIR RUN_USER PHP_BIN LOG
#
# Print the single cron line that drives the Laravel scheduler every minute.
# Defaults: RUN_USER=www-data, PHP_BIN=php, LOG=/var/log/zedproxy-scheduler.log.
# -----------------------------------------------------------------------------
zp_scheduler_cron_line() {
    local app_dir="$1" user="${2:-www-data}" php="${3:-php}" log="${4:-/var/log/zedproxy-scheduler.log}"
    [ -n "$app_dir" ] || return 1
    printf '* * * * * %s cd %s && %s artisan schedule:run >> %s 2>&1' "$user" "$app_dir" "$php" "$log"
}

# -----------------------------------------------------------------------------
# zp_write_cron_file FILE CONTENT
#
# Write CONTENT as the *entire* contents of a /etc/cron.d file (mode 644),
# atomically. Because the file is fully replaced (not appended), re-running the
# installer can never create duplicate entries — the file always holds exactly
# one scheduler line. A header comment is added for clarity.
# -----------------------------------------------------------------------------
zp_write_cron_file() {
    local file="$1" content="$2" tmp
    [ -n "$file" ] || return 1
    tmp="$(mktemp)" || return 1
    {
        printf '# Managed by ZedProxy installer — do not edit by hand.\n'
        printf '%s\n' "$content"
    } > "$tmp" || { rm -f "$tmp"; return 1; }
    # cron.d files must not be group/world writable.
    chmod 0644 "$tmp" 2>/dev/null || true
    mv "$tmp" "$file" 2>/dev/null || { rm -f "$tmp"; return 1; }
}

# -----------------------------------------------------------------------------
# zp_remove_file FILE — remove FILE if it exists. Returns 0 whether or not it
# was present (idempotent). Used to retire the legacy backup cron once the
# Laravel scheduler controls backups, so backups run from only one system.
# -----------------------------------------------------------------------------
zp_remove_file() {
    local file="$1"
    [ -n "$file" ] || return 1
    [ -e "$file" ] && rm -f "$file"
    return 0
}

# -----------------------------------------------------------------------------
# zp_count_lines_matching FILE PATTERN — count lines in FILE matching (fixed
# string) PATTERN. Prints 0 when FILE is absent. Used by tests to prove no
# duplicate scheduler entries accumulate across re-runs.
# -----------------------------------------------------------------------------
zp_count_lines_matching() {
    local file="$1" pattern="$2"
    [ -f "$file" ] || { printf '0'; return 0; }
    grep -cF "$pattern" "$file" 2>/dev/null | tr -d '[:space:]' || printf '0'
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

# =============================================================================
# Secret redaction + secure credential delivery (Prompt 14).
#
# These are the ONLY sanctioned paths for handling credentials in the installer.
# Everything here is pure/testable and used by install.sh + the log sanitizer.
# =============================================================================

# -----------------------------------------------------------------------------
# zp_redact  — filter stdin → stdout, replacing every recognised secret with the
# fixed token [REDACTED]. Never keeps partial secrets (no first/last chars).
#
# Masks: KEY=VALUE credential assignments (APP_KEY, DB_PASSWORD, *_PASS(WORD),
# *TOKEN, *SECRET, API_KEY, PGPASSWORD, ...), Bearer/Basic Authorization headers,
# credentials embedded in URLs (user:pass@host, incl. postgres:// / https://),
# JSON credential fields, and GitHub / Telegram bot-token shapes.
# -----------------------------------------------------------------------------
zp_redact() {
    sed -E \
        -e 's/([A-Za-z0-9_]*(PASSWORD|PASSWD|PASS|SECRET|TOKEN|APP_KEY|API_?KEY|PGPASSWORD))[[:space:]]*=[[:space:]]*[^[:space:]"'"'"']+/\1=[REDACTED]/gI' \
        -e 's/(Authorization[[:space:]]*:[[:space:]]*)(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+\/=-]+/\1\2 [REDACTED]/gI' \
        -e 's#([A-Za-z][A-Za-z0-9+.-]*://)[^/@[:space:]:]+:[^/@[:space:]]+@#\1[REDACTED]@#g' \
        -e 's/("?(password|passwd|secret|token|api[_-]?key|api[_-]?token|access[_-]?token|refresh[_-]?token|authorization|signature)"?[[:space:]]*:[[:space:]]*")[^"]*"/\1[REDACTED]"/gI' \
        -e 's/\b(gh[posur]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,})\b/[REDACTED]/g' \
        -e 's/\b[0-9]{6,}:[A-Za-z0-9_-]{30,}\b/[REDACTED]/g'
}

# -----------------------------------------------------------------------------
# zp_redact_str STRING — redact a single string (convenience over zp_redact).
# -----------------------------------------------------------------------------
zp_redact_str() {
    printf '%s' "${1:-}" | zp_redact
}

# -----------------------------------------------------------------------------
# zp_mask_command CMD [LITERAL_SECRET...]
#
# Produce a log-safe rendering of a failed shell command: first blank out any
# exact literal secret values still held in memory (admin/db passwords, tokens),
# then apply zp_redact for structural credential patterns. Used by the ERR trap
# so a failing command can never echo a secret argument.
# -----------------------------------------------------------------------------
zp_mask_command() {
    local cmd="${1:-}"; shift || true
    local s
    for s in "$@"; do
        [ -n "$s" ] || continue
        # Replace every occurrence of the literal secret value.
        cmd="${cmd//"$s"/[REDACTED]}"
    done
    printf '%s' "$cmd" | zp_redact
}

# -----------------------------------------------------------------------------
# zp_path_is_forbidden_credential_dir PATH
#
# Return 0 (forbidden) when PATH lies under a world-exposed or app-shared
# location that must never hold a plaintext credential file: /tmp, /var/tmp,
# web roots, application storage, or shared upload dirs.
# -----------------------------------------------------------------------------
zp_path_is_forbidden_credential_dir() {
    local p="${1:-}"
    case "$p" in
        /tmp/*|/tmp|/var/tmp/*|/var/tmp|/dev/shm/*|/dev/shm) return 0 ;;
        /var/www/*|/srv/www/*|/usr/share/nginx/*) return 0 ;;
        */storage/*|*/storage|*/public/*|*/uploads/*|*/shared/*) return 0 ;;
    esac
    return 1
}

# -----------------------------------------------------------------------------
# zp_validate_credential_dest PATH
#
# Validate a destination for the non-interactive secure credential file. Prints
# a short machine reason on failure. Rules (all must hold):
#   - absolute path
#   - not currently a symlink (anti-symlink-swap)
#   - parent directory exists, is a directory, owned by root (uid 0)
#   - parent directory is NOT group/other writable (unless it is sticky, e.g.
#     never — /tmp is forbidden outright below)
#   - path is not under a forbidden (tmp / web / storage / upload) location
# Returns 0 when the destination is safe to create.
# -----------------------------------------------------------------------------
zp_validate_credential_dest() {
    local dest="${1:-}" parent powner pperm
    case "$dest" in
        /*) ;;
        *) printf 'not-absolute'; return 1 ;;
    esac
    if [ -L "$dest" ]; then printf 'is-symlink'; return 1; fi
    if zp_path_is_forbidden_credential_dir "$dest"; then printf 'forbidden-location'; return 1; fi
    parent="$(dirname "$dest")"
    [ -d "$parent" ] || { printf 'parent-missing'; return 1; }
    if [ -L "$parent" ]; then printf 'parent-symlink'; return 1; fi
    powner="$(stat -c '%u' "$parent" 2>/dev/null || echo -1)"
    [ "$powner" = "0" ] || { printf 'parent-not-root'; return 1; }
    pperm="$(stat -c '%a' "$parent" 2>/dev/null || echo 777)"
    # Reject group- or world-writable parents (last two octal digits & 022).
    case "$pperm" in
        *[2367]|*[2367]?) printf 'parent-writable'; return 1 ;;
    esac
    return 0
}

# -----------------------------------------------------------------------------
# zp_write_credential_file PATH CONTENT [--force]
#
# Atomically create a root-only (600) credential file at PATH after validating
# the destination. Never overwrites an existing file unless --force is given.
# The CONTENT is written via a private temp file (umask 077) then renamed.
# Prints nothing sensitive; returns 0 on success, non-zero + reason otherwise.
# -----------------------------------------------------------------------------
zp_write_credential_file() {
    local dest="${1:-}" content="${2:-}" force="${3:-}" reason tmp
    reason="$(zp_validate_credential_dest "$dest")" || { printf '%s' "$reason"; return 1; }
    if [ -e "$dest" ] && [ "$force" != "--force" ]; then printf 'exists'; return 1; fi
    tmp="$(cd "$(dirname "$dest")" && mktemp ".zpcred.XXXXXX" 2>/dev/null)" || { printf 'mktemp-failed'; return 1; }
    tmp="$(dirname "$dest")/$tmp"
    ( umask 077; printf '%s\n' "$content" > "$tmp" ) || { rm -f "$tmp"; printf 'write-failed'; return 1; }
    chmod 600 "$tmp" 2>/dev/null || true
    chown 0:0 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$dest" 2>/dev/null || { rm -f "$tmp"; printf 'rename-failed'; return 1; }
    return 0
}

# -----------------------------------------------------------------------------
# zp_scan_secrets_in_file FILE
#
# Print (one per line) the CATEGORY of each secret pattern detected in FILE,
# WITHOUT ever printing the matched value. Used by the log sanitizer's --scan.
# Returns 0 when at least one category is found, 1 when the file looks clean.
# -----------------------------------------------------------------------------
zp_scan_secrets_in_file() {
    local file="${1:-}" found=1
    [ -f "$file" ] || return 2
    local -a cats=(
        'app-key|APP_KEY[[:space:]]*=[[:space:]]*base64:'
        'db-password|(DB_PASSWORD|PGPASSWORD)[[:space:]]*=[[:space:]]*[^[:space:]]'
        'admin-password|(ADMIN_PASS|ADMIN_PASSWORD|ZEDPROXY_ADMIN_PASS)[[:space:]]*[=:][[:space:]]*[^[:space:]]'
        'generic-password|[A-Za-z_]*PASS(WORD|WD)?[[:space:]]*[=:][[:space:]]*[^[:space:]]'
        'token|[A-Za-z_]*TOKEN[[:space:]]*[=:][[:space:]]*[^[:space:]]'
        'secret|[A-Za-z_]*SECRET[[:space:]]*[=:][[:space:]]*[^[:space:]]'
        'authorization-header|Authorization[[:space:]]*:[[:space:]]*(Bearer|Basic)[[:space:]]'
        'credential-url|[A-Za-z][A-Za-z0-9+.-]*://[^/@[:space:]:]+:[^/@[:space:]]+@'
        'github-token|(gh[posur]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,})'
        'telegram-token|[0-9]{6,}:[A-Za-z0-9_-]{30,}'
    )
    local entry label pat
    for entry in "${cats[@]}"; do
        label="${entry%%|*}"; pat="${entry#*|}"
        if grep -Eq "$pat" "$file" 2>/dev/null; then
            printf '%s\n' "$label"
            found=0
        fi
    done
    return $found
}
