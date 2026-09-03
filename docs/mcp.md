# nomeus as an MCP server

`nomeus mcp` speaks the Model Context Protocol over stdio, so Claude Desktop, Claude Code, Cursor and any
other MCP client can ask your local stack questions — and flip the few switches that are safe to flip.
It runs in-process as you, with no network listener and no token: it can do exactly what your terminal can.

## Register it

```bash
nomeus mcp:install claude --write     # Claude Desktop  (~/Library/Application Support/Claude/claude_desktop_config.json)
nomeus mcp:install code  --write      # Claude Code     (claude mcp add nomeus -- /opt/homebrew/bin/nomeus mcp)
nomeus mcp:install cursor --write     # Cursor          (~/.cursor/mcp.json)
```

Without `--write` the command prints the snippet. Restart the client; "nomeus" shows up among its tools.

## Tools

| tool | what it answers |
|---|---|
| `list_sites`, `site_info(name)`, `site_env_keys(name)` | sites; one site with `artisan about` and its manifest; a site's `.env` **keys** plus the values of the driver keys only (`SESSION_DRIVER`, `CACHE_STORE`, …) — never secrets |
| `list_services`, `service_status(name)`, `service_logs(name, lines)`, `whats_on_port(port)` | instances with live status and the `.env` lines to use them; what listens on a port |
| `start_service`, `stop_service`, `restart_service` (name) | the launchd agent |
| `php_versions`, `xdebug_status`, `set_xdebug(version, mode)` | versions and isolation; Xdebug off / on / trigger (restarts php-fpm) |
| `tail_log(source, lines, level)` | a site's newest Laravel log, or `nginx` / `fpm`, parsed |
| `recent_dumps(kind, limit)`, `set_capture(on)` | the Debug page's store; capture on/off |
| `doctor(section)`, `list_tasks`, `task_log(id)` | health with fixes; background tasks |
| `init_plan(name)` | what `nomeus init` would do — plan only |

Not exposed on purpose: creating or removing sites, creating/cloning/deleting/adopting instances, installing
PHP versions or extensions, self-update. Those stay human actions in the terminal or the dashboard.

## Try it without a client

```bash
nomeus mcp --list
nomeus mcp --call=whats_on_port --args='{"port":5433}'
nomeus mcp --call=tail_log --args='{"source":"shop","level":"error","lines":5}'
```

Things to ask once it's registered: *"what's on port 5433"* · *"is redis up, and what env does shop need for it"* ·
*"show me the last errors from smoke"* · *"turn xdebug to trigger on 8.4"* · *"run the doctor and tell me what's wrong"*.
