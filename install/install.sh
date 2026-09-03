#!/usr/bin/env bash
# nomeus — macOS bootstrap
#
#   ./install/install.sh [--trust] [--skip-node] [--check] [--verbose]
#
#   --trust       run `valet trust` so later valet commands don't prompt for sudo
#   --skip-node   don't `fnm install --lts` (fnm itself is still installed)
#   --check       report what is and isn't in place; change nothing
#   --verbose     stream every step's output instead of the spinner
#
# Env overrides:
#   NOMEUS_CODE_DIR      directory to `valet park`      (default: ~/Code, or config.json's code_dir)
#   NOMEUS_PHP_DEFAULT   global PHP version for Valet   (default: 8.4)
#
# Idempotent — safe to re-run; every step is skipped when its result exists. Output of each step goes to
# ~/.nomeus/install.log; a failing step prints its last lines and stops. Ends with `nomeus doctor`.
# Xdebug and PHP extensions are installed on demand (`nomeus xdebug:install`, `nomeus php:ext`).

set -euo pipefail

NOMEUS_HOME="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_DIR="$HOME/.nomeus"
CODE_DIR="${NOMEUS_CODE_DIR:-$HOME/Code}"
PHP_DEFAULT="${NOMEUS_PHP_DEFAULT:-8.4}"
RC="${ZDOTDIR:-$HOME}/.zshrc"; [[ -n "${BASH_VERSION:-}" && "$(basename "${SHELL:-}")" == "bash" ]] && RC="$HOME/.bash_profile"
TRUST=0; SKIP_NODE=0; CHECK=0; VERBOSE=0

for arg in "$@"; do
  case "$arg" in
    --trust)     TRUST=1 ;;
    --skip-node) SKIP_NODE=1 ;;
    --check)     CHECK=1 ;;
    --verbose)   VERBOSE=1 ;;
    -h|--help)   sed -n '2,17p' "$0"; exit 0 ;;
    *)           echo "unknown flag: $arg" >&2; exit 1 ;;
  esac
done

# shellcheck source=lib/ui.sh
. "$NOMEUS_HOME/install/lib/ui.sh"
UI_VERBOSE=$VERBOSE
ui_init "$CONFIG_DIR/install.log"
VERSION="$(sed -n "s/.*'version' => '\([^']*\)'.*/\1/p" "$NOMEUS_HOME/config/nomeus.php" 2>/dev/null || true)"
ui_banner "${VERSION:+v$VERSION}"

