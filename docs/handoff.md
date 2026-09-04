# nomeus — hand-off

Repo `github.com/owldesign/nomeus` · site `nomeus.dev` · latest tag **v2.1.0** · checkout `~/Code/nomeus` (the `nomeus`
shim points at it) · 236 pest tests at the tag, 237 with 10a applied · 30 Swift tests in `apps/macos`.

## Where things are

**9b done** (v2.1.0): `ProcessManager::readAgent` (launchd/systemd), `AgentAuditor`, `agents:rewrite [--dry-run]`,
doctor rows (fail `agent <n>` with reasons + fix / ok `agents`), dashboard-fixable, self-update runs it before its
doctor, `refreshAgent` re-anchors nomeus-bound records (`site_path`/`php_bin_dir`) so `migrate:devkit` is covered too.
Scope: the dump server and the xdebug watcher only — brew-backed agents are `services:upgrade`'s job.

**10a in flight — Nomeus.app.** Applied to the checkout as an overlay, **not committed**. Runbook
`docs/runbook-10a-macos.html`; phases 0–2 walked on a real Mac (macOS 26.5, Xcode 26); the walk is at **step 3.1**
(commit, push, watch `macos.yml`). Findings, all fixed and written into the runbook as red callouts:

1. Secured `nomeus.test` → nginx 301 http→https → URLSession re-issued the POST as GET → 405 on every task route.
   Fixed twice: transport no longer follows redirects (model adopts the new origin, retries the same method once,
   persists it), and `StatusService` reports `dashboard.url` as https when the site is secured (+1 pest test).
2. 403 "Missing X-Nomeus header" — `RequireNomeusHeader` guards every unsafe method. Client sends it; the Swift
   `FakeTransport` now returns the same 403 without it.
3. Action errors vanished after one poll — `run()` set `lastError`, the refresh it triggers cleared it. Now refresh
   errors and action errors are tracked separately; action errors stay until the next action or ✕.
4. Menu bar icon is the four-point star from `site/favicon.svg` as a template image (filled ok / outline degraded /
   struck all-down / faint unreachable). `resources/js/components/Layout.tsx`: only `<main>` scrolls, sidebar and
   status strip stay (needs `npm run build`).
5. (2.2) Bundled, every poll failed with an App Transport Security error: `Info.plist` had `NSAllowsArbitraryLoads`
   *and* `NSAllowsLocalNetworking`, and macOS ignores the first whenever a narrower key is present. `swift run` has
   no Info.plist, so phase 1 never saw it. Key dropped; `InfoPlistTests` (Swift test 30) guards it.
6. (2.2) Ports rendered `:9,912` — `Text` interpolating an `Int` groups digits. `String(port)`.
7. (2.2/2.3) The notification prompt is one-shot: killed the app while it was up → status *denied*, never asked
   again, app absent from System Settings → Notifications. Reset that worked: delete every copy of the .app,
   `killall usernoted NotificationCenter`, reinstall, launch. Settings now shows a **Notifications** row read from
   `notificationSettings()`, and `Notifier.authorized` is synced from it (the request callback couldn't be observed).
8. (2.4) macOS 26 calls a quarantined ad-hoc app "damaged" — Move to Trash / Cancel only, no right-click → Open.
   `xattr -dr` is the only path there; README says so now.
9. Tooling: `log` is a zsh builtin — `/usr/bin/log show …` or you get silence. Login-item registration is checkable
   with `sfltool dumpbtm` (no sudo needed here).

What 10a adds, file by file:

| path | what |
|---|---|
| `apps/macos/Package.swift` | tools 5.10, macOS 14; targets `NomeusCore` (lib), `Nomeus` (app), `NomeusCoreTests` |
| `apps/macos/Sources/NomeusCore/` | `APIClient` (status/sites/services/tasks, start/stop/restart → task, `wait(for:)`, `rebased(to:)`), `Models` (mirrors of the API JSON), `Transport` (no-redirect URLSession), `Health`, `NomeusModel` (`@Observable`, main-actor; `call{}` does the one-retry rebase; `onBaseChange`, `dismissError`) |
| `apps/macos/Sources/Nomeus/` | `NomeusApp` (MenuBarExtra + Window + Settings; `AppDelegate` owns the model and `Poller`: 5 s open / 30 s closed), `MenuBarView`, `DashboardWindow` (WKWebView loading `dashboard.url`; off-host links to the browser), `StarIcon`, `AppSettings` (UserDefaults `dev.nomeus.app`: `baseURL`, `slowInterval`; `isBundled` gates notifications + launch-at-login), `Notifier`, `LaunchAtLogin` (`SMAppService.mainApp`) |
| `apps/macos/Resources/Info.plist` | `LSUIElement`, `dev.nomeus.app`, `__VERSION__`/`__BUILD__` filled by bundle.sh |
| `apps/macos/scripts/bundle.sh` | universal release build → `dist/Nomeus.app` → icon via `qlmanage`+`iconutil` → `codesign --sign -` → `dist/Nomeus-<version>.zip` |
| `.github/workflows/macos.yml` | `swift test` + bundle on `apps/macos/**` pushes/PRs; zip as run artifact |
| `.github/workflows/release.yml` | new `app` job after `release`: builds on macos-15, `gh release upload` the zip |
| `app/Services/StatusService.php`, `tests/Feature/Api/StatusTest.php` | `dashboard.url` honours a Valet certificate |
| `resources/js/components/Layout.tsx` | sticky shell |
| `README.md`, `docs/runbooks.md`, `.gitignore` | Nomeus.app section, 10a row, `apps/macos/{.build,dist,.swiftpm}` |

Remaining for 10a: runbook phase 3 (commit as `10a: Nomeus.app — SwiftPM menu bar shell over the API`, push, watch
`macos.yml`, run the CI artifact, cut 2.2.0 — first Release with the zip), write back anything CI teaches.

## CI
`ci.yml`: php 8.4 required, 8.5 reported (`continue-on-error`) — promote 8.5 to the required list once it has been
green a while. `macos.yml` only triggers on `apps/macos/**`, the favicon and `config/nomeus.php` (macOS minutes).

## Open
- **10b candidate**: doctor rows (9b) in the menu — "n issues · fix" line calling `/api/doctor` and `/api/doctor/fix`.
- brew-side path drift (Cellar path moving under a running agent) if it ever bites.
- Linux VM run when there's appetite (`docs/linux.md`, `install-linux.sh`; the issue template asks for
  `nomeus doctor --json`).
- Nomeus.app signing: when a Developer ID exists, notarising `bundle.sh`'s output is the only change; the
  `xattr` line leaves the README then — and on macOS 26 it is the only way in, so this matters more than it did.
- Notifications recovery: no supported way to reset a denied one-shot prompt short of deleting the app; if a user
  reports "Notifications: not allowed" in Settings, that recipe (runbook 2.2) is the answer.

## Conventions
Unchanged, and now in `CLAUDE.md`: file-level plan → approval → tests with everything faked → HTML runbook → walk it on
a real Mac → findings back into the runbook → commit with an `area: what changed` subject. Working in Claude Code:
run `swift test`, `vendor/bin/pest` and `npm run build` yourself before writing a runbook step; the runbook records
what was proved, not what was hoped.
