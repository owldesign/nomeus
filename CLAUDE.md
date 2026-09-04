<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# nomeus — how this repo is worked on

Read `docs/handoff.md` first: where the project is, what is in flight, what is open.

## The loop
- Vertical slices, numbered (`9b`, `10a`). Each one: a file-level plan → approval → code with tests → an HTML runbook
  in `docs/` (copy the head/CSS/script of the latest `runbook-*.html`; phases, numbered checkbox steps, `cmd`/`out`
  blocks, `gold`/`red`/`blue` callouts) → a row in `docs/runbooks.md`. A slice isn't done until the runbook has been
  walked on a real Mac and its findings are written back into the runbook as `red` callouts.
- Commit subjects are the changelog: `area: what changed` (`10a: Nomeus.app — SwiftPM menu bar shell over the API`).
  Never edit `CHANGELOG.md` by hand; `php artisan docs:changelog --next=X.Y.Z` builds it at release time.
- Releases: bump `'version'` in `config/nomeus.php`, `docs:changelog`, commit `release: X.Y.Z`, tag `vX.Y.Z`
  (recipe in `CONTRIBUTING.md`). The `release` workflow refuses a tag whose version doesn't match `config/nomeus.php`.
- If a command, argument or option changed: `php artisan docs:commands`, commit `docs/commands.md`.

## Tests
- `vendor/bin/pest` — every process and HTTP call is faked (`tests/Support/Fake*`), nothing touches the machine.
  CI runs it on ubuntu with `NOMEUS_PLATFORM=macos`. php 8.4 required, 8.5 reported.
- `apps/macos`: `swift test` — `NomeusCore` only, through `FakeTransport`; the fixtures are copies of today's API JSON,
  so a shape change on the PHP side goes red here. `swift test` must be green before a runbook step is written.
- Bug fixes start with the failing test. Style: `vendor/bin/pint`. Comments say why, not what.

## Rules the API enforces (learned the hard way; see runbook-10a 1.3)
- Every mutation is a task: `POST …` answers `202 {task}`; clients poll `GET /api/tasks/{id}` until `done|failed`.
- Every unsafe request must send `X-Nomeus: 1` (`RequireNomeusHeader`) or it's a 403.
- Clients never follow redirects: a secured site 301s http → https and a followed 301 turns POST into GET (405).
  `/api/status` → `dashboard.url` is the truth for the scheme.
- Anything that shells out goes through `Shell::run`. Dashboard actions: add the CLI command first, API and page after.

## Layout
- `app/Services` is the map: `ValetBridge`, `ServiceManager` + `Services/*Driver`, `TaskRunner`, `StatusService`,
  `AgentAuditor`, `Init/`, `Dumps/`, `Php/`, `Doctor/`, `Mcp/`. `app/Support` holds the value objects (`Site`,
  `ServiceInstance`, `Task`, `NomeusConfig`).
- `resources/js` is the dashboard (React, Tailwind v4, TanStack Query); `npm run build` after any change there.
- `apps/macos` is Nomeus.app (SwiftPM, macOS 14+): `Sources/NomeusCore` (client, models, `NomeusModel`),
  `Sources/Nomeus` (SwiftUI menu bar, WKWebView dashboard window, settings), `scripts/bundle.sh` (universal build,
  ad-hoc codesign, icon from `site/favicon.svg`, version from `config/nomeus.php`). No Apple developer account:
  no notarization, `xattr -dr com.apple.quarantine` is the documented first-launch step.
- Two checkouts: `~/Code/nomeus` (this one; the `nomeus` shim points at it) and `~/.nomeus/app` (what
  `nomeus self-update` maintains). Work here, verify there.
