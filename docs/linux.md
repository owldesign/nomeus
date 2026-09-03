# Nomeus on Linux

Status: **in progress** — 7g-1 (this) puts the seam in; 7g-2 adds the Linux side; 7g-3 proves it on Ubuntu 24.04.

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
| sites, php-fpm, TLS, dns | Laravel Valet | valet-linux-plus (same `~/.config/valet` layout; 7g-2 handles the differences) | `ValetBridge` |
| `brew services` adoption | launchd agents | systemd units (7g-2) | `BrewServices` |
| login persistence | launchd agents run at login | `loginctl enable-linger` (installer, 7g-2) | installer |

`Platform::name()` decides (`NOMEUS_PLATFORM=linux|macos` overrides it). On macOS nothing changed in 7g-1 beyond
the indirection; the systemd code only executes when the platform says Linux.

## Not planned

Windows. WSL2 is Linux and will work once 7g lands.
