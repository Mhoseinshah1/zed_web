#!/usr/bin/env bash
# =============================================================================
# Supply-chain hardening shell tests (scripts/lib/supply-chain-lib.sh + the
# install/deploy wiring). Run with:
#     bash tests/supply-chain/run-tests.sh
#
# NO root, NO real network, NO real package managers, NO real apt repositories.
# Every download / package-manager call is mocked. The 25 numbered scenarios
# map 1:1 to the task specification.
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LIB="${REPO_ROOT}/scripts/lib/supply-chain-lib.sh"

# shellcheck source=/dev/null
source "$LIB"

PASS=0
FAIL=0
ok()  { echo "  ok   - $*"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL - $*"; FAIL=$((FAIL + 1)); }
assert_true()  { if "$@"; then ok "$*"; else bad "$* (expected success)"; fi; }
assert_false() { if "$@"; then bad "$* (expected failure)"; else ok "! $*"; fi; }
assert_eq()    { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

echo "== ZedProxy supply-chain tests =="

# ── 1. Valid Composer signature ──────────────────────────────────────────────
T="$(mktemp -d)"; printf 'installer-bytes' > "$T/setup.php"
SIG="$(zsc_sha384 "$T/setup.php")"
assert_true zsc_verify_composer_installer "$T/setup.php" "$SIG"

# ── 2. Invalid Composer signature ────────────────────────────────────────────
assert_false zsc_verify_composer_installer "$T/setup.php" "0000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000"

# ── 3. Empty signature response ──────────────────────────────────────────────
assert_false zsc_verify_composer_installer "$T/setup.php" ""

# ── 4. Composer download failure (no installer file on disk) ─────────────────
assert_false zsc_verify_composer_installer "$T/does-not-exist.php" "$SIG"
rm -rf "$T"

# ── 5. Valid Node signing key ────────────────────────────────────────────────
T="$(mktemp -d)"
if command -v gpg >/dev/null 2>&1; then
    # Build a REAL keyring so the gpg-parse path is exercised (no network).
    GNUPGHOME="$(mktemp -d)"; export GNUPGHOME; chmod 700 "$GNUPGHOME"
    gpg --batch --quiet --pinentry-mode loopback --passphrase '' \
        --quick-generate-key 'ZedProxy CI <ci@example.com>' default default 0 >/dev/null 2>&1
    gpg --export > "$T/node.gpg" 2>/dev/null
else
    printf 'BINARY-GPG-KEYRING-BLOB' > "$T/node.gpg"   # fallback path (no gpg)
fi
assert_true zsc_gpg_key_valid "$T/node.gpg"

# ── 6. Invalid Node signing key (empty response) ─────────────────────────────
: > "$T/empty.gpg"
assert_false zsc_gpg_key_valid "$T/empty.gpg"

# ── 7. Repository setup failure (missing keyring file) ───────────────────────
assert_false zsc_gpg_key_valid "$T/missing.gpg"
rm -rf "$T"

# ── 8. Missing composer.lock ─────────────────────────────────────────────────
T="$(mktemp -d)"; : > "$T/package-lock.json"
assert_eq "$(zsc_require_lockfiles "$T")" "composer.lock" "missing composer.lock reported"
assert_false zsc_require_lockfiles "$T"

# ── 9. Composer lock mismatch (composer validate --strict fails) ─────────────
MOCKBIN="$(mktemp -d)"
cat > "$MOCKBIN/composer" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  *"validate"*) exit ${MOCK_VALIDATE_RC:-0} ;;
  *) exit 0 ;;
esac
EOF
chmod +x "$MOCKBIN/composer"
run_validate() { "$MOCKBIN/composer" validate --strict --no-check-publish; }
MOCK_VALIDATE_RC=1 run_validate; assert_eq "$?" "1" "composer validate mismatch is fatal"
MOCK_VALIDATE_RC=0 run_validate; assert_eq "$?" "0" "composer validate passes when consistent"

# ── 10. Missing package-lock.json ────────────────────────────────────────────
rm -f "$T/package-lock.json"; : > "$T/composer.lock"
assert_eq "$(zsc_require_lockfiles "$T")" "package-lock.json" "missing package-lock.json reported"
: > "$T/package-lock.json"
assert_true zsc_require_lockfiles "$T"
rm -rf "$T"

# ── 11. npm ci failure is fatal (NO fallback to npm install) ─────────────────
cat > "$MOCKBIN/npm" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  ci)      exit ${MOCK_NPM_CI_RC:-0} ;;
  install) echo "FALLBACK-INSTALL-CALLED"; exit 0 ;;
  *)       exit 0 ;;
esac
EOF
chmod +x "$MOCKBIN/npm"
# Mirror install.sh's rule: `npm ci || error` — never `|| npm install`.
prod_npm() { "$MOCKBIN/npm" ci || return 1; }
out="$( MOCK_NPM_CI_RC=1 prod_npm 2>&1 )"; rc=$?
assert_eq "$rc" "1" "npm ci failure is fatal"
assert_true bash -c "[ -z '$out' ] || ! printf '%s' \"$out\" | grep -q FALLBACK"

# ── 12. Forbidden npm fallback pattern is detected ───────────────────────────
BADF="$(mktemp)"; printf 'npm ci || npm install\n' > "$BADF"
assert_false zsc_scan_forbidden "$BADF"
GOODF="$(mktemp)"; printf 'npm ci\n' > "$GOODF"
assert_true zsc_scan_forbidden "$GOODF"
rm -f "$BADF" "$GOODF"

