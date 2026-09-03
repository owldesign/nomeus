#!/usr/bin/env bash
# nomeus — Ubuntu/Debian bootstrap (status: 7g-2b; proven on Ubuntu 24.04 in 7g-3)
#
#   ./install/install-linux.sh [--skip-node] [--check] [--verbose]
#
# What it installs: apt prerequisites · ppa:ondrej/php + ppa:ondrej/nginx · php<default> (+fpm, common extensions)
# · nginx · dnsmasq · composer · valet-linux-plus (+ valet install, valet trust) · Linuxbrew + Brewfile.linux
# · the root helper + its sudoers rule · loginctl enable-linger · the app (deps, build, nomeus.test, php ini) · doctor.
#
# Env: NOMEUS_CODE_DIR (default ~/Code) · NOMEUS_PHP_DEFAULT (default 8.4) · NOMEUS_VALET_PACKAGE (default genesisweb/valet-linux-plus)

set -euo pipefail

NOMEUS_HOME="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_DIR="$HOME/.nomeus"
CODE_DIR="${NOMEUS_CODE_DIR:-$HOME/Code}"
PHP_DEFAULT="${NOMEUS_PHP_DEFAULT:-8.4}"
VALET_PKG="${NOMEUS_VALET_PACKAGE:-genesisweb/valet-linux-plus}"
RC="$HOME/.bashrc"; [[ "$(basename "${SHELL:-}")" == "zsh" ]] && RC="${ZDOTDIR:-$HOME}/.zshrc"
SKIP_NODE=0; CHECK=0; VERBOSE=0
for arg in "$@"; do
  case "$arg" in
    --skip-node) SKIP_NODE=1 ;;
    --check)     CHECK=1 ;;
    --verbose)   VERBOSE=1 ;;
    -h|--help)   sed -n '2,10p' "$0"; exit 0 ;;
    *)           echo "unknown flag: $arg" >&2; exit 1 ;;
  esac
done

# shellcheck source=lib/ui.sh
. "$NOMEUS_HOME/install/lib/ui.sh"
UI_VERBOSE=$VERBOSE
ui_init "$CONFIG_DIR/install.log"
VERSION="$(sed -n "s/.*'version' => '\([^']*\)'.*/\1/p" "$NOMEUS_HOME/config/nomeus.php" 2>/dev/null || true)"
ui_banner "${VERSION:+v$VERSION} · linux"

BREW_PREFIX="/home/linuxbrew/.linuxbrew"; [[ -x "$BREW_PREFIX/bin/brew" ]] || BREW_PREFIX="$HOME/.linuxbrew"
COMPOSER_BIN="$HOME/.config/composer/vendor/bin"; [[ -d "$HOME/.composer/vendor/bin" ]] && COMPOSER_BIN="$HOME/.composer/vendor/bin"
HELPER=/usr/local/bin/nomeus-helper
PHP="php$PHP_DEFAULT"
rc_add() { grep -qF -- "$1" "$RC" 2>/dev/null || { printf '\n# nomeus\n%s\n' "$2" >> "$RC"; }; }
apt_installed() { dpkg -s "$1" >/dev/null 2>&1; }