# an existing config.json is the truth for code_dir (the default is only for a first install)
if [[ -f "$CONFIG_DIR/config.json" && -z "${NOMEUS_CODE_DIR:-}" ]]; then
  STORED="$(sed -n 's/.*"code_dir": *"\([^"]*\)".*/\1/p' "$CONFIG_DIR/config.json")"
  [[ -n "$STORED" ]] && CODE_DIR="${STORED/#\~/$HOME}"
fi
COMPOSER_BIN="$(composer global config bin-dir --absolute 2>/dev/null || echo "$HOME/.composer/vendor/bin")"
BREW_PREFIX="$(brew --prefix 2>/dev/null || echo /opt/homebrew)"

# ── --check: what is in place, nothing changed ────────────────────────────────
if [[ "$CHECK" -eq 1 ]]; then
  OK=0; MISSING=0
  row() { # row <test-expr> <label> <fix-when-missing>
    if eval "$1" >/dev/null 2>&1; then printf '  %s✓%s %-30s\n' "$C_GREEN" "$C_OFF" "$2"; OK=$((OK+1));
    else printf '  %s✗%s %-30s %s%s%s\n' "$C_RED" "$C_OFF" "$2" "$C_DIM" "$3" "$C_OFF"; MISSING=$((MISSING+1)); fi
  }
  ui_info "install --check · $NOMEUS_HOME"
  row 'command -v brew'                                            'homebrew'                    'https://brew.sh'
  row '[[ "$(brew --version | head -1 | sed -E "s/^Homebrew ([0-9]+).*/\1/")" -ge 6 ]]' 'homebrew 6+' 'brew update'
  row 'brew bundle check --no-upgrade --file="$NOMEUS_HOME/install/Brewfile"' 'Brewfile satisfied' 'brew bundle --no-upgrade --file=install/Brewfile'
  row 'grep -qF -- "$COMPOSER_BIN" "$RC"'                           'composer bin in PATH (rc)'  "add: export PATH=\"\$PATH:$COMPOSER_BIN\""
  row 'command -v valet'                                           'valet installed'             'composer global require laravel/valet'
  row 'test -f "$HOME/.config/valet/config.json"'                  'valet install done'          'valet install'
  row 'test -f /etc/sudoers.d/valet'                               'valet trusted'               'valet trust'
  row 'brew list --versions "php@$PHP_DEFAULT"'                    "php@$PHP_DEFAULT"            "brew install shivammathur/php/php@$PHP_DEFAULT"
  row 'grep -q "\"$CODE_DIR\"" "$HOME/.config/valet/config.json"' "parked $CODE_DIR"          "cd $CODE_DIR && valet park"
  row 'test -x "$BREW_PREFIX/bin/fnm"'                              'fnm'                         'brew install fnm'
  row 'test -f "$CONFIG_DIR/config.json"'                          '~/.nomeus/config.json'       'install.sh writes it'
  row 'test -f "$NOMEUS_HOME/vendor/autoload.php"'                 'composer deps'               'composer install'
  row 'test -f "$NOMEUS_HOME/.env"'                                '.env'                        'cp .env.example .env && php artisan key:generate'
  row 'test -f "$NOMEUS_HOME/public/build/manifest.json"'          'dashboard build'             'npm install && npm run build'
  row 'test -L "$HOME/.config/valet/Sites/nomeus"'                 'nomeus.test linked'          'valet link nomeus'
  row 'test -f "$HOME/.config/valet/Certificates/nomeus.test.crt"' 'nomeus.test secured'         'nomeus secure nomeus'
  row 'test -L "$BREW_PREFIX/bin/nomeus" && test -x "$(readlink "$BREW_PREFIX/bin/nomeus")"' 'nomeus shim' "ln -sf $NOMEUS_HOME/bin/nomeus $BREW_PREFIX/bin/nomeus"
  row 'test -f "$CONFIG_DIR/php/prepend.php"'                      'dumps prepend'               'nomeus dumps:install --restart'
  printf '  %s%d done, %d todo%s\n' "$C_DIM" "$OK" "$MISSING" "$C_OFF"
  [[ "$MISSING" -eq 0 ]] && command -v nomeus >/dev/null && { echo; nomeus doctor; }
  exit $(( MISSING > 0 ))
fi

# ── 0. Preconditions ──────────────────────────────────────────────────────────
[[ "$(uname -s)" == "Darwin" ]] || { ui_warn "macOS only for now."; exit 1; }
[[ "$EUID" -ne 0 ]]               || { ui_warn "Run as your normal user, not root. Valet will sudo when it needs to."; exit 1; }
command -v brew >/dev/null        || { ui_warn "Homebrew is required first: https://brew.sh"; exit 1; }
BREW_MAJOR="$(brew --version | head -1 | sed -E 's/^Homebrew ([0-9]+).*/\1/')"
[[ "${BREW_MAJOR:-0}" -ge 6 ]]    || { ui_warn "Homebrew 6+ required (Brewfile declares tap trust). Run: brew update"; exit 1; }
touch "$RC"
ui_done "homebrew $(brew --version | head -1 | sed 's/^Homebrew //')" "$BREW_PREFIX"

# ── 1. Homebrew bundle ────────────────────────────────────────────────────────
# --no-upgrade: install what's missing, never upgrade what's present. Upgrades are a deliberate
# `brew upgrade` (or `nomeus self-update`), not a side effect of re-running the bootstrap.
if brew bundle check --no-upgrade --file="$NOMEUS_HOME/install/Brewfile" >/dev/null 2>&1; then
  ui_done "Brewfile"
else
  ui_hint "brew bundle --no-upgrade --file=install/Brewfile   (the log names the formula)"
  ui_step "Brewfile  (nginx dnsmasq php@$PHP_DEFAULT composer fnm mailpit tableplus …)" brew bundle --no-upgrade --file="$NOMEUS_HOME/install/Brewfile"
fi

# ── 2. PATH: composer's global bin, last ──────────────────────────────────────
rc_add() { grep -qF -- "$1" "$RC" || { printf '\n# nomeus\n%s\n' "$2" >> "$RC"; }; }
export PATH="$PATH:$COMPOSER_BIN"
# Composer bin goes LAST: `valet` must resolve to <brew>/bin/valet, the path `valet trust` whitelists in sudoers.
OLD_LINE="export PATH=\"$COMPOSER_BIN:\$PATH\""
NEW_LINE="export PATH=\"\$PATH:$COMPOSER_BIN\""
if grep -qF -- "$OLD_LINE" "$RC"; then
  python3 - "$RC" "$OLD_LINE" "$NEW_LINE" <<'PY'
import sys
path, old, new = sys.argv[1:4]
src = open(path).read()
open(path, 'w').write(src.replace(old, new))
PY
fi
if grep -qF -- "$COMPOSER_BIN" "$RC"; then ui_done "composer bin on PATH" "$RC"; else rc_add "$COMPOSER_BIN" "$NEW_LINE"; ui_step "composer bin on PATH ($RC)" true; fi

# ── 3. Valet ──────────────────────────────────────────────────────────────────
if command -v valet >/dev/null; then ui_done "valet $(valet --version 2>/dev/null | sed 's/^Laravel Valet //' || echo)"; else
  ui_hint "composer global require laravel/valet"
  ui_step "valet (composer global require)" composer global require laravel/valet --no-interaction
fi
if [[ -f "$HOME/.config/valet/config.json" ]]; then ui_done "valet install"; else
  ui_step_visible "valet install  (asks for your password)" valet install
fi
if [[ -f /etc/sudoers.d/valet ]]; then ui_done "valet trust" "sudoers rule present"; elif [[ "$TRUST" -eq 1 ]]; then
  ui_step_visible "valet trust  (asks for your password)" valet trust
else
  ui_warn "not trusted: dashboard actions that need sudo will fail — re-run with --trust, or: valet trust"
fi
if [[ "$(valet which-php 2>/dev/null | sed -n 's#.*/php@\([0-9.]*\)/.*#\1#p' | head -1)" == "$PHP_DEFAULT" ]] || php -v 2>/dev/null | head -1 | grep -q "PHP $PHP_DEFAULT"; then
  ui_done "php $PHP_DEFAULT"
else
  ui_hint "valet use php@$PHP_DEFAULT"
  ui_step "valet use php@$PHP_DEFAULT" valet use "php@$PHP_DEFAULT"
fi
mkdir -p "$CODE_DIR"
if grep -q "\"$CODE_DIR\"" "$HOME/.config/valet/config.json" 2>/dev/null; then ui_done "parked $CODE_DIR"; else
  park() { cd "$CODE_DIR" && valet park; }
  ui_step "valet park $CODE_DIR" park
fi

# ── 4. fnm + Node LTS ─────────────────────────────────────────────────────────
# fnm is a binary, so nomeus can call it (init installs a site's .nvmrc version; scripts run under it).
# An existing nvm is left alone — both read .nvmrc.
FNM_LINE='eval "$(fnm env --use-on-cd --shell zsh)"'
[[ "$RC" == *bash* ]] && FNM_LINE='eval "$(fnm env --use-on-cd --shell bash)"'
if [[ -x "$BREW_PREFIX/bin/fnm" ]]; then
  rc_add "fnm env" "$FNM_LINE"
  eval "$("$BREW_PREFIX/bin/fnm" env --shell bash)"
  if [[ "$SKIP_NODE" -eq 0 ]]; then
    if "$BREW_PREFIX/bin/fnm" ls 2>/dev/null | grep -q 'v[0-9]'; then ui_done "node $("$BREW_PREFIX/bin/fnm" current 2>/dev/null || echo)"; else
      ui_hint "fnm install --lts"
      ui_step "node lts (fnm install --lts)" "$BREW_PREFIX/bin/fnm" install --lts
      "$BREW_PREFIX/bin/fnm" default lts-latest >/dev/null 2>&1 || true
    fi
  fi
else
  ui_warn "fnm not found at $BREW_PREFIX/bin/fnm — npm steps will be skipped"
fi

# ── 5. ~/.nomeus ──────────────────────────────────────────────────────────────
mkdir -p "$CONFIG_DIR"/{tasks,services,dumps,php}
write_config() {
  if [[ ! -f "$CONFIG_DIR/config.json" ]]; then
    sed -e "s|__NOMEUS_HOME__|$NOMEUS_HOME|" -e "s|__CODE_DIR__|$CODE_DIR|" -e "s|__COMPOSER_BIN__|$COMPOSER_BIN|" \
        -e "s|__BREW_PREFIX__|$BREW_PREFIX|" -e "s|__PHP_DEFAULT__|$PHP_DEFAULT|" \
        "$NOMEUS_HOME/install/config.default.json" > "$CONFIG_DIR/config.json"
  else
    # Machine facts the app can't discover from php-fpm's stripped env are kept current; user choices never touched.
    python3 - "$CONFIG_DIR/config.json" "$NOMEUS_HOME" "$COMPOSER_BIN" "$BREW_PREFIX" <<'PY'
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
PY
  fi
}
if [[ -f "$CONFIG_DIR/config.json" ]]; then write_config; ui_done "~/.nomeus/config.json"; else ui_step "~/.nomeus/config.json" write_config; fi

# ── 6. the app: deps, .env, build, dashboard, shim ────────────────────────────
[[ -f "$NOMEUS_HOME/artisan" ]] || { ui_warn "no artisan in $NOMEUS_HOME — not a nomeus checkout?"; exit 1; }
cd "$NOMEUS_HOME"
if [[ -f vendor/autoload.php ]]; then ui_done "composer install"; else
  ui_hint "composer install"; ui_step "composer install" composer install --no-interaction --prefer-dist --no-progress
fi
make_env() { cp .env.example .env && php artisan key:generate --no-interaction --quiet; }
if [[ -f .env ]]; then ui_done ".env"; else ui_step ".env + APP_KEY" make_env; fi
if command -v npm >/dev/null 2>&1; then
  if [[ -d node_modules ]]; then ui_done "npm install"; else ui_hint "npm install"; ui_step "npm install" npm install --no-audit --no-fund; fi
  if [[ -f public/build/manifest.json ]]; then ui_done "dashboard build"; else ui_hint "npm run build"; ui_step "dashboard build (npm run build)" npm run build; fi
else
  ui_warn "npm not found (fnm not loaded?) — later: npm install && npm run build"
fi
if [[ -L "$HOME/.config/valet/Sites/nomeus" ]]; then ui_done "nomeus.test linked"; else ui_step "valet link nomeus" valet link nomeus; fi
if [[ -f "$HOME/.config/valet/Certificates/nomeus.test.crt" ]]; then ui_done "nomeus.test secured"; elif [[ -f /etc/sudoers.d/valet ]]; then
  ui_step "valet secure nomeus" valet secure nomeus
else
  ui_warn "nomeus.test stays http until trusted (browser clipboard needs https): valet trust && nomeus secure nomeus"
fi
[[ -x bin/nomeus ]] || chmod +x bin/nomeus
if [[ -L "$BREW_PREFIX/bin/nomeus" && "$(readlink "$BREW_PREFIX/bin/nomeus")" == "$NOMEUS_HOME/bin/nomeus" ]]; then ui_done "nomeus shim" "$BREW_PREFIX/bin/nomeus"; else
  shim() { ln -sf "$NOMEUS_HOME/bin/nomeus" "$BREW_PREFIX/bin/nomeus"; }
  ui_step "nomeus shim → $BREW_PREFIX/bin/nomeus" shim
fi

# ── 7. php ini (dumps prepend; xdebug block when present) ─────────────────────
if [[ -f "$CONFIG_DIR/php/prepend.php" ]]; then ui_done "php ini (99-nomeus.ini)"; else
  ui_hint "nomeus dumps:install --restart"
  ui_step "php ini (nomeus dumps:install)" bin/nomeus dumps:install
  ui_warn "php-fpm loads the new ini on its next restart: valet restart php   (nomeus dumps:install --restart does it)"
fi

# ── 8. doctor + summary ───────────────────────────────────────────────────────
DOC="$(bin/nomeus doctor 2>/dev/null | tail -1 | sed 's/\x1b\[[0-9;]*m//g' || echo 'doctor: run it')"
ui_summary \
  "${C_BOLD}nomeus${C_OFF} ${VERSION:+v$VERSION }is installed        ${C_BLUE}https://nomeus.test${C_OFF}" \
  "doctor   $DOC" \
  "next     ${C_GOLD}nomeus mail --create${C_OFF}   ${C_GOLD}nomeus services:create dumps${C_OFF}   ${C_GOLD}nomeus new shop${C_OFF}" \
  "shell    open a new terminal (or: source $RC) so nomeus and composer are on PATH"
