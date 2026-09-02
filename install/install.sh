#!/usr/bin/env bash
#
# devkit — Phase 0a bootstrap (macOS)
#
#   ./install/install.sh [--trust] [--skip-node]
#
#   --trust       run `valet trust` so later valet commands don't prompt for sudo
#   --skip-node   don't `nvm install --lts` (nvm itself is still installed)
#
# Env overrides:
#   DEVKIT_CODE_DIR      directory to `valet park`      (default: ~/Code)
#   DEVKIT_PHP_DEFAULT   global PHP version for Valet   (default: 8.4)
#
# Idempotent — safe to re-run. Xdebug is deliberately NOT installed here:
# shivammathur/extensions writes an always-on conf.d ini, which defeats the
# Phase 5 "load only when debugging" toggle. Phase 5 installs and quarantines it.

set -euo pipefail

DEVKIT_HOME="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CODE_DIR="${DEVKIT_CODE_DIR:-$HOME/Code}"
PHP_DEFAULT="${DEVKIT_PHP_DEFAULT:-8.4}"
CONFIG_DIR="$HOME/.devkit"
RC="${ZDOTDIR:-$HOME}/.zshrc"
TRUST=0
SKIP_NODE=0

for arg in "$@"; do
  case "$arg" in
    --trust)     TRUST=1 ;;
    --skip-node) SKIP_NODE=1 ;;
    -h|--help)   sed -n '2,17p' "$0"; exit 0 ;;
    *)           echo "unknown flag: $arg" >&2; exit 1 ;;
  esac
done

log()  { printf '\033[1;33m[devkit]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;31m[devkit]\033[0m %s\n' "$*" >&2; }
die()  { warn "$*"; exit 1; }

# Append a line to the shell rc once, keyed on a grep pattern.
rc_add() { # rc_add <grep-pattern> <line>
  grep -qF -- "$1" "$RC" 2>/dev/null || printf '\n# devkit\n%s\n' "$2" >> "$RC"
}

# ── 0. Preconditions ──────────────────────────────────────────────────────────
[[ "$(uname -s)" == "Darwin" ]] || die "macOS only for now (Linux via Valet Linux is Phase 6)."
[[ "$EUID" -ne 0 ]]               || die "Run as your normal user, not root. Valet will sudo when it needs to."
command -v brew >/dev/null        || die "Homebrew is required first: https://brew.sh"
BREW_PREFIX="$(brew --prefix)"
touch "$RC"

# ── 1. Homebrew bundle ────────────────────────────────────────────────────────
log "brew bundle ($DEVKIT_HOME/install/Brewfile)"
if ! brew bundle --file="$DEVKIT_HOME/install/Brewfile"; then
  warn "brew bundle reported failures — check output above. Continuing with what installed."
fi
[[ -d "/Applications/LaraDumps.app" ]] || warn "LaraDumps.app not found — grab it from https://github.com/laradumps/app/releases"

# ── 2. Composer global bin on PATH ────────────────────────────────────────────
COMPOSER_BIN="$(composer global config bin-dir --absolute 2>/dev/null || echo "$HOME/.composer/vendor/bin")"
export PATH="$COMPOSER_BIN:$PATH"
rc_add "$COMPOSER_BIN" "export PATH=\"$COMPOSER_BIN:\$PATH\""

# ── 3. Valet ──────────────────────────────────────────────────────────────────
if ! command -v valet >/dev/null; then
  log "composer global require laravel/valet"
  composer global require laravel/valet
fi
log "valet install (expect a sudo prompt)"
valet install
if [[ "$TRUST" -eq 1 ]]; then
  log "valet trust"
  valet trust
fi
log "valet use php@$PHP_DEFAULT"
valet use "php@$PHP_DEFAULT"

mkdir -p "$CODE_DIR"
log "valet park $CODE_DIR"
( cd "$CODE_DIR" && valet park )

# ── 4. nvm + Node LTS ─────────────────────────────────────────────────────────
mkdir -p "$HOME/.nvm"
NVM_SH="$BREW_PREFIX/opt/nvm/nvm.sh"
rc_add "opt/nvm/nvm.sh" "export NVM_DIR=\"\$HOME/.nvm\"; [ -s \"$NVM_SH\" ] && . \"$NVM_SH\""
if [[ "$SKIP_NODE" -eq 0 && -s "$NVM_SH" ]]; then
  log "nvm install --lts"
  export NVM_DIR="$HOME/.nvm"
  # shellcheck disable=SC1090
  . "$NVM_SH"
  nvm install --lts >/dev/null
fi

# ── 5. ~/.devkit ──────────────────────────────────────────────────────────────
mkdir -p "$CONFIG_DIR"/{tasks,services,logs,xdebug}
if [[ ! -f "$CONFIG_DIR/config.json" ]]; then
  log "writing $CONFIG_DIR/config.json"
  sed -e "s|__DEVKIT_HOME__|$DEVKIT_HOME|" \
      -e "s|__CODE_DIR__|$CODE_DIR|" \
      -e "s|__PHP_DEFAULT__|$PHP_DEFAULT|" \
      "$DEVKIT_HOME/install/config.default.json" > "$CONFIG_DIR/config.json"
else
  log "$CONFIG_DIR/config.json exists — left untouched"
fi

# ── 6. CLI shim (bin/devkit lands in Phase 1a) ────────────────────────────────
if [[ -x "$DEVKIT_HOME/bin/devkit" ]]; then
  ln -sf "$DEVKIT_HOME/bin/devkit" "$BREW_PREFIX/bin/devkit"
  log "linked $BREW_PREFIX/bin/devkit"
else
  warn "bin/devkit not present yet (Phase 1a) — shim symlink skipped; re-run install.sh after 1a lands"
fi

# ── 7. Summary ────────────────────────────────────────────────────────────────
echo
log "done. Open a new shell (or: source $RC), then verify:"
printf '  %-10s %s\n' "php"     "$(php -v 2>/dev/null | head -1 || echo '?')"
printf '  %-10s %s\n' "valet"   "$(valet --version 2>/dev/null || echo '?')"
printf '  %-10s %s\n' "mailpit" "$(mailpit version 2>/dev/null || echo '?')"
printf '  %-10s %s\n' "parked"  "$CODE_DIR  →  http://<dir>.test"
echo
log "next: drop a Laravel app into $CODE_DIR and hit it. Phase 1a adds the devkit CLI + dashboard."
