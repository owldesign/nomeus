#!/usr/bin/env bash
# Build Nomeus.app from the SwiftPM package and zip it. No developer account: ad-hoc signature only.
#
#   scripts/bundle.sh            → dist/Nomeus-<version>.zip
#   VERSION=2.0.0 scripts/bundle.sh
#
# Version defaults to config/nomeus.php's 'version' so the app and the CLI move together.
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$HERE"

VERSION="${VERSION:-$(sed -n "s/.*'version' => '\([^']*\)'.*/\1/p" "$ROOT/config/nomeus.php" 2>/dev/null || true)}"
VERSION="${VERSION:-0.0.0}"
BUILD="$(git -C "$ROOT" rev-list --count HEAD 2>/dev/null || echo 1)"

ARCH="${ARCH:-}"   # empty = universal (arm64 + x86_64)
echo "▸ swift build (release, ${ARCH:-universal})"
if [[ -z "$ARCH" ]]; then
  swift build -c release --arch arm64 --arch x86_64
  BIN=".build/apple/Products/Release/Nomeus"
else
  swift build -c release --arch "$ARCH"
  BIN=".build/$ARCH-apple-macosx/release/Nomeus"
fi
[[ -x "$BIN" ]] || { echo "binary not found at $BIN" >&2; exit 1; }

APP="dist/Nomeus.app"
rm -rf dist && mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
cp "$BIN" "$APP/Contents/MacOS/Nomeus"
sed -e "s/__VERSION__/$VERSION/" -e "s/__BUILD__/$BUILD/" Resources/Info.plist > "$APP/Contents/Info.plist"
printf 'APPL????' > "$APP/Contents/PkgInfo"

# App icon from the site favicon. qlmanage rasterises SVG; iconutil packs the .iconset.
ICON_SRC="$ROOT/site/favicon.svg"
if [[ -f "$ICON_SRC" ]] && command -v qlmanage >/dev/null && command -v iconutil >/dev/null; then
  echo "▸ app icon"
  TMP="$(mktemp -d)"
  qlmanage -t -s 1024 -o "$TMP" "$ICON_SRC" >/dev/null 2>&1 || true
  PNG="$(ls "$TMP"/*.png 2>/dev/null | head -1 || true)"
  if [[ -n "$PNG" ]]; then
    SET="$TMP/AppIcon.iconset"; mkdir -p "$SET"
    for s in 16 32 128 256 512; do
      sips -z $s $s "$PNG" --out "$SET/icon_${s}x${s}.png" >/dev/null
      sips -z $((s*2)) $((s*2)) "$PNG" --out "$SET/icon_${s}x${s}@2x.png" >/dev/null
    done
    iconutil -c icns "$SET" -o "$APP/Contents/Resources/AppIcon.icns"
  else
    echo "  (favicon.svg didn't rasterise; shipping without an icon)"
  fi
  rm -rf "$TMP"
fi

echo "▸ codesign (ad-hoc)"
codesign --force --deep --sign - --timestamp=none "$APP"
codesign --verify --verbose=2 "$APP"

ZIP="dist/Nomeus-$VERSION.zip"
echo "▸ $ZIP"
ditto -c -k --keepParent "$APP" "$ZIP"
echo "done · $(du -h "$ZIP" | cut -f1)"
echo
echo "Unsigned by Apple: after downloading, users run"
echo "  xattr -dr com.apple.quarantine /Applications/Nomeus.app"
echo "or right-click → Open the first time."
