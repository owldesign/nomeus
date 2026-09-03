# Claude Design brief — the Nomeus dashboard

Paste this into Claude Design as the opening message. Attach the screenshots listed at the end.

---

**What Nomeus is.** A self-hosted replacement for Laravel Herd Pro for macOS developers: one Laravel app
that is both a CLI (`nomeus …`) and a local dashboard at `https://nomeus.test`. It manages Valet sites,
PHP versions, database/search/storage services (as launchd agents), a mail catcher with one inbox per
app, log tailing, a dump/debug window, and Xdebug. Everything the dashboard does is also a CLI command;
every mutation runs as a background *task* whose log streams into the page.

**Who uses it.** One developer, on their own Mac, many times a day, usually for ten seconds at a time:
"is postgres up", "what did that request dump", "turn Xdebug on". Sometimes for minutes: reading a stack
trace, watching a log while reproducing a bug. Never as a marketing surface — this is a tool the user
already installed.

**What exists (keep the information architecture; change how it looks and feels).**

- A left rail with seven pages: Status, Sites, PHP, Tasks, Services, Mail, Logs, Debug.
- A top status strip: linked PHP version, Valet version, LEDs for nginx / dnsmasq / php-fpm / mailpit,
  and a services count (`svc 11/11`). Polled every 5 s.
- **Status**: a few key/value rows, a "doctor" panel (ok/warn/fail rows, each with a fix), an "update nomeus" button.
- **Sites**: a table (site, type, php, tls, path, actions: secure/unsecure, isolate to a PHP version, unlink, init) plus
  two forms: "new site" (name, starter, PHP, database, services, mail, https) and "link a directory".
- **PHP**: installed versions with linked/isolation info, install/update/use actions.
- **Tasks**: a list of background tasks with state and a live log per task.
- **Services**: a table (LED, name, type/formula, version, port, pid, actions: stop/start/restart/clone/delete), rows expand to a
  `.env` block and a log tail; a create form; an "adopt from brew services" panel when applicable.
- **Mail**: apps rail (tags) · message list · preview (HTML or text).
- **Logs**: rail of sites → log files · entries with level chips, search, follow, IDE links on `file:line`.
- **Debug**: an Xdebug panel (per PHP version: off/on/trigger, IDE-listening LED) above a dumps view: capture toggle, server LED,
  "latest request only", request picker, tabs (All/Dumps/Queries/Jobs/Views/Requests/Logs) with counts, rows per kind.

**Current look.** Dark, monospace, dense — a terminal aesthetic: near-black panels, thin borders, gold as the accent,
green/red/blue LEDs, no chrome. It is legible and fast but flat and samey: every page is the same grey table, nothing says
"this is *Nomeus*", and states (running / stopped / crash-looping / stale) rely on small coloured dots.

**What I want from you.**

1. A visual identity that feels *crafted and a little playful* while staying a serious tool — think a well-made
   instrument panel, not a marketing site. Herd's dashboard is friendly and rounded; I want Nomeus to feel more
   precise and more alive than that, still dark-first (a light theme is a nice-to-have). The name is Greek
   (νομεύς, "shepherd"); a subtle mark or motif from that is welcome, no cartoons.
2. **Design tokens** for Tailwind v4 (`@theme` variables): colours (background layers, borders, text ranks, the accent,
   ok/warn/fail/info), type scale (a monospace for data and a humanist sans for labels/headings, or a good reason to stay
   mono-only), spacing rhythm, radii, shadows/glows for "live" states.
3. **Component specs**, each with states: status LED (running / stopped / starting / crash-looping / missing), the status
   strip, data table rows (with expand), action buttons (inline, destructive, confirm-inline), task progress (queued /
   running with a streaming log / done / failed), forms (the "new site" form is the most complex), tabs with counts,
   the log entry row (level, timestamp, message, expandable trace with links), the dump row (Symfony's var-dumper output
   sits inside it — style the frame, not the dump), the doctor row, empty states (there are many: "no instances yet",
   "capture is off", "no logs yet") which should teach the next command.
4. **Page mocks** for Status, Services, Debug and Sites at desktop width (this is a desktop tool; a narrow layout matters
   only down to a half-screen window).
5. Motion: only where it carries information — a task streaming, a new dump arriving, an LED changing state.
   No decorative animation.

**Constraints.** React + Tailwind v4 (core utilities only, no plugin classes), no component library. The dashboard is
served from `public/build` by the same app; assets must be local (no CDN fonts at runtime — bundle or system fonts).
Everything must remain readable at 12–13 px in the data areas; developers keep this in a small window next to an IDE.
Keep the keyboard-friendly, click-sparse feel: no modals for confirmations (inline confirm is the current pattern and it works).

**Deliverables**, in this order: tokens → components → the four pages → a short "how to apply" note mapping tokens to the
existing Tailwind class conventions (`bg-panel`, `border-line`, `text-dim`, `text-gold`, `bg-green` …).

**Attached**: screenshots of every current page (Status, Sites, PHP, Tasks, Services, Mail, Logs, Debug), the status strip,
one task in progress, one crash-looping service row, the doctor table.