if [[ -f "$CONFIG_DIR/config.json" && -z "${NOMEUS_CODE_DIR:-}" ]]; then
  STORED="$(sed -n 's/.*"code_dir": *"\([^"]*\)".*/\1/p' "$CONFIG_DIR/config.json")"
  [[ -n "$STORED" ]] && CODE_DIR="${STORED/#\~/$HOME}"
fi

# ── --check ───────────────────────────────────────────────────────────────────
if [[ "$CHECK" -eq 1 ]]; then
  OK=0; MISSING=0
  row() { if eval "$1" >/dev/null 2>&1; then printf '  %s✓%s %-30s\n' "$C_GREEN" "$C_OFF" "$2"; OK=$((OK+1)); else printf '  %s✗%s %-30s %s%s%s\n' "$C_RED" "$C_OFF" "$2" "$C_DIM" "$3" "$C_OFF"; MISSING=$((MISSING+1)); fi; }
  ui_info "install-linux --check · $NOMEUS_HOME"
  row 'grep -rq "ondrej/php" /etc/apt/sources.list.d/'          'ppa:ondrej/php'              'sudo add-apt-repository -y ppa:ondrej/php'
  row "apt_installed $PHP-fpm"                                    "$PHP + fpm"                  "sudo apt install $PHP-cli $PHP-fpm …"
  row 'apt_installed nginx'                                       'nginx'                       'sudo apt install nginx'
  row 'apt_installed dnsmasq'                                     'dnsmasq'                     'sudo apt install dnsmasq'
  row 'command -v composer'                                       'composer'                    'install-linux.sh installs it'
  row 'command -v valet || test -x "$COMPOSER_BIN/valet"'          'valet-linux-plus'            "composer global require $VALET_PKG"
  row 'test -f "$HOME/.config/valet/config.json"'                 'valet install done'          'valet install'
  row 'test -f /etc/sudoers.d/valet'                               'valet trusted'               'valet trust'
  row 'test -x "$BREW_PREFIX/bin/brew"'                            'linuxbrew'                   'https://brew.sh (Linux)'
  row 'test -x "$BREW_PREFIX/bin/fnm"'                             'fnm'                         'brew install fnm'
  row 'test -x "$HELPER"'                                          'root helper'                 "sudo install -m 0755 install/linux/nomeus-helper $HELPER"
  row 'test -f /etc/sudoers.d/nomeus'                              'sudoers rule'                'install-linux.sh writes it'
  row '[[ "$(loginctl show-user "$USER" -p Linger --value 2>/dev/null)" == yes ]]' 'linger'      "sudo loginctl enable-linger $USER"
  row 'test -f "$CONFIG_DIR/config.json"'                          '~/.nomeus/config.json'       'install-linux.sh writes it'
  row 'test -f "$NOMEUS_HOME/vendor/autoload.php"'                 'composer deps'               'composer install'
  row 'test -f "$NOMEUS_HOME/.env"'                                '.env'                        'cp .env.example .env && php artisan key:generate'
  row 'test -f "$NOMEUS_HOME/public/build/manifest.json"'          'dashboard build'             'npm install && npm run build'
  row 'test -L "$HOME/.config/valet/Sites/nomeus"'                 'nomeus.test linked'          'valet link nomeus'
  row 'test -x "$HOME/.local/bin/nomeus"'                          'nomeus on PATH'              "ln -sf $NOMEUS_HOME/bin/nomeus ~/.local/bin/nomeus"
  row 'test -f "$CONFIG_DIR/php/prepend.php"'                      'dumps prepend'               'nomeus dumps:install --restart'
  printf '  %s%d done, %d todo%s\n' "$C_DIM" "$OK" "$MISSING" "$C_OFF"
  [[ "$MISSING" -eq 0 ]] && command -v nomeus >/dev/null && { echo; nomeus doctor; }
  exit $(( MISSING > 0 ))
fi

# ── 0. Preconditions ──────────────────────────────────────────────────────────
[[ "$(uname -s)" == "Linux" ]]     || { ui_warn "this is the Linux installer; use install.sh on macOS"; exit 1; }
[[ "$EUID" -ne 0 ]]                 || { ui_warn "run as your normal user (sudo is asked for where needed)"; exit 1; }
command -v apt-get >/dev/null       || { ui_warn "apt-get not found — Ubuntu/Debian only for now"; exit 1; }
touch "$RC"
ui_done "$(. /etc/os-release && echo "$PRETTY_NAME")"

# ── 1. apt prerequisites, PPAs, php, nginx, dnsmasq ───────────────────────────
ui_step_visible "apt prerequisites (asks for your password)" sudo apt-get install -y build-essential curl git file procps ca-certificates software-properties-common unzip lsb-release
if grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then ui_done "ppa:ondrej/php"; else
  ui_step_visible "ppa:ondrej/php + ppa:ondrej/nginx" sudo bash -c 'add-apt-repository -y ppa:ondrej/php && add-apt-repository -y ppa:ondrej/nginx && apt-get update'
fi
if apt_installed "$PHP-fpm"; then ui_done "$PHP + fpm"; else
  ui_hint "sudo apt install $PHP-cli $PHP-fpm $PHP-common …"
  ui_step_visible "$PHP (+fpm, common extensions)" sudo apt-get install -y "$PHP-cli" "$PHP-fpm" "$PHP-common" "$PHP-mbstring" "$PHP-xml" "$PHP-curl" "$PHP-zip" "$PHP-intl" "$PHP-bcmath" "$PHP-gd" "$PHP-pgsql" "$PHP-mysql" "$PHP-sqlite3" "$PHP-readline" "$PHP-opcache"
fi
if apt_installed nginx && apt_installed dnsmasq; then ui_done "nginx + dnsmasq"; else
  ui_step_visible "nginx + dnsmasq" sudo apt-get install -y nginx dnsmasq
fi

# ── 2. composer, valet-linux-plus ─────────────────────────────────────────────
if command -v composer >/dev/null; then ui_done "composer $(composer --version 2>/dev/null | awk '{print $3}')"; else
  get_composer() { "$PHP" -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" && sudo "$PHP" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && rm -f /tmp/composer-setup.php; }
  ui_step_visible "composer (getcomposer.org → /usr/local/bin)" get_composer
fi
export PATH="$PATH:$COMPOSER_BIN:$HOME/.local/bin"
rc_add "$COMPOSER_BIN" "export PATH=\"\$PATH:$COMPOSER_BIN:\$HOME/.local/bin\""
if command -v valet >/dev/null; then ui_done "valet $(valet --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"; else
  ui_hint "composer global require $VALET_PKG"
  ui_step "valet-linux-plus (composer global require)" composer global require "$VALET_PKG" --no-interaction
fi
if [[ -f "$HOME/.config/valet/config.json" ]]; then ui_done "valet install"; else
  ui_step_visible "valet install (nginx + dnsmasq config; asks for your password)" valet install
fi
if [[ -f /etc/sudoers.d/valet ]]; then ui_done "valet trust"; else
  ui_step_visible "valet trust" valet trust || ui_warn "valet trust unavailable in this valet build — dashboard actions needing sudo will prompt"
fi
mkdir -p "$CODE_DIR"
if grep -q "\"$CODE_DIR\"" "$HOME/.config/valet/config.json" 2>/dev/null; then ui_done "parked $CODE_DIR"; else
  park() { cd "$CODE_DIR" && valet park; }
  ui_step "valet park $CODE_DIR" park
fi

# ── 3. Linuxbrew: services and fnm ────────────────────────────────────────────
if [[ -x "$BREW_PREFIX/bin/brew" ]]; then ui_done "linuxbrew $("$BREW_PREFIX/bin/brew" --version | head -1 | sed 's/^Homebrew //')" "$BREW_PREFIX"; else
  ui_step_visible "Linuxbrew (brew.sh installer; a few minutes)" bash -c 'NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"'
  BREW_PREFIX="/home/linuxbrew/.linuxbrew"
fi
rc_add "brew shellenv" "eval \"\$($BREW_PREFIX/bin/brew shellenv)\""
eval "$("$BREW_PREFIX/bin/brew" shellenv)"
if brew bundle check --no-upgrade --file="$NOMEUS_HOME/install/Brewfile.linux" >/dev/null 2>&1; then ui_done "Brewfile.linux"; else
  ui_hint "brew bundle --no-upgrade --file=install/Brewfile.linux"
  ui_step "Brewfile.linux (fnm, mailpit)" brew bundle --no-upgrade --file="$NOMEUS_HOME/install/Brewfile.linux"
fi
if [[ -x "$BREW_PREFIX/bin/fnm" ]]; then
  rc_add "fnm env" 'eval "$(fnm env --use-on-cd --shell bash)"'
  eval "$("$BREW_PREFIX/bin/fnm" env --shell bash)"
  if [[ "$SKIP_NODE" -eq 0 ]]; then
    if "$BREW_PREFIX/bin/fnm" ls 2>/dev/null | grep -q 'v[0-9]'; then ui_done "node $("$BREW_PREFIX/bin/fnm" current 2>/dev/null || echo)"; else
      ui_step "node lts (fnm install --lts)" "$BREW_PREFIX/bin/fnm" install --lts
      "$BREW_PREFIX/bin/fnm" default lts-latest >/dev/null 2>&1 || true
    fi
  fi
fi

# ── 4. the root helper, sudoers, linger ───────────────────────────────────────
if [[ -x "$HELPER" ]] && cmp -s "$HELPER" "$NOMEUS_HOME/install/linux/nomeus-helper"; then ui_done "root helper" "$HELPER"; else
  ui_step_visible "root helper → $HELPER" sudo install -m 0755 "$NOMEUS_HOME/install/linux/nomeus-helper" "$HELPER"
fi
if [[ -f /etc/sudoers.d/nomeus ]] && sudo -n "$HELPER" restart-fpm "$PHP_DEFAULT" >/dev/null 2>&1; then ui_done "sudoers rule for the helper"; else
  write_sudoers() { sed "s/__USER__/$USER/" "$NOMEUS_HOME/install/linux/sudoers.nomeus" | sudo tee /etc/sudoers.d/nomeus >/dev/null && sudo chmod 0440 /etc/sudoers.d/nomeus && sudo visudo -cf /etc/sudoers.d/nomeus; }
  ui_step_visible "sudoers: NOPASSWD for $HELPER only" write_sudoers
fi
if [[ "$(loginctl show-user "$USER" -p Linger --value 2>/dev/null)" == yes ]]; then ui_done "linger" "services survive logout"; else
  ui_step_visible "loginctl enable-linger $USER" sudo loginctl enable-linger "$USER"
fi

# ── 5. ~/.nomeus, the app ─────────────────────────────────────────────────────
mkdir -p "$CONFIG_DIR"/{tasks,services,dumps,php} "$HOME/.local/bin" "$HOME/.config/systemd/user"
write_config() {
  if [[ ! -f "$CONFIG_DIR/config.json" ]]; then
    sed -e "s|__NOMEUS_HOME__|$NOMEUS_HOME|" -e "s|__CODE_DIR__|$CODE_DIR|" -e "s|__COMPOSER_BIN__|$COMPOSER_BIN|" \
        -e "s|__BREW_PREFIX__|$BREW_PREFIX|" -e "s|__PHP_DEFAULT__|$PHP_DEFAULT|" \
        "$NOMEUS_HOME/install/config.default.json" > "$CONFIG_DIR/config.json"
  fi
}
if [[ -f "$CONFIG_DIR/config.json" ]]; then ui_done "~/.nomeus/config.json"; else ui_step "~/.nomeus/config.json" write_config; fi
cd "$NOMEUS_HOME"
if [[ -f vendor/autoload.php ]]; then ui_done "composer install"; else ui_step "composer install" composer install --no-interaction --prefer-dist --no-progress; fi
make_env() { cp .env.example .env && "$PHP" artisan key:generate --no-interaction --quiet; }
if [[ -f .env ]]; then ui_done ".env"; else ui_step ".env + APP_KEY" make_env; fi
if command -v npm >/dev/null 2>&1; then
  if [[ -d node_modules ]]; then ui_done "npm install"; else ui_step "npm install" npm install --no-audit --no-fund; fi
  if [[ -f public/build/manifest.json ]]; then ui_done "dashboard build"; else ui_step "dashboard build (npm run build)" npm run build; fi
else
  ui_warn "npm not found — later: npm install && npm run build"
fi
if [[ -L "$HOME/.config/valet/Sites/nomeus" ]]; then ui_done "nomeus.test linked"; else ui_step "valet link nomeus" valet link nomeus; fi
if [[ -f "$HOME/.config/valet/Certificates/nomeus.test.crt" ]]; then ui_done "nomeus.test secured"; else
  ui_step_visible "valet secure nomeus" valet secure nomeus || ui_warn "secure failed — http works; retry: valet secure nomeus"
fi
[[ -x bin/nomeus ]] || chmod +x bin/nomeus
if [[ -L "$HOME/.local/bin/nomeus" && "$(readlink "$HOME/.local/bin/nomeus")" == "$NOMEUS_HOME/bin/nomeus" ]]; then ui_done "nomeus on PATH" "~/.local/bin/nomeus"; else
  shim() { ln -sf "$NOMEUS_HOME/bin/nomeus" "$HOME/.local/bin/nomeus"; }
  ui_step "nomeus → ~/.local/bin/nomeus" shim
fi
if [[ -f "$CONFIG_DIR/php/prepend.php" ]]; then ui_done "php ini (99-nomeus.ini)"; else
  ui_hint "nomeus dumps:install --restart"
  ui_step "php ini (nomeus dumps:install --restart)" bin/nomeus dumps:install --restart
fi

# ── 6. doctor + summary ───────────────────────────────────────────────────────
DOC="$(bin/nomeus doctor 2>/dev/null | tail -1 | sed 's/\x1b\[[0-9;]*m//g' || echo 'doctor: run it')"
ui_summary \
  "${C_BOLD}nomeus${C_OFF} ${VERSION:+v$VERSION }is installed on Linux    ${C_BLUE}http://nomeus.test${C_OFF}" \
  "doctor   $DOC" \
  "next     ${C_GOLD}nomeus mail --create${C_OFF}   ${C_GOLD}nomeus services:create postgresql${C_OFF}   ${C_GOLD}nomeus new shop${C_OFF}" \
  "shell    open a new terminal (or: source $RC)"
