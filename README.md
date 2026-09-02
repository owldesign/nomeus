# devkit

Self-built Laravel dev environment for macOS. Laravel Valet underneath, native Homebrew binaries for
services, one Laravel app that is both the `devkit` CLI (artisan commands behind a shim) and a
React dashboard served by Valet at `devkit.test`.

Companion apps, all free: PHP Monitor (menubar), LaraDumps (dumps/queries/jobs/logs), Mailpit (mail),
TablePlus (`devkit db`).

## Install

```bash
git clone <repo> ~/Code/devkit
cd ~/Code/devkit
./install/install.sh            # add --trust to skip future sudo prompts, --skip-node to skip nvm install --lts
```

The installer bootstraps Homebrew/Valet, then (once the Laravel app is present) runs `composer install`,
builds the SPA, `valet link`s the dashboard at `http://devkit.test`, and symlinks `bin/devkit` into brew's bin.
`config/devkit.php` carries a version bumped per slice; `devkit status` and the dashboard rail show it,
so "which cut is running" is never a guess. Runbooks for each slice live in `docs/`. Slices arrive as zips — apply them with
`unzip -o <slice>.zip -d ~/Code/` (overlay only); never replace directories, which drops
skeleton files that sit next to devkit's own.

Requires Homebrew. Set `DEVKIT_CODE_DIR` to park a directory other than `~/Code`;
`DEVKIT_PHP_DEFAULT` to pick a global PHP other than 8.4.

`valet trust` (or `install.sh --trust`) is required for the dashboard: Valet escalates through
sudo for almost every command, php-fpm can't answer a prompt, and the NOPASSWD rule Valet writes
matches only `<brew>/bin/valet` — which is the path devkit always uses.

Dashboard mutations are detached tasks (`~/.devkit/tasks/`): Valet restarts nginx and fpm as
part of `secure`/`isolate`/`use`, which would sever an inline response and kill a child in the
service's process group — `task:run` detaches with `posix_setsid()` first. `devkit tasks` and
`devkit task:log <id>` are the audit trail.

`packages/devkit-client` is a Composer package sites require via a path repository; it tags outgoing
mail with the app's slug so the Mail page shows one inbox per app.

## Layout

```
bin/devkit          shim: Valet commands pass straight through, everything else → artisan
install/            Brewfile, install.sh, config template
app/Services/       ValetBridge, BrewBridge, PhpManager, TaskRunner, service drivers
app/Console/        devkit commands
app/Http/Api/       JSON API (loopback only)
resources/js/       React SPA
~/.devkit/          config.json, tasks/, services/<instance>/{service.json,data,conf,run,logs}, logs/, xdebug/
~/Library/LaunchAgents/dev.zhuk.devkit.svc.<instance>.plist   one launchd agent per service instance
```

## Phases

| Phase | Scope | Status |
|---|---|---|
| 0 | Brewfile, install.sh, config | done |
| 1 | shim, Valet passthrough, `php:*`, `db`, `edit`, `ini`; Sites + PHP pages | done |
| 2 | services engine (brew + launchd, multi-instance), `services:*`; Services page | done — postgresql, mysql, mariadb, redis, meilisearch, typesense, seaweedfs (s3), reverb |
| 3 | `init` / `dev.yml`, client package, Mailpit; Mail page | 3a done (mailpit instance, Mail page, zhuk/devkit-client); 3b init next |
| 4 | log watcher; Logs page | — |
| 5 | Xdebug toggle, then auto-detect | — |
| 6 | MCP server, Linux via Valet Linux | optional |

## CLI parity with Herd

Site management, PHP isolation, TLS, proxies, sharing, loopback, and start/stop/restart are
Valet commands and pass through unchanged. devkit adds `php:list|install|update`,
`isolate-node`, `db`, `edit`, `ini`, `site-information`, `init`, `services:*`, `debug:*`,
`logs`, `mail`, `dumps`.
