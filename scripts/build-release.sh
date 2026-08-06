#!/usr/bin/env bash
# Build an unsigned release archive of the app.
#
# Usage: scripts/build-release.sh [--output <path>]
#
#   --output <path>  Where to write the .tar.gz archive.
#                    Default: <workspace-root>/nextcloud_migrate.tar.gz
#
# The script installs all required dependencies itself (composer + npm),
# so it can be run standalone outside of CI.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

APP_ID="nextcloud_migrate"
OUTPUT_PATH="$WORKSPACE_DIR/${APP_ID}.tar.gz"

# Parse arguments
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output)
      OUTPUT_PATH="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

BUILD_ROOT="$(mktemp -d)"
trap 'rm -rf "$BUILD_ROOT"' EXIT

RELEASE_DIR="$BUILD_ROOT/$APP_ID"

echo "==> Installing development dependencies"
cd "$WORKSPACE_DIR"
composer install --no-interaction --no-progress --prefer-dist
npm ci

echo "==> Building frontend bundle"
npm run build

echo "==> Assembling release archive"
mkdir -p "$RELEASE_DIR"

rsync -a --exclude-from="$WORKSPACE_DIR/.distignore" "$WORKSPACE_DIR/" "$RELEASE_DIR/"

composer install \
  --working-dir="$RELEASE_DIR" \
  --no-dev \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  --optimize-autoloader

rm -f \
  "$RELEASE_DIR/composer.json" \
  "$RELEASE_DIR/composer.lock"

tar -czf "$OUTPUT_PATH" -C "$BUILD_ROOT" "$APP_ID"

echo "==> Archive written to: $OUTPUT_PATH"
