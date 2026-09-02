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
BREW_MAJOR="$(brew --version | head -1 | sed -E 's/^Homebrew ([0-9]+).*/\1/')"
[[ "${BREW_MAJOR:-0}" -ge 6 ]]    || die "Homebrew 6+ required (Brewfile declares tap trust). Run: brew update"
touch "$RC"

# ── 1. Homebrew bundle ────────────────────────────────────────────────────────
# --no-upgrade: install what's missing, never upgrade what's present. Upgrades are a deliberate
# `brew upgrade` (later: `devkit self-update`), not a side effect of re-running the bootstrap.
log "brew bundle --no-upgrade ($DEVKIT_HOME/install/Brewfile)"
if ! brew bundle --no-upgrade --file="$DEVKIT_HOME/install/Brewfile"; then
  warn "brew bundle reported failures — check output above. Continuing with what installed."
fi

# Post-bundle verification: name every missing item with its exact fix, so a partial bundle is never silent.
MISSING=0
check() { # check <test-expr> <label> <fix-command>
  if ! eval "$1" >/dev/null 2>&1; then
    warn "missing: $2"
    printf '           fix: %s\n' "$3" >&2
    MISSING=1
  fi
}
check 'command -v mailpit'                          'mailpit'      'brew install mailpit'
check 'test -d "/Applications/PHP Monitor.app"'     'PHP Monitor'  'brew tap nicoverbruggen/homebrew-cask && brew install --cask nicoverbruggen/cask/phpmon'
check 'test -d "/Applications/LaraDumps.app"'       'LaraDumps'    'brew tap laradumps/app && brew install --cask laradumps/app/laradumps'
check 'test -d "/Applications/TablePlus.app"'       'TablePlus'    'brew install --cask tableplus'
check 'brew list --versions "php@$PHP_DEFAULT"'     "php@$PHP_DEFAULT" "brew install shivammathur/php/php@$PHP_DEFAULT"
if [[ -d "/Applications/LaraDumps.app" ]] && xattr -p com.apple.quarantine "/Applications/LaraDumps.app" >/dev/null 2>&1; then
  warn "LaraDumps.app is quarantined (unnotarized build). If macOS reports it as damaged on launch, run:"
  printf '           xattr -dr com.apple.quarantine "/Applications/LaraDumps.app"\n' >&2
fi
[[ "$MISSING" -eq 0 ]] || warn "run the fix commands above, then re-run this script (it is idempotent)"

# ── 2. Composer global bin on PATH ────────────────────────────────────────────
COMPOSER_BIN="$(composer global config bin-dir --absolute 2>/dev/null || echo "$HOME/.composer/vendor/bin")"
export PATH="$PATH:$COMPOSER_BIN"
# Composer bin goes LAST: `valet` must resolve to <brew>/bin/valet, the path `valet trust`
# whitelists in sudoers. An earlier devkit install prepended it; migrate that line in place.
OLD_LINE="export PATH=\"$COMPOSER_BIN:\$PATH\""
NEW_LINE="export PATH=\"\$PATH:$COMPOSER_BIN\""
if grep -qF -- "$OLD_LINE" "$RC"; then
  python3 - "$RC" "$OLD_LINE" "$NEW_LINE" <<'PY'
import sys
path, old, new = sys.argv[1:4]
src = open(path).read()
open(path, 'w').write(src.replace(old, new))
PY
  log "moved composer bin to the end of PATH in $RC"
fi
rc_add "$COMPOSER_BIN" "$NEW_LINE"

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
if [[ -s "$NVM_SH" ]]; then
  export NVM_DIR="$HOME/.nvm"
  # shellcheck disable=SC1090
  . "$NVM_SH"                       # sourced regardless, so section 6 can find npm
  if [[ "$SKIP_NODE" -eq 0 ]]; then
    log "nvm install --lts"
    nvm install --lts >/dev/null
  fi
fi

# ── 5. ~/.devkit ──────────────────────────────────────────────────────────────
mkdir -p "$CONFIG_DIR"/{tasks,services,logs,xdebug}
if [[ ! -f "$CONFIG_DIR/config.json" ]]; then
  log "writing $CONFIG_DIR/config.json"
  sed -e "s|__DEVKIT_HOME__|$DEVKIT_HOME|" \
      -e "s|__CODE_DIR__|$CODE_DIR|" \
      -e "s|__COMPOSER_BIN__|$COMPOSER_BIN|" \
      -e "s|__BREW_PREFIX__|$BREW_PREFIX|" \
      -e "s|__PHP_DEFAULT__|$PHP_DEFAULT|" \
      "$DEVKIT_HOME/install/config.default.json" > "$CONFIG_DIR/config.json"
else
  # Machine facts the app can't discover reliably from php-fpm's stripped env are kept current;
  # user choices (code_dir, ide, db_client, ports) are never touched.
  python3 - "$CONFIG_DIR/config.json" "$DEVKIT_HOME" "$COMPOSER_BIN" "$BREW_PREFIX" <<'PY'
