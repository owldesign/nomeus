# Nomeus component specs

Identity in one line: the **shepherd's star** (νομεύς — Hesperus, the evening star that brings the flock home). Every status LED is a small four-point star; the running services are a flock, accounted for. One accent — **lantern** amber — used sparingly. Mono (`Geist Mono`) for anything a machine produced; sans (`Public Sans`) for anything a human wrote. Token names below refer to `nomeus-tokens.css`.

Global rules

- Motion carries information only: pulse = becoming, flicker = failing repeatedly, marching dashes = work, a fading wash = new data. Running is *still*.
- Glow (`shadow-glow-*`) is reserved for things alive right now. Static UI never glows.
- No modals. Confirmation swaps in place.
- Data text never below 12px; tables default 12.5px, rows 36px, hairline separators `line/55%`.

---

## 1. Status LED

11px inline SVG, viewBox 0 0 16 16, path:
`M8 0C8.55 4.9 11.1 7.45 16 8C11.1 8.55 8.55 11.1 8 16C7.45 11.1 4.9 8.55 0 8C4.9 7.45 7.45 4.9 8 0Z`
(concave cubic edges — never a ✦ text glyph).

| state | rendering | motion |
| --- | --- | --- |
| running | fill `ok` + `drop-shadow(0 0 3px ok/60%)` | none — steady means healthy |
| starting | fill `warn` | `np-pulse` 1.2s ease-in-out infinite |
| stopped | fill none, stroke `faint` 1.5 — hollow star | none |
| crash-looping | fill `fail` + drop-shadow fail/70% | `np-flicker` 1.1s steps(1) infinite |
| missing | dashed circle r5.5, stroke `faint`, dasharray 2.5 2.5 — not a star at all | none |

Each state differs in shape or fill, not just color.

## 2. Status strip

44–46px bar on `inset` (darker than the page — a bezel). Left→right: 15px lantern star mark · `php` label (dim) + version (lantern 600) · `valet` + version (text 500) · 1px `line` divider · LED+name per core service (mono 12.5, `mid`; stopped service goes hollow + name drops to `dim`) · divider · `svc 10/11` (count in `text`) · spacer · polling dot: 5px `ok` circle, `np-pulse` synced to the 5s poll, label `polled 5s` (faint, 11px).

## 3. Data table row + expand

Grid columns: 36px chevron+LED · name · type · version · port · pid · actions. Header: sans 11px 600, uppercase, letter-spacing 0.14em, `dim`.

- Row: 36px, mono 12.5. Name `text` 500; type `dim` with formula `faint`; port `text`; pid `dim`.
- Hover: `raised/50%` wash.
- Crash-looping row: `fail/5%` wash across full width (9% on hover), pid cell shows `crash ×N` in `fail`.
- Stopped row: all cells `faint` except the `start` action in `ok`.
- Expand: chevron ▸/▾, click anywhere on row. Well drops to `inset`, indented under name column, bottom border `line/55%`. Contents: `.env` block (keys `dim`, values `mid`, line-height 1.7) + log tail (12px, errors in `fail`, live caret ▍ `np-blink`).

## 4. Actions

| variant | spec |
| --- | --- |
| inline | mono 12.5 `mid`, ghost (no bg/border), 4px radius, padding 3–4px 7–9px. Hover: `raised` bg, `text`. |
| primary | sans 12.5 600 `lantern`, bg `lantern/10%`, border `lantern/45%`, radius 5. Hover: bg 18% + `glow-lantern`. One per view. |
| destructive | mono 12.5 `fail/75%`, ghost. Hover: `fail/12%` bg, full `fail`. |
| confirm-inline | click swaps button for: frame border `fail/45%` bg `fail/8%` radius 5 → question (mono 12 `fail`, e.g. `delete mysql-2?`) + `no` (ghost) + `delete` (solid `fail`, text `bg`, glow on hover). Esc/no restores. Never a modal. |

## 5. Task progress

