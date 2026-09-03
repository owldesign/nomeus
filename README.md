# nomeus

**Nomeus** (νομεύς, *shepherd*) — a self-hosted replacement for Laravel Herd Pro, built on Laravel Valet and Homebrew.
One Laravel app is both the CLI (`nomeus …`) and the dashboard (`https://nomeus.test`). MIT.

Everything Herd Pro sells, from free parts you already run:

| Herd Pro | nomeus |
|---|---|
| Sites, PHP versions, isolation, TLS | Valet, driven through `nomeus sites` / `php:*` and the Sites and PHP pages |
| Services (Postgres, MySQL, Redis, …) | native Homebrew binaries under nomeus-owned launchd agents — multiple instances, clone, adopt from `brew services`, doctor |
| Mail | a Mailpit instance; one inbox per app via `X-Tags` from `nomeus/client` |
| `herd init` / `herd.yml` | `nomeus init` / `nomeus.yml` — link, tls, php, node, services, databases, mail, `.env`, scripts; idempotent |
| Log viewer | every site's `storage/logs`, nginx and php-fpm logs; offset-based tail; file:line into the IDE |
| Dumps (dump(), queries, jobs, views, requests, logs) | nomeus's own dump server (VarDumper's server protocol through an `auto_prepend_file`); the request tabs via the client package |
| Xdebug | per PHP version: off / on / trigger / detect (follows the IDE), from the CLI or the Debug page |

Menubar: PHP Monitor (free). Databases: TablePlus (free tier) through `nomeus db`.

## Install

```bash
curl -fsSL https://nomeus.dev/install | bash -s -- --trust
```

That clones `github.com/owldesign/nomeus` to `~/.nomeus/app` and runs `install/install.sh`: Homebrew bundle, Valet,
`~/.nomeus`, dependencies, the dashboard build, `nomeus.test`, the php ini — ending with `nomeus doctor`. Re-running is a no-op.
From a checkout: `install/install.sh --trust`. Then:

```bash
nomeus mail --create && nomeus services:create dumps
open https://nomeus.test
```

Upgrading from the pre-rename tool ("devkit"): `nomeus migrate:devkit` once, from the new checkout.

Then, per site, a `nomeus.yml` (see [docs/nomeus-yml.md](docs/nomeus-yml.md)) and `nomeus init`.

## How it works, in ten lines

- **Valet** serves sites; nomeus reads its config and runs its CLI (`ValetBridge`). `valet trust` is required so the dashboard can act.
- **Services** are launchd user agents (`dev.nomeus.svc.<name>`) running Homebrew binaries with data under `~/.nomeus/services/<name>/`. Drivers describe each type (`app/Services/Services/*Driver.php`).
- **Every dashboard mutation is a task** (`~/.nomeus/tasks/`): a detached process running the same CLI command, because Valet restarts nginx/php-fpm mid-command.
- **php-fpm's environment is empty**; `Shell::env()` supplies PATH/HOME/locale for everything nomeus runs.
- **One ini per PHP version**, `99-nomeus.ini`, regenerated from two inputs: the dumps prepend and the Xdebug state. Nothing else touches php.ini.
- **Dumps**: the prepend file sets `VAR_DUMPER_FORMAT=server` when `~/.nomeus/dumps/capture` exists; `dumps:serve` stores what arrives in SQLite; the Debug page polls it.
- **The client package** (`packages/client`, installed by `init` via a path repository) adds the mail tag and the queries/jobs/views/requests/logs recorders.
- **The API is the product**: `routes/api.php` is what the dashboard, the CLI-as-task pattern and any future MCP server all use. Loopback only; mutations need `X-Nomeus: 1`.

## Reference

- [docs/commands.md](docs/commands.md) — every command (generated: `nomeus docs:commands`) — including `nomeus new`, `nomeus rm`, `nomeus php:ext`
- [docs/nomeus-yml.md](docs/nomeus-yml.md) — the manifest
- [docs/layout.md](docs/layout.md) — where things live: `~/.nomeus`, ports, launchd labels, ini files, sudoers
- [docs/mcp.md](docs/mcp.md) — `nomeus mcp`: your stack as tools for Claude Desktop, Claude Code, Cursor
- [docs/runbooks.md](docs/runbooks.md) — the build history, slice by slice, with every trap met on a real machine

## Development

```bash
vendor/bin/pest          # 190+ tests; Process/Http are faked, nothing touches the machine
npm run build            # dashboard (React, Tailwind v4, TanStack Query)
nomeus self-update       # pull, deps, build, ini, doctor — also a button on the Status page
```

Vertical slices, each with a runbook: see `docs/runbooks.md`. Linux is not supported yet (launchd, Valet).
