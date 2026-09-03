#!/usr/bin/env bash
# Copies install/bootstrap.sh to site/install — the file https://nomeus.dev/install serves (GitHub Pages from /site).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cp "$HERE/install/bootstrap.sh" "$HERE/site/install"
echo "site/install ← install/bootstrap.sh"
