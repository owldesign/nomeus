#!/usr/bin/env bash
#
# nomeus — one-line install (macOS)
#
#   curl -fsSL https://nomeus.dev/install | bash
#   curl -fsSL https://nomeus.dev/install | bash -s -- --trust
#
# Clones (or updates) the app into $NOMEUS_HOME (default ~/.nomeus/app) and hands over to
# install/install.sh, which is idempotent and ends with `nomeus doctor`.
#
# Env: NOMEUS_HOME (checkout location) · NOMEUS_REPO (git url) · NOMEUS_REF (branch or tag)

set -euo pipefail

NOMEUS_HOME="${NOMEUS_HOME:-$HOME/.nomeus/app}"
NOMEUS_REPO="${NOMEUS_REPO:-https://github.com/owldesign/nomeus.git}"
NOMEUS_REF="${NOMEUS_REF:-main}"

say()  { printf '\033[1;33m[nomeus]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[nomeus]\033[0m %s\n' "$*" >&2; exit 1; }

[[ "$(uname -s)" == "Darwin" ]] || die "macOS only for now."
command -v git  >/dev/null || die "git is required — run: xcode-select --install"
command -v brew >/dev/null || die "Homebrew is required first: https://brew.sh"

if [[ -d "$NOMEUS_HOME/.git" ]]; then
  say "updating $NOMEUS_HOME"
  git -C "$NOMEUS_HOME" fetch --quiet origin "$NOMEUS_REF"
  git -C "$NOMEUS_HOME" checkout --quiet "$NOMEUS_REF"
  git -C "$NOMEUS_HOME" pull --ff-only --quiet
else
  say "cloning $NOMEUS_REPO ($NOMEUS_REF) → $NOMEUS_HOME"
  mkdir -p "$(dirname "$NOMEUS_HOME")"
  git clone --quiet --depth 1 --branch "$NOMEUS_REF" "$NOMEUS_REPO" "$NOMEUS_HOME"
fi

chmod +x "$NOMEUS_HOME/install/install.sh" "$NOMEUS_HOME/bin/nomeus"
exec "$NOMEUS_HOME/install/install.sh" "$@"