import json, sys
path, home, composer_bin, brew_prefix = sys.argv[1:5]
with open(path) as f:
    cfg = json.load(f)
wanted = {'home': home, 'composer_bin': composer_bin, 'brew_prefix': brew_prefix}
changed = [k for k, v in wanted.items() if cfg.get(k) != v]
for k in changed:
    cfg[k] = wanted[k]
if changed:
    with open(path, 'w') as f:
        json.dump(cfg, f, indent=2)
        f.write('\n')
print('[devkit] config.json: updated ' + ', '.join(changed) if changed else '[devkit] config.json: unchanged')
PY
  STORED_CODE_DIR="$(sed -n 's/.*"code_dir": *"\([^"]*\)".*/\1/p' "$CONFIG_DIR/config.json")"
  if [[ -n "$STORED_CODE_DIR" && "$STORED_CODE_DIR" != "$CODE_DIR" ]]; then
    warn "config.json code_dir is $STORED_CODE_DIR but this run parked $CODE_DIR — edit config.json or run: cd $STORED_CODE_DIR && valet forget"
  fi
fi

# ── 6. devkit app: deps, build, Valet link, CLI shim ─────────────────────────
# Each step is skipped when its output already exists, so re-runs are cheap.
if [[ -f "$DEVKIT_HOME/artisan" ]]; then
  # Skeleton integrity: these ship with `composer create-project`, not with devkit's slices.
  # Missing means a slice was applied by replacing directories instead of overlaying files.
  SKELETON_MISSING=0
  for f in tests/TestCase.php app/Http/Controllers/Controller.php routes/console.php bootstrap/providers.php config/app.php; do
    [[ -f "$DEVKIT_HOME/$f" ]] || { warn "skeleton file missing: $f"; SKELETON_MISSING=1; }
  done
  if [[ "$SKELETON_MISSING" -eq 1 ]]; then
    warn "restore with: rsync -a --ignore-existing --exclude .env --exclude .git /tmp/devkit-skel/ $DEVKIT_HOME/   (runbook 1a §1)"
    warn "apply slices with: unzip -o <slice>.zip -d $(dirname "$DEVKIT_HOME")/   — never by replacing folders"
  fi
  (
    cd "$DEVKIT_HOME"
    [[ -d vendor ]]       || { log "composer install"; composer install --no-interaction --prefer-dist; }
    [[ -f .env ]]         || { log "creating .env"; cp .env.example .env; php artisan key:generate --no-interaction --quiet; }
    if command -v npm >/dev/null 2>&1; then
      [[ -d node_modules ]] || { log "npm install"; npm install --no-audit --no-fund; }
      [[ -d public/build ]] || { log "npm run build"; npm run build; }
    else
      warn "npm not found (nvm not loaded?) — run: npm install && npm run build"
    fi
    if [[ ! -L "$HOME/.config/valet/Sites/devkit" ]]; then
      log "valet link devkit"
      valet link devkit
    fi
  )
else
  warn "no artisan in $DEVKIT_HOME — Laravel skeleton not scaffolded yet (see docs/runbook-1a-scaffold.html)"
fi

if [[ -f "$DEVKIT_HOME/bin/devkit" ]]; then
  [[ -x "$DEVKIT_HOME/bin/devkit" ]] || { log "restoring execute bit on bin/devkit"; chmod +x "$DEVKIT_HOME/bin/devkit"; }
  ln -sf "$DEVKIT_HOME/bin/devkit" "$BREW_PREFIX/bin/devkit"
  log "linked $BREW_PREFIX/bin/devkit"
else
  warn "bin/devkit missing — shim symlink skipped"
fi

# ── 7. Trust check ────────────────────────────────────────────────────────────
# Valet 4.12 sudo's for nearly every command. From a terminal that prompts; from php-fpm
# (the dashboard) it cannot, so dashboard actions require the NOPASSWD rule `valet trust` writes.
if [[ ! -f /etc/sudoers.d/valet ]]; then
  warn "no /etc/sudoers.d/valet — the dashboard cannot run Valet actions until you run: devkit trust   (or re-run with --trust)"
fi

# ── 8. Summary ────────────────────────────────────────────────────────────────
echo
log "done. Open a new shell (or: source $RC), then verify:"
printf '  %-10s %s\n' "php"     "$(php -v 2>/dev/null | head -1 || echo '?')"
printf '  %-10s %s\n' "valet"   "$(valet --version 2>/dev/null || echo '?')"
printf '  %-10s %s\n' "mailpit" "$(mailpit version 2>/dev/null || echo '?')"
printf '  %-10s %s\n' "parked"  "$CODE_DIR  →  http://<dir>.test"
echo
[[ -f "$DEVKIT_HOME/artisan" ]] && log "then: devkit status   ·   open http://devkit.test" || true
