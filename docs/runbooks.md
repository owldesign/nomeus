# Runbooks

Each slice shipped with an HTML runbook (dark console, phase-numbered, checkboxes persist in the browser).
They are the build history — and the record of every trap met on a real machine, with its fix.

| slice | runbook | what it proved |
|---|---|---|
| 0a | [install](runbook-0a-install.html) | Brewfile, Valet, `~/.nomeus`; Homebrew 6 tap trust |
| 1a | [scaffold](runbook-1a-scaffold.html) | the app + SPA shell; status snapshot |
| 1b | [sites](runbook-1b-sites.html) | Sites page; every mutation as a task (Valet restarts fpm mid-command) |
| 1c | [php](runbook-1c-php.html) | versions, `use`, install/update; the composer platform floor |
| 1d | [cli](runbook-1d-cli.html) | `ini`, `edit`, `db`, `config` |
| 2a | [services](runbook-2a-services.html) | the engine; postgres locale trap, ports, crash-loop detection |
| 2b | [services ui](runbook-2b-services-ui.html) | `/api/services`, CLI-as-task; clone lock files and permissions |
| 2c | [adopt](runbook-2c-adopt.html) | `brew services` → nomeus; MySQL lineage (9.6 → 9.7 LTS); binary pre-flight |
| 2d | [drivers](runbook-2d-drivers.html) | meilisearch, typesense (tap trust), seaweedfs, reverb (site-bound) |
| 3a | [mail](runbook-3a-mail.html) | mailpit instance, Mail page, `nomeus/client` |
| 3b | [init](runbook-3b-init.html) | `nomeus.yml`, `nomeus init` |
| 4a | [logs](runbook-4a-logs.html) | log parser/tailer, Logs page, IDE links |
| 5a | [dumps](runbook-5a-dumps.html) | dump server, prepend ini, Debug page |
| 5b | [xdebug](runbook-5b-xdebug.html) | off/on/trigger; PhpStorm settings that otherwise hang your sites |
| 6a | [consolidation](runbook-6a-consolidation.html) | doctor, self-update, install --check, docs, v1.0 |
| 7a | [rename](runbook-7a-rename.html) | devkit → nomeus: `migrate:devkit`, nomeus.yml, the one-line installer |
| 7b | [new](runbook-7b-new.html) | `nomeus new`: scaffold → nomeus.yml → init; the Claude Design briefs |
| 7c | [installer](runbook-7c-installer.html) | the install experience, nomeus.dev/install on Pages, `php:ext`, `rm` |
| 7d | [mcp](runbook-7d-mcp.html) | `nomeus mcp`: the stack as tools for Claude Desktop / Code / Cursor |
| 7e | [detect](runbook-7e-detect.html) | Xdebug detect: the ini follows the IDE via a launchd watcher |
| 7f | [node](runbook-7f-node.html) | Node versions through fnm: `node:*`, pins in init, the PHP page section |
| 7g-1 | [linux seam](runbook-7g1-linux-seam.html) | `ProcessManager` (launchd / systemd), `Platform`; no behaviour change on macOS |
| 7g-2a | [php on linux](runbook-7g2a-php-linux.html) | `PhpProvider` (brew / apt), the root helper; macOS unchanged |
| 7g-2b | [valet on linux](runbook-7g2b-valet-linux.html) | fpm sockets via the provider, brew services on systemd, `install-linux.sh`, the first VM run |
| 8a | [design](runbook-8a-design.html) | the tokens, the star LED and primitives, Status + Services on the new system |
| 8b | [design 2](runbook-8b-design.html) | Panel/Table/Tabs/Field; Sites, PHP, Tasks, Mail, Logs, Debug on the same system; aliases removed |
| 8c | [site](runbook-8c-site.html) | nomeus.dev: the designed landing page, fonts, the two screenshots |
| 9a | [public-ready](runbook-9a-public.html) | CI on every push, a Release per tag with notes from `CHANGELOG.md`, `docs:changelog`, CONTRIBUTING, issue templates; v2.0.0 |
| 9b | [agents](runbook-9b-agents.html) | agents that outlive the checkout that made them: `agents:rewrite`, the doctor check, self-update hook; php 8.5 on CI |
| 10a | [macos](runbook-10a-macos.html) | Nomeus.app: the SwiftPM menu bar shell over the API; ad-hoc signed, no developer account; the zip on every Release |
