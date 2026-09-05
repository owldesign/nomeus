# Session notes — 2026-09-03/04: 10a walked and released, then the copy rewrite

Companion to `docs/handoff.md` (which stays the canonical "where things are"). This file is the closeout of one
Claude Code session; the next session should read `handoff.md` first and this second.

## State at close

- Branch `main`, clean working tree, in sync with `origin/main`. Latest tag **v2.2.0** (Release has `Nomeus-2.2.0.zip`).
- Tests: `vendor/bin/pest` 237 passed; `apps/macos` `swift test` 30 passed; `vendor/bin/pint --test` clean on the
  changed PHP files. `npm run build` output is newer than `Layout.tsx`.
- The Mac this ran on (macOS 26.5, Xcode 26): `/Applications/Nomeus.app` is the **released 2.2.0 build** from the
  GitHub Release zip, launch-at-login on, notifications allowed; the panel reads "v2.2.0 · All services running".
- nomeus.dev serves commit `98f1323` (pages run green, verified with curl).

## Commits this session (oldest first)

| sha | subject |
|---|---|
| `19fe14d` | 10a: Nomeus.app — SwiftPM menu bar shell over the API |
| `ada425b` | 10a: runbook — CI walk (3.1, 3.2): 1-minute macos run, artifact verified |
| `dcb0664` | release: 2.2.0 |
| `4e2c121` | 10a: runbook — release walk (3.3), handoff: 10a done at v2.2.0 |
| `c9d291d` | copy: Nomeus.app replaces "not a menubar app" on the site, README and llms.txt |
| `98f1323` | copy: lead with Nomeus, not Herd Pro — new hero, a Get started section in place of the comparison table |

## What was done

1. **Runbook 10a walked from 2.1 to the end** on a real Mac; every finding is a `red` callout in
   `docs/runbook-10a-macos.html` and item 5–9 of the list in `handoff.md`. Code fixes that came out of it, all in
   `apps/macos`: `Info.plist` lost `NSAllowsLocalNetworking` (it made macOS ignore `NSAllowsArbitraryLoads`; new
   `InfoPlistTests`); `MenuBarView` renders ports with `String(port)`; `Notifier` reads its authorized flag back from
   `notificationSettings()` and Settings shows a **Notifications** row (allowed / not allowed / not asked yet).
2. **Release 2.2.0 cut** per runbook 3.3: version bump, `docs:changelog`, tag, `release.yml` test → release → app.
3. **Copy**: "not a menubar app" removed everywhere (site, README, llms.txt, landing brief note); then the Herd-first
   positioning replaced — new title/meta/OG/JSON-LD, new hero, "Get started" section instead of the comparison
   table, README "What it does" list, one low-key Herd Pro sentence under "What it costs".

## In-flight decisions (made by Claude, easy to reverse)

- **Herd Pro kept in three low-visibility places**: one sentence at the end of the site's "What it costs", one clause in
  `site/llms.txt` line 3 (so an LLM asked for "Herd alternatives" still finds it), and the hidden `keywords` meta +
  JSON-LD keywords. Removing all three is a two-minute edit if the intent is zero mentions.
- **Honesty block** on the site and README: the "Not a menubar app" item became "Not notarized" (ad-hoc signature,
  one `xattr` line) rather than being deleted, so the block still tells the truth about first launch.
- **`docs/design/landing-brief.md`** left as the historical design brief (only the honesty-block line was touched).
- **README "Then:" and "A day with it"** command blocks were left as they were; the site's new Get started section
  overlaps them on purpose (site = first look, README = fuller list).
- **The "Ask again" button** for notifications was added and then removed before commit: macOS does not re-prompt a
  denied app, so the button could not do anything. The recovery recipe lives in runbook 2.2 and troubleshooting.
- **CLAUDE.md** not edited even though its "two checkouts / `~/.nomeus/app`" line is wrong on this Mac (see Open).

## Open items (also in `handoff.md` → Open)

- `CLAUDE.md` Layout: `~/.nomeus/app` does not exist here; `~/.nomeus/config.json` `home` is `~/Code/nomeus`, so
  `nomeus self-update` pulls this checkout. Decide: fix the line, or create the second checkout; then make the
  runbooks' "other checkout" verify steps match. (For fresh installs the install script does clone to
  `~/.nomeus/app`, so the line is right for users and wrong for this dev machine.)
- Landing brief and keyword lists (above).
- `actions/upload-artifact@v4` in `macos.yml` targets Node 20 (annotation on every run); bump when v5 exists.
- Notifications: no supported reset for a denied one-shot prompt short of deleting every copy of the app,
  `killall usernoted NotificationCenter`, reinstall. Documented; a Developer ID would not change it.
- 10b candidate unchanged: doctor rows in the menu bar panel.

## Tooling notes for the next session on this Mac

- `log` is a zsh builtin — use `/usr/bin/log show --predicate 'process == "Nomeus"'`; usernoted's lines
  (`eventMessage CONTAINS "dev.nomeus.app"`) are the reliable trace for notification prompts.
- No Orca, no screenshots (terminal lacks screen-recording permission). The app was driven through System Events
  accessibility via `osascript` (recursive `UI elements` walker inside `tell application "System Events"`).
- `sfltool dumpbtm` verifies login items without sudo but can hang at the end of a long command chain; run it alone.
- zsh `nomatch`: an unmatched glob aborts the whole `&&` chain; use explicit filenames in runbook commands.
