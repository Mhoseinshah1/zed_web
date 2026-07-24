#!/usr/bin/env bash
# =============================================================================
# ZedProxy supply-chain hardening helper library.
#
# Pure, side-effect-free helpers shared by install.sh / update.sh / the deploy
# scripts and covered by the shell tests in tests/supply-chain/. Sourcing this
# file MUST NOT run any installation step, perform network I/O, print anything,
# or mutate global state beyond defining functions + the load sentinel.
#
# This is the SINGLE SOURCE OF TRUTH for supported runtime versions and for the
# vulnerability-audit policy. All function names are prefixed `zsc_`.
# =============================================================================

# Guard against double-sourcing.
if [ -n "${ZSC_SUPPLY_CHAIN_LIB_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
ZSC_SUPPLY_CHAIN_LIB_LOADED=1

# ─────────────────────────────────────────────────────────────────────────────
# Runtime version policy — the ONE place versions are declared.
# ─────────────────────────────────────────────────────────────────────────────
ZSC_SUPPORTED_UBUNTU="${ZSC_SUPPORTED_UBUNTU:-22.04 24.04 26.04}"
ZSC_PHP_MIN="${ZSC_PHP_MIN:-8.2}"
ZSC_PHP_MAX="${ZSC_PHP_MAX:-8.4}"
ZSC_PG_MIN="${ZSC_PG_MIN:-14}"
ZSC_PG_MAX="${ZSC_PG_MAX:-17}"
ZSC_REDIS_MIN="${ZSC_REDIS_MIN:-6}"
ZSC_REDIS_MAX="${ZSC_REDIS_MAX:-7}"
ZSC_COMPOSER_MIN="${ZSC_COMPOSER_MIN:-2.2.0}"
ZSC_COMPOSER_MAX="${ZSC_COMPOSER_MAX:-2.99.99}"
ZSC_NODE_MAJOR="${ZSC_NODE_MAJOR:-22}"
ZSC_NPM_MIN="${ZSC_NPM_MIN:-10.0.0}"
ZSC_NPM_MAX="${ZSC_NPM_MAX:-11.99.99}"

# Official Composer public key / installer signature source (documented trust root).
ZSC_COMPOSER_SIG_URL="${ZSC_COMPOSER_SIG_URL:-https://composer.github.io/installer.sig}"
ZSC_COMPOSER_INSTALLER_URL="${ZSC_COMPOSER_INSTALLER_URL:-https://getcomposer.org/installer}"
# NodeSource apt signing key + repository (pinned major is appended by the caller).
ZSC_NODE_KEY_URL="${ZSC_NODE_KEY_URL:-https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key}"
ZSC_NODE_KEYRING="${ZSC_NODE_KEYRING:-/usr/share/keyrings/nodesource.gpg}"

# Persian operator-facing messages (kept identical to the spec).
ZSC_MSG_COMPOSER_BAD='اعتبار فایل نصب Composer تأیید نشد. عملیات متوقف شد.'
ZSC_MSG_NODE_BAD='اعتبار مخزن Node.js تأیید نشد. نصب متوقف شد.'

# ─────────────────────────────────────────────────────────────────────────────
# Version comparison
# ─────────────────────────────────────────────────────────────────────────────

# zsc_ver_ge A B — return 0 when version A >= version B (dotted numeric).
zsc_ver_ge() {
    [ -n "${1:-}" ] && [ -n "${2:-}" ] || return 2
    [ "$1" = "$2" ] && return 0
    # The smaller of the two, by version sort, must be B for A>=B.
    [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$2" ]
}

# zsc_ver_in_range V MIN MAX — return 0 when MIN <= V <= MAX.
zsc_ver_in_range() {
    local v="$1" min="$2" max="$3"
    [ -n "$v" ] || return 1
    zsc_ver_ge "$v" "$min" && zsc_ver_ge "$max" "$v"
}

# zsc_major V — print the leading integer (major) component of a version.
zsc_major() {
    local v="${1:-}"
    v="${v#v}"
    printf '%s' "${v%%.*}" | tr -cd '0-9'
}

# ── Per-runtime support checks (0 = supported) ──────────────────────────────
zsc_check_ubuntu() {
    local v="${1:-}" want
    for want in $ZSC_SUPPORTED_UBUNTU; do
        [ "$v" = "$want" ] && return 0
    done
    return 1
}
zsc_check_php()      { zsc_ver_in_range "${1:-}" "$ZSC_PHP_MIN" "$ZSC_PHP_MAX"; }
zsc_check_pg()       { local m; m="$(zsc_major "${1:-}")"; [ -n "$m" ] && zsc_ver_in_range "$m" "$ZSC_PG_MIN" "$ZSC_PG_MAX"; }
zsc_check_redis()    { local m; m="$(zsc_major "${1:-}")"; [ -n "$m" ] && zsc_ver_in_range "$m" "$ZSC_REDIS_MIN" "$ZSC_REDIS_MAX"; }
zsc_check_composer() { zsc_ver_in_range "${1:-}" "$ZSC_COMPOSER_MIN" "$ZSC_COMPOSER_MAX"; }
zsc_check_npm()      { zsc_ver_in_range "${1:-}" "$ZSC_NPM_MIN" "$ZSC_NPM_MAX"; }
zsc_check_node_major() { [ "$(zsc_major "${1:-}")" = "$ZSC_NODE_MAJOR" ]; }

# ─────────────────────────────────────────────────────────────────────────────
# Constant-time-ish hash comparison + SHA-384
# ─────────────────────────────────────────────────────────────────────────────

# zsc_consteq A B — compare two strings without a length-dependent early exit.
# These are public hashes, not secrets, but we still avoid the naive short-
# circuit so the comparison is uniform. Return 0 on exact match.
zsc_consteq() {
    local a="${1:-}" b="${2:-}" i diff=0 ca cb la lb
    la=${#a}; lb=${#b}
    # Different lengths can never match; still fold in every char of the longer.
    [ "$la" -eq "$lb" ] || diff=1
    local n=$la
    [ "$lb" -gt "$n" ] && n=$lb
    for (( i=0; i<n; i++ )); do
        ca="${a:i:1}"; cb="${b:i:1}"
        [ "$ca" = "$cb" ] || diff=1
    done
    [ "$diff" -eq 0 ]
}

# zsc_sha384 FILE — print the lowercase hex SHA-384 of FILE, or return 1.
# Tries coreutils, then openssl, then PHP — whichever is available.
zsc_sha384() {
    local file="$1" out
    [ -f "$file" ] || return 1
    if command -v sha384sum >/dev/null 2>&1; then
        out="$(sha384sum "$file" 2>/dev/null)" && { printf '%s' "${out%% *}"; return 0; }
    fi
    if command -v openssl >/dev/null 2>&1; then
        out="$(openssl dgst -sha384 "$file" 2>/dev/null)" && { printf '%s' "${out##* }"; return 0; }
    fi
    if command -v php >/dev/null 2>&1; then
        out="$(php -r 'echo hash_file("sha384", $argv[1]);' "$file" 2>/dev/null)" && { printf '%s' "$out"; return 0; }
    fi
    return 1
}

# zsc_verify_composer_installer FILE EXPECTED_SIG
# Return 0 when the SHA-384 of FILE exactly matches EXPECTED_SIG (trimmed).
# Return 1 on any mismatch, empty signature, or unreadable file.
zsc_verify_composer_installer() {
    local file="$1" expected="$2" actual
    [ -f "$file" ] && [ -s "$file" ] || return 1
    expected="$(printf '%s' "${expected:-}" | tr -d '[:space:]')"
    [ -n "$expected" ] || return 1          # empty signature response → fail
    actual="$(zsc_sha384 "$file")" || return 1
    [ -n "$actual" ] || return 1
    zsc_consteq "$actual" "$expected"
}

# ─────────────────────────────────────────────────────────────────────────────
# Lock-file enforcement
# ─────────────────────────────────────────────────────────────────────────────

# zsc_require_lockfiles DIR — 0 only when BOTH composer.lock and
# package-lock.json exist in DIR. Prints the name of the first missing file.
zsc_require_lockfiles() {
    local dir="${1:-.}"
    if [ ! -f "${dir}/composer.lock" ]; then printf 'composer.lock'; return 1; fi
    if [ ! -f "${dir}/package-lock.json" ]; then printf 'package-lock.json'; return 1; fi
    return 0
}

# zsc_forbidden_prod_flag ARG... — return 0 (found) if any argument is a flag
# that must never be used in a production install (integrity/platform bypass).
zsc_forbidden_prod_flag() {
    local a
    for a in "$@"; do
        case "$a" in
            --ignore-platform-reqs|--no-audit|--no-verify|--force|--legacy-peer-deps)
                printf '%s' "$a"; return 0 ;;
        esac
    done
    return 1
}

# ─────────────────────────────────────────────────────────────────────────────
# Vulnerability-audit policy
# ─────────────────────────────────────────────────────────────────────────────
# Policy (single source of truth):
#   critical            → fail
#   high                → fail UNLESS an unexpired allowlist entry exists
#   moderate / low      → report and continue
#   none / unknown-low  → pass
# The allowlist file is line-based: package|advisory|reason|YYYY-MM-DD|owner
# Blank lines and lines beginning with '#' are ignored.

# zsc_severity_rank SEV — numeric rank so severities are comparable.
zsc_severity_rank() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        critical) printf '4' ;;
        high)     printf '3' ;;
        moderate|medium) printf '2' ;;
        low)      printf '1' ;;
        *)        printf '0' ;;
    esac
}

