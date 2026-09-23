#!/usr/bin/env bash
# Build an installable plugin ZIP: dist/shd-translator.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="shd-translator"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php" | head -1)"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

mkdir -p "$BUILD/$SLUG" "$ROOT/dist"
(cd "$ROOT" && tar --exclude-from=.distignore --exclude=./dist -cf - .) | tar -xf - -C "$BUILD/$SLUG"

rm -f "$ROOT/dist/$SLUG.zip"
(cd "$BUILD" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG")
echo "Built dist/$SLUG.zip (version $VERSION)"