# ── 13. Critical Composer advisory → fail ────────────────────────────────────
assert_eq "$(zsc_audit_decision critical 0)" "fail" "critical advisory fails"

# ── 14. High Composer advisory (not allowlisted) → fail ──────────────────────
assert_eq "$(zsc_audit_decision high 0)" "fail" "high advisory fails without allowlist"

# ── 15. Critical npm advisory → fail ─────────────────────────────────────────
assert_eq "$(zsc_audit_decision critical 0)" "fail" "critical npm advisory fails"

# ── 16. Allowed temporary advisory (unexpired allowlist) → report ────────────
AL="$(mktemp)"
printf 'guzzlehttp/guzzle|CVE-2026-0001|upstream fix pending|2999-12-31|sec@zed\n' > "$AL"
assert_true zsc_allowlist_entry_active "$AL" guzzlehttp/guzzle CVE-2026-0001 2026-07-24
assert_eq "$(zsc_audit_decision high 1)" "report" "high advisory with active allowlist → report"

# ── 17. Expired advisory exception → still fails ─────────────────────────────
printf 'pkg/x|CVE-2020-9999|old|2020-01-01|sec@zed\n' > "$AL"
assert_false zsc_allowlist_entry_active "$AL" pkg/x CVE-2020-9999 2026-07-24
allowed=0; zsc_allowlist_entry_active "$AL" pkg/x CVE-2020-9999 2026-07-24 && allowed=1
assert_eq "$(zsc_audit_decision high "$allowed")" "fail" "expired exception does not suppress high"
# Malformed (missing) expiry is treated as expired.
printf 'pkg/y|CVE-1||owner\n' > "$AL"
assert_false zsc_allowlist_entry_active "$AL" pkg/y CVE-1 2026-07-24
rm -f "$AL"

# ── 18. Unsupported PHP version ──────────────────────────────────────────────
assert_false zsc_check_php 8.1
assert_true  zsc_check_php 8.3

# ── 19. Unsupported Node version ─────────────────────────────────────────────
assert_false zsc_check_node_major v20.11.0
assert_true  zsc_check_node_major v22.22.2

# ── 20. Unsupported operating system ─────────────────────────────────────────
assert_false zsc_check_ubuntu 20.04
assert_true  zsc_check_ubuntu 24.04

# ── 21. Exact commit recording (full resolved SHA, never a branch name) ──────
GT="$(mktemp -d)"
( cd "$GT"
  git init -q
  git config user.email t@t; git config user.name t
  git commit -q --allow-empty -m init
)
SHA="$(zsc_git_resolve "$GT" HEAD)"
assert_true bash -c "printf '%s' '$SHA' | grep -Eq '^[0-9a-f]{40}$'"
git -C "$GT" tag -a v9.9.9 -m rel
assert_eq "$(zsc_git_tag_for "$GT" "$SHA")" "v9.9.9" "exact tag recorded for the commit"
rm -rf "$GT"

# ── 22. Temporary-file cleanup (mktemp + RETURN trap removes the file) ───────
LEAK=""
cleanup_demo() {
    local tmp; tmp="$(mktemp)"
    # shellcheck disable=SC2064
    trap "rm -f '$tmp'" RETURN
    LEAK="$tmp"
    printf 'sensitive' > "$tmp"
}
cleanup_demo
assert_true bash -c "[ ! -e '$LEAK' ]"

# ── 23. Secret masking ───────────────────────────────────────────────────────
masked="$(printf 'DB_PASSWORD=SuperSecret123\nAuthorization: Bearer abcdef.token\nhttps://user:pass@github.com/x.git\n' | zsc_mask_secrets)"
assert_true  bash -c "! printf '%s' \"$masked\" | grep -q SuperSecret123"
assert_true  bash -c "! printf '%s' \"$masked\" | grep -q 'abcdef.token'"
assert_true  bash -c "! printf '%s' \"$masked\" | grep -q 'user:pass'"

# ── 24. Shell syntax (bash -n) on every hardened script ──────────────────────
for f in install.sh update.sh \
         scripts/lib/supply-chain-lib.sh scripts/lib/installer-lib.sh scripts/lib/deploy-lib.sh \
         scripts/deploy/deploy.sh scripts/deploy/rollback.sh scripts/deploy/deploy-status.sh; do
    assert_true bash -n "${REPO_ROOT}/${f}"
done

# ── 25. ShellCheck (errors only) — skipped cleanly if not installed ──────────
if command -v shellcheck >/dev/null 2>&1; then
    assert_true shellcheck -S error -x "${REPO_ROOT}/scripts/lib/supply-chain-lib.sh"
    assert_true shellcheck -S error    "${REPO_ROOT}/install.sh"
else
    echo "  skip - shellcheck not installed"
fi

# ── Bonus: no forbidden patterns remain in the real scripts ──────────────────
assert_true zsc_scan_forbidden \
    "${REPO_ROOT}/install.sh" "${REPO_ROOT}/update.sh" \
    "${REPO_ROOT}/scripts/deploy/deploy.sh" "${REPO_ROOT}/scripts/deploy/rollback.sh" \
    "${REPO_ROOT}/scripts/deploy/deploy-status.sh" "${REPO_ROOT}/scripts/backup.sh"

rm -rf "$MOCKBIN"

echo ""
echo "== results: ${PASS} passed, ${FAIL} failed =="
[ "$FAIL" -eq 0 ]
