#!/usr/bin/env bash
# =============================================================================
# ZedProxy update — thin entrypoint that delegates to the atomic, release-based
# deployment system (scripts/deploy/deploy.sh).
#
#   sudo bash update.sh
#
# The old in-place `git reset --hard` update has been replaced: deployments now
# build a brand-new release in an isolated directory and activate it with an
# atomic `current` symlink switch, with automatic code rollback on failure. See
# scripts/deploy/deploy.sh and docs/deployment.md.
# =============================================================================
set -Eeuo pipefail

PROJECT_DIR="${ZPD_BASE:-/var/www/zedproxy}"

# Prefer the deploy script from the active release, so the running deployment
# logic always matches the deployed code; fall back to the working tree.
CANDIDATES=(
    "${PROJECT_DIR}/current/scripts/deploy/deploy.sh"
    "${PROJECT_DIR}/scripts/deploy/deploy.sh"
    "$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)/scripts/deploy/deploy.sh"
)

DEPLOY=""
for c in "${CANDIDATES[@]}"; do
    if [ -f "$c" ]; then DEPLOY="$c"; break; fi
done

if [ -z "$DEPLOY" ]; then
    echo "ERROR: atomic deploy script not found (looked in: ${CANDIDATES[*]})" >&2
    exit 1
fi

exec bash "$DEPLOY" "$@"
