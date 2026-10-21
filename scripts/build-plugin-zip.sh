#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
PLUGIN_DIR="senoobar-link-auditor"
mkdir -p "$DIST"
rm -f "$DIST/$PLUGIN_DIR.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$PLUGIN_DIR"
cp "$ROOT/senoobar-link-auditor.php" "$STAGE/$PLUGIN_DIR/"
cp -R "$ROOT/includes" "$STAGE/$PLUGIN_DIR/"
cp -R "$ROOT/assets" "$STAGE/$PLUGIN_DIR/"
cp "$ROOT/uninstall.php" "$STAGE/$PLUGIN_DIR/"
cp "$ROOT/readme.txt" "$STAGE/$PLUGIN_DIR/"
if find "$STAGE/$PLUGIN_DIR" -type f \( -name '*.php' -o -name '*.js' \) -print0 | xargs -0 -r grep -nE 'TODO[[:space:]]*:[[:space:]]*(FIXME|IMPLEMENT)' ; then
  echo "Unfinished implementation marker found; refusing to package." >&2
  exit 1
fi
(
  cd "$STAGE"
  zip -qr "$DIST/$PLUGIN_DIR.zip" "$PLUGIN_DIR"
)
unzip -t "$DIST/$PLUGIN_DIR.zip"
echo "Created $DIST/$PLUGIN_DIR.zip"
