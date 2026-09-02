# devkit

A self-hosted replacement for Laravel Herd Pro, built on Laravel Valet and Homebrew. One Laravel app
at `~/Code/devkit` is both the CLI (`devkit …`) and the dashboard (`https://devkit.test`).

Everything Herd Pro sells, from free parts you already run:

| Herd Pro | devkit |
|---|---|
| Sites, PHP versions, isolation, TLS | Valet, driven through `devkit sites` / `php:*` and the Sites and PHP pages |
| Services (Postgres, MySQL, Redis, …) | native Homebrew binaries under devkit-owned launchd agents — multiple instances, clone, adopt from `brew services`, doctor |
| Mail | a Mailpit instance; one inbox per app via `X-Tags` from `zhuk/devkit-client` |
| `herd init` / `herd.yml` | `devkit init` / `dev.yml` — link, tls, php, node, services, databases, mail, `.env`, scripts; idempotent |
| Log viewer | every site's `storage/logs`, nginx and php-fpm logs; offset-based tail; file:line into the IDE |
| Dumps (dump(), queries, jobs, views, requests, logs) | devkit's own dump server (VarDumper's server protocol through an `auto_prepend_file`); the request tabs via the client package |
| Xdebug | per PHP version: off / on / trigger, from the CLI or the Debug page |

Menubar: PHP Monitor (free). Databases: TablePlus (free tier) through `devkit db`.

## Quickstart

```bash
git clone <your remote> ~/Code/devkit
~/Code/devkit/install/install.sh --trust      # brew bundle, valet, ~/.devkit, deps, build, link, ini; ends with `devkit doctor`
devkit doctor                                  # every layer, with the fix for anything wrong
devkit mail --create && devkit services:create dumps
open https://devkit.test
```

Then, per site, a `dev.yml` (see [docs/dev-yml.md](docs/dev-yml.md)) and `devkit init`.

## How it works, in ten lines

- **Valet** serves sites; devkit reads its config and runs its CLI (`ValetBridge`). `valet trust` is required so the dashboard can act.
- **Services** are launchd user agents (`dev.zhuk.devkit.svc.<name>`) running Homebrew binaries with data under `~/.devkit/services/<name>/`. Drivers describe each type (`app/Services/Services/*Driver.php`).
- **Every dashboard mutation is a task** (`~/.devkit/tasks/`): a detached process running the same CLI command, because Valet restarts nginx/php-fpm mid-command.
- **php-fpm's environment is empty**; `Shell::env()` supplies PATH/HOME/locale for everything devkit runs.
- **One ini per PHP version**, `99-devkit.ini`, regenerated from two inputs: the dumps prepend and the Xdebug state. Nothing else touches php.ini.
- **Dumps**: the prepend file sets `VAR_DUMPER_FORMAT=server` when `~/.devkit/dumps/capture` exists; `dumps:serve` stores what arrives in SQLite; the Debug page polls it.
- **The client package** (`packages/devkit-client`, installed by `init` via a path repository) adds the mail tag and the queries/jobs/views/requests/logs recorders.
- **The API is the product**: `routes/api.php` is what the dashboard, the CLI-as-task pattern and any future MCP server all use. Loopback only; mutations need `X-Devkit: 1`.

## Reference

- [docs/commands.md](docs/commands.md) — every command (generated: `devkit docs:commands`)
- [docs/dev-yml.md](docs/dev-yml.md) — the manifest
- [docs/layout.md](docs/layout.md) — where things live: `~/.devkit`, ports, launchd labels, ini files, sudoers
- [docs/runbooks.md](docs/runbooks.md) — the build history, slice by slice, with every trap met on a real machine

## Development

```bash
vendor/bin/pest          # 190+ tests; Process/Http are faked, nothing touches the machine
npm run build            # dashboard (React, Tailwind v4, TanStack Query)
devkit self-update       # pull, deps, build, ini, doctor — also a button on the Status page
```

Vertical slices, each with a runbook: see `docs/runbooks.md`. Linux is not supported yet (launchd, Valet).
