# Nomeus on Linux

Status: **in progress** — 7g-1 the seam · 7g-2a PHP on Linux · 7g-2b Valet + the installer (this) · 7g-3 proves it on Ubuntu 24.04.

## What carries over unchanged

Homebrew runs on Linux (`/home/linuxbrew/.linuxbrew`), and the `shivammathur/php` and `shivammathur/extensions`
taps build there, so everything built on `BrewBridge` is the same code: PHP versions, extensions, Xdebug, the
service formulas (postgres, mysql, mariadb, redis, meilisearch, typesense, seaweedfs, mailpit), `php:ext`, the
prepend ini.

## What is platform-specific, and where it lives

| concern | macOS | Linux | seam |
|---|---|---|---|
| per-user supervisor | launchd, `~/Library/LaunchAgents`, `launchctl` | systemd --user, `~/.config/systemd/user`, `systemctl --user` | `ProcessManager` → `LaunchdManager` / `SystemdManager` |
| default brew prefix | `/opt/homebrew`, `/usr/local` | `/home/linuxbrew/.linuxbrew`, `~/.linuxbrew` | `Platform::defaultBrewPrefixes()` |
| open a URL/file | `open` | `xdg-open` | `Shell::open()`, `Editor` |
| PHP itself | Homebrew (`shivammathur/php`), `<prefix>/etc/php/X.Y/conf.d` | apt from `ppa:ondrej/php`: `/usr/bin/phpX.Y`, `/etc/php/X.Y/{cli,fpm}/conf.d`, `phpX.Y-fpm` units | `PhpProvider` → `BrewBridge` / `AptPhp` |
| root for `/etc/php` and apt | not needed (brew is user-owned) | `install/linux/nomeus-helper` — one script, a fixed verb set, NOPASSWD for it alone | `AptPhp` |
| extensions, xdebug | `shivammathur/extensions` tap | `phpX.Y-redis`, `phpX.Y-xdebug` from the PPA; `phpdismod` as quarantine | `PhpProvider` |
| sites, php-fpm, TLS, dns | Laravel Valet | valet-linux-plus (same `~/.config/valet` layout; 7g-2b handles the differences) | `ValetBridge` |
| `brew services` adoption | launchd agents | systemd units (7g-2) | `BrewServices` |
| login persistence | launchd agents run at login | `loginctl enable-linger` (installer, 7g-2) | installer |

`Platform::name()` decides (`NOMEUS_PLATFORM=linux|macos` overrides it). On macOS nothing changed in 7g-1 beyond
the indirection; the systemd code only executes when the platform says Linux.

## Installing on Ubuntu

```bash
curl -fsSL https://nomeus.dev/install | bash        # detects Linux → install/install-linux.sh
```

The installer: apt prerequisites → `ppa:ondrej/php` + `ppa:ondrej/nginx` → `php8.4` (+fpm, common extensions) → nginx, dnsmasq
→ composer → valet-linux-plus (`valet install`, `valet trust`) → Linuxbrew + `Brewfile.linux` (fnm, mailpit) → the root helper and its
sudoers rule → `loginctl enable-linger` → the app (deps, build, `nomeus.test`, php ini) → `nomeus doctor`. `--check` reports without changing.

## Where things live on Linux

| what | path |
|---|---|
| nomeus | `~/.nomeus` (same tree as macOS), the app at `~/.nomeus/app`, `~/.local/bin/nomeus` |
| services | `~/.config/systemd/user/nomeus-svc-<name>.service`, data under `~/.nomeus/services/<name>/` |
| php | `/usr/bin/phpX.Y`, `/etc/php/X.Y/{cli,fpm}/conf.d/99-nomeus.ini`, `phpX.Y-fpm` units, `/run/php/phpX.Y-fpm.sock` |
| root helper | `/usr/local/bin/nomeus-helper`, `/etc/sudoers.d/nomeus` |
| valet | `~/.config/valet` (same layout), nginx + dnsmasq as system services |
| brew services | `~/.config/systemd/user/homebrew.<formula>.service` — adoptable by `nomeus services:adopt` |

## Not planned

Windows. WSL2 is Linux and will work once 7g lands.