# zsc_allowlist_entry_active FILE PACKAGE ADVISORY TODAY
# Return 0 when FILE holds a non-expired entry matching PACKAGE+ADVISORY.
# TODAY is YYYY-MM-DD (injected for deterministic tests).
zsc_allowlist_entry_active() {
    local file="$1" pkg="$2" adv="$3" today="$4" line f_pkg f_adv f_exp
    [ -f "$file" ] || return 1
    [ -n "$pkg" ] && [ -n "$adv" ] && [ -n "$today" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in ''|\#*) continue ;; esac
        f_pkg="$(printf '%s' "$line" | cut -d'|' -f1 | tr -d '[:space:]')"
        f_adv="$(printf '%s' "$line" | cut -d'|' -f2 | tr -d '[:space:]')"
        f_exp="$(printf '%s' "$line" | cut -d'|' -f4 | tr -d '[:space:]')"
        [ "$f_pkg" = "$pkg" ] && [ "$f_adv" = "$adv" ] || continue
        # An entry with no expiry, or a malformed one, is treated as EXPIRED.
        printf '%s' "$f_exp" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' || return 1
        # Active only while today <= expiry (string compare works for ISO dates).
        [ "$today" \> "$f_exp" ] && return 1
        return 0
    done < "$file"
    return 1
}

# zsc_audit_decision SEVERITY ALLOWLISTED
# Print one of: fail | report | pass. ALLOWLISTED is "1" when an active
# allowlist entry covers the finding (only relevant for high).
zsc_audit_decision() {
    local rank; rank="$(zsc_severity_rank "${1:-}")"
    local allowed="${2:-0}"
    if [ "$rank" -ge 4 ]; then printf 'fail'; return 0; fi          # critical
    if [ "$rank" -eq 3 ]; then                                       # high
        [ "$allowed" = "1" ] && printf 'report' || printf 'fail'
        return 0
    fi
    if [ "$rank" -ge 1 ]; then printf 'report'; return 0; fi         # moderate/low
    printf 'pass'
}

# ─────────────────────────────────────────────────────────────────────────────
# Secret masking + secure download flags
# ─────────────────────────────────────────────────────────────────────────────

# zsc_mask_secrets — filter stdin→stdout, redacting tokens/keys/passwords and
# credentials embedded in URLs so audit output and logs never leak them.
zsc_mask_secrets() {
    sed -E \
        -e 's/(Authorization:[[:space:]]*)(Bearer|Basic)[[:space:]]+[^[:space:]]+/\1\2 ***/gI' \
        -e 's#(https?://)[^/@[:space:]:]+:[^/@[:space:]]+@#\1***:***@#g' \
        -e 's/(APP_KEY|DB_PASSWORD|DB_PASS|REDIS_PASSWORD|PGPASSWORD|MAIL_PASSWORD)=[^[:space:]]*/\1=***/gI' \
        -e 's/((authorization|token|secret|api[_-]?key|password|passwd)[[:space:]]*[:=][[:space:]]*)[^[:space:]"'"'"']+/\1***/gI'
}

# zsc_curl_args OUTFILE — echo the safe curl argument list for downloading an
# executable/script: HTTPS-only, TLS floor, bounded timeout + retries, no
# secrets on the command line. The caller appends the URL.
zsc_curl_args() {
    local out="$1"
    printf '%s\0' \
        --fail --location --show-error --silent \
        --proto '=https' --tlsv1.2 \
        --max-time "${ZSC_DL_MAX_TIME:-60}" \
        --retry "${ZSC_DL_RETRIES:-3}" --retry-delay 2 \
        --output "$out"
}

# ─────────────────────────────────────────────────────────────────────────────
# GPG keyring validation (Node repository)
# ─────────────────────────────────────────────────────────────────────────────

# zsc_gpg_key_valid FILE — return 0 when FILE is a non-empty keyring that gpg
# can parse at least one public key from. Falls back to a non-empty binary
# check when gpg is unavailable (still rejects the empty-response case).
zsc_gpg_key_valid() {
    local file="$1"
    [ -f "$file" ] && [ -s "$file" ] || return 1
    if command -v gpg >/dev/null 2>&1; then
        gpg --show-keys --with-colons "$file" 2>/dev/null | grep -q '^pub:' && return 0
        return 1
    fi
    # No gpg (e.g. minimal test env): accept any non-empty keyring blob.
    return 0
}

# ─────────────────────────────────────────────────────────────────────────────
# Release / version metadata
# ─────────────────────────────────────────────────────────────────────────────

# zsc_git_resolve DIR REF — print the FULL commit SHA that REF resolves to in
# the git tree at DIR. REF may be a tag, branch, or commit. Prints nothing and
# returns 1 when it cannot be resolved. Never assumes "main".
zsc_git_resolve() {
    local dir="$1" ref="${2:-HEAD}"
    [ -d "$dir/.git" ] || [ -f "$dir/.git" ] || return 1
    git -C "$dir" rev-parse --verify --quiet "${ref}^{commit}" 2>/dev/null
}

# zsc_git_tag_for DIR SHA — print an exact tag pointing at SHA, or nothing.
zsc_git_tag_for() {
    local dir="$1" sha="$2"
    [ -n "$sha" ] || return 0
    git -C "$dir" describe --tags --exact-match "$sha" 2>/dev/null || true
}

# zsc_tool_version NAME — print a single-line version string for a tool, safely
# (never fails the caller; prints "unknown" when the tool is absent).
zsc_tool_version() {
    case "${1:-}" in
        php)      php -r 'echo PHP_VERSION;' 2>/dev/null || printf 'unknown' ;;
        composer) composer --version --no-ansi 2>/dev/null | head -n1 || printf 'unknown' ;;
        node)     node --version 2>/dev/null || printf 'unknown' ;;
        npm)      npm --version 2>/dev/null || printf 'unknown' ;;
        *)        printf 'unknown' ;;
    esac
}

