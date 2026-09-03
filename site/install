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

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then G=$'\033[1;33m'; D=$'\033[2m'; R=$'\033[31m'; O=$'\033[0m'; else G=""; D=""; R=""; O=""; fi
say() { printf '%s▶%s %s\n' "$G" "$O" "$*"; }
die() { printf '%s✗%s %s\n' "$R" "$O" "$*" >&2; exit 1; }

printf '\n%snomeus%s %s— shepherd for your local stack%s\n\n' "$G" "$O" "$D" "$O"
OS="$(uname -s)"
case "$OS" in
  Darwin)
    command -v git  >/dev/null || die "git is required — run: xcode-select --install"
    command -v brew >/dev/null || die "Homebrew is required first: https://brew.sh" ;;
  Linux)
    command -v git >/dev/null || die "git is required — sudo apt install git"
    command -v apt-get >/dev/null || die "Ubuntu/Debian only for now" ;;
  *) die "unsupported OS: $OS" ;;
esac

if [[ -d "$NOMEUS_HOME/.git" ]]; then
  say "updating $NOMEUS_HOME ($NOMEUS_REF)"
  git -C "$NOMEUS_HOME" fetch --quiet origin "$NOMEUS_REF"
  git -C "$NOMEUS_HOME" checkout --quiet "$NOMEUS_REF"
  git -C "$NOMEUS_HOME" pull --ff-only --quiet
else
  say "cloning $NOMEUS_REPO ($NOMEUS_REF) → $NOMEUS_HOME"
  mkdir -p "$(dirname "$NOMEUS_HOME")"
  git clone --quiet --depth 1 --branch "$NOMEUS_REF" "$NOMEUS_REPO" "$NOMEUS_HOME"
fi

chmod +x "$NOMEUS_HOME/install/install.sh" "$NOMEUS_HOME/install/install-linux.sh" "$NOMEUS_HOME/bin/nomeus" 2>/dev/null || true
if [[ "$OS" == "Linux" ]]; then exec "$NOMEUS_HOME/install/install-linux.sh" "$@"; fi
exec "$NOMEUS_HOME/install/install.sh" "$@"
