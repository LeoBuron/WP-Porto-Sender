#!/usr/bin/env bash
#
# Build a distributable plugin zip into dist/wp-porto-sender-<version>.zip.
#
# The version is read from the porto-sender.php header (single source of truth).
# Production vendor (composer --no-dev) is built in an isolated staging dir, so this
# never disturbs the dev vendor/ this repo needs for the test suite. The same script
# runs locally and in CI (.github/workflows/release.yml) so the artifacts match.
#
# Usage:  bash bin/build-zip.sh
set -euo pipefail

SLUG="wp-porto-sender"
MAIN_FILE="porto-sender.php"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# 1) Version from the plugin header (POSIX classes — portable across BSD/GNU sed).
VERSION="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_FILE" \
  | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "ERROR: could not read Version from $MAIN_FILE" >&2; exit 1; }
echo "==> Packaging $SLUG v$VERSION"

# 2) Build the editor block assets when a JS toolchain is present (CI runs npm ci first).
if [ -f package.json ] && command -v npm >/dev/null 2>&1; then
  [ -d node_modules ] || npm ci --no-audit --no-fund
  npm run build
else
  echo "    (skipping asset build — npm not available; using committed build/)"
fi

# 3) Stage runtime files into a clean temp dir named after the plugin slug.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

rsync -a \
  --exclude '.git' --exclude '.jj' --exclude '.github' --exclude 'bin' \
  --exclude 'node_modules' --exclude 'vendor' --exclude 'dist' \
  --exclude 'tests' --exclude 'docs' --exclude '.claude' --exclude '.superpowers' \
  --exclude '.phpunit.cache' --exclude '.wp-env.json' --exclude '.gitignore' \
  --exclude 'phpunit-*.xml' --exclude 'package*.json' --exclude 'README.md' \
  --exclude '.DS_Store' \
  ./ "$DEST/"

# 4) Build the PRODUCTION vendor inside the stage (composer.json/lock + scripts/ + patches/
#    were copied above; the post-install hook patches the altcha vendor).
( cd "$DEST" && COMPOSER_ROOT_VERSION="$VERSION" composer install --no-dev --optimize-autoloader --no-interaction --no-progress )

# 5) Drop build-only files from the package.
rm -f  "$DEST/composer.json" "$DEST/composer.lock"
rm -rf "$DEST/scripts" "$DEST/patches"
find "$DEST" -name '.DS_Store' -delete

# 6) Zip with the plugin slug as the top-level folder (WordPress install convention).
mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/${SLUG}-${VERSION}.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rqX "$ZIP" "$SLUG" )

echo "==> Created dist/${SLUG}-${VERSION}.zip ($(du -h "$ZIP" | cut -f1))"