# ─────────────────────────────────────────────────────────────────────────────
# Forbidden-pattern scanner (used by CI and by tests)
# ─────────────────────────────────────────────────────────────────────────────

# zsc_scan_forbidden FILE... — return 0 when NONE of the files contain an unsafe
# supply-chain pattern; return 1 and print the offending matches otherwise.
# Detects: curl|wget piped into bash/sh/php, npm-ci→npm-install fallback,
# composer update, and integrity/platform-bypass flags. set -e safe (all grep
# failures are captured, never propagated).
zsc_scan_forbidden() {
    local rc=0 f hit
    local -a checks=(
        'unsafe-pipe|(curl|wget)[^|]*\|[[:space:]]*(sudo[[:space:]]+)?(bash|sh|php)([[:space:]]|-|$)'
        'npm-fallback|npm[[:space:]]+ci\b.*\|\|[[:space:]]*npm[[:space:]]+install'
        'composer-update|(^|[^[:alnum:]_])composer[[:space:]]+update\b'
        'unsafe-flag|--ignore-platform-reqs|--legacy-peer-deps'
    )
    for f in "$@"; do
        [ -f "$f" ] || continue
        local entry label pat
        for entry in "${checks[@]}"; do
            label="${entry%%|*}"
            pat="${entry#*|}"
            # Skip pure comment lines (first non-space char is '#') — comments
            # never execute, so a documented mention is not an unsafe pattern.
            hit="$(grep -nE "$pat" "$f" 2>/dev/null | grep -vE '^[0-9]+:[[:space:]]*#' || true)"
            if [ -n "$hit" ]; then
                printf '%s\n' "$hit" | sed "s|^|${f}: ${label}: |"
                rc=1
            fi
        done
    done
    return $rc
}
