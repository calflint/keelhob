#!/usr/bin/env bash
#
# Build the installable WordPress plugin zip.
#
#   bash bin/build-zip.sh            → build/claudpress.zip
#   bash bin/build-zip.sh ~/Downloads → also copies it there
#
# WordPress expects a single top-level directory inside the archive matching the
# plugin folder name, so everything is staged under claudpress/ first. Only
# runtime files ship — tests, CI config and local indexes stay out.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/claudpress"
ZIP="$BUILD/claudpress.zip"

VERSION="$(grep -oP "define\('CLAUDPRESS_VERSION',\s*'\K[^']+" "$ROOT/claudpress.php" || true)"
[ -n "$VERSION" ] || { echo "Could not read CLAUDPRESS_VERSION from claudpress.php" >&2; exit 1; }

echo "Building Claudpress $VERSION"

rm -rf "$BUILD"
mkdir -p "$STAGE"

# Runtime files only.
cp "$ROOT/claudpress.php"  "$STAGE/"
cp "$ROOT/uninstall.php"   "$STAGE/"
cp "$ROOT/readme.txt"      "$STAGE/"
[ -f "$ROOT/README.md" ] && cp "$ROOT/README.md" "$STAGE/"
cp -r "$ROOT/includes"     "$STAGE/"
cp -r "$ROOT/skills"       "$STAGE/"

# Belt and braces: strip anything that should never ship even if it was copied.
find "$STAGE" \( -name '.DS_Store' -o -name '*.log' -o -name '.env' \) -delete
rm -rf "$STAGE/.codegraph" "$STAGE/node_modules"

# `zip` on CI and Linux/macOS; Python or PowerShell elsewhere, since Git Bash on
# Windows ships neither zip nor unzip.
PY=""
for candidate in python3 python; do
  if command -v "$candidate" > /dev/null 2>&1 && "$candidate" -c "import zipfile" > /dev/null 2>&1; then
    PY="$(command -v "$candidate")"
    break
  fi
done

if command -v zip > /dev/null 2>&1; then
  ( cd "$BUILD" && zip -qr "$(basename "$ZIP")" claudpress )
elif command -v powershell > /dev/null 2>&1 || command -v pwsh > /dev/null 2>&1; then
  PS="$(command -v pwsh || command -v powershell)"
  "$PS" -NoProfile -Command "Compress-Archive -Path '$(cygpath -w "$STAGE" 2>/dev/null || echo "$STAGE")' -DestinationPath '$(cygpath -w "$ZIP" 2>/dev/null || echo "$ZIP")' -Force"
elif [ -n "$PY" ]; then
  "$PY" - "$BUILD" "$ZIP" <<'PY'
import os, sys, zipfile
build, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _dirs, files in os.walk(os.path.join(build, 'claudpress')):
        for name in sorted(files):
            full = os.path.join(root, name)
            z.write(full, os.path.relpath(full, build).replace(os.sep, '/'))
PY
else
  echo "Need 'zip', PowerShell, or Python to build the archive." >&2
  exit 1
fi

if command -v unzip > /dev/null 2>&1; then
  ENTRIES="$(unzip -Z1 "$ZIP" | wc -l | tr -d ' ')"
else
  ENTRIES="$(find "$STAGE" -type f | wc -l | tr -d ' ')"
fi
SIZE="$(du -h "$ZIP" | cut -f1)"
echo "Built $ZIP — $ENTRIES entries, $SIZE"

# Optional: drop a copy wherever the caller asked for it.
if [ "${1:-}" != "" ]; then
  cp "$ZIP" "$1/claudpress.zip"
  echo "Copied to $1/claudpress.zip"
fi