- **queued**: chip (mono 11, border `line-strong`, `dim`) + command `mid` + right meta `faint`.
- **running**: the loudest element on any page. Lantern frame (`lantern/35%` border, 4% wash), chip lantern-tinted with `glow-lantern`; 3px progress stripe `repeating-linear-gradient(90deg, lantern/55% 0 8px, lantern/12% 8px 16px)` background-size 24px, `np-dash` 0.7s linear infinite — constant rate, signals *work* not percent; log well on `inset` streaming lines (mono 12, `mid`, lh 1.7) with lantern caret ▍ `np-blink`. Elapsed timer top-right.
- **done**: quiet line — ok-tinted chip + command `mid` + `7s · log` (log = info link).
- **failed**: fail-tinted chip + command + last stderr line inline (`dim`) + `31s · log`. The inline stderr usually saves opening the log.

Chips: mono 11, letter-spacing 0.05em, padding 2px 9px, radius 3, tint = color/12–14% bg + color/45–50% border.

## 6. Tabs with counts

Mono 12.5, padding 12px 12px 10px, 2px bottom border. Active: border `lantern`, label `text`, count `lantern`. Inactive: border transparent, label `mid`, count `faint`. Zero-count: label `faint` too (still clickable). Hover: label `text`. The counts row doubles as a summary of the request.

## 7. Forms ("new site" is the reference)

- Labels: sans 11px 600 uppercase ls 0.12em `dim`, stacked 6px above field.
- Inputs/selects: mono 13 `text` on `inset`, border `line`, radius 5, padding 7px 10px. Focus: border `lantern/70%` + `0 0 8px lantern/25%`.
- Service picker: toggle chips (mono 12, radius 3) — selected: lantern tint; unselected: border `line`, `dim`, hover `line-strong`/`mid`.
- Toggles: 30×16 track radius 9. On: `lantern/25%` track, `lantern/60%` border, lantern knob right. Off: `raised` track, `line-strong` border, `dim` knob left.
- Submit: primary button, right-aligned on the last row.
- Footer (always): faint 12px — placeholder behavior note + the CLI equivalent the form will run, mono `dim`.

## 8. Log entry row

Baseline-aligned flex row, padding 9px 16px, separator `line/55%`, hover `raised/50%`. Order: chevron (faint 10px) · timestamp (mono 12 `dim`, ms precision) · level chip (11px, radius 3, LEVEL/14% bg + LEVEL text: ERROR=fail, WARN=warn, INFO=info, DEBUG=dim) · message (`text` for error, `mid` otherwise) · spacer · repeat count `+102` (faint 11).
Expanded trace: `inset` well, indented past chevron, mono 12 lh 1.8 `mid`; frames as `#N path/file.php:LINE` where `file:line` is an info link → IDE (`phpstorm://`/`vscode://`).

## 9. Dump row

Header row: timestamp `dim` · kind chip (query/dump/job/view — info tint) · duration + connection `faint` · spacer · source `file:line` info link. Body: the frame owns spacing only — 16px margins, `inset` well, radius 5; Symfony var-dumper renders inside unstyled.
Arrival: `np-arrive` 2.4s once — a lantern wash that fades; catches the eye mid-reproduction, never a bounce.

## 10. Doctor row

Padding 9px 16px, separator `line/55%`. State chip (OK/WARN/FAIL, tint per semantic) · check message (`text` when non-OK, `mid` when OK) · scope tag `dim`. Every non-OK row carries a second line: `fix:` (dim) + the exact command as a kbd pill (mono 12 `lantern` on `inset`, border `line`, radius 3) + a `run fix` outline button (hover: lantern border/text) that spawns a task.

## 11. Empty states

Centered in the panel, 36px vertical padding: hollow star (20px, `faint` stroke) · title sans 13 600 · one line `dim` 12px · the exact next command as `$ nomeus …` kbd pill (mono 12, `lantern` on `inset`), or a primary button + `or nomeus …` alternative when a click exists. The hollow star marks absence everywhere; every empty state teaches the CLI.

---

Live reference with all states interactive: `Nomeus Foundations.dc.html`.
