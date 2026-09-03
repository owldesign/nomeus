# Design

Output of the Claude Design session run from `dashboard-brief.md`:

- `nomeus-tokens.css` — the Tailwind v4 `@theme` (surfaces, lines, text ranks, the lantern accent, semantics, type, radii, glows, motion) and the class-name migration map.
- `nomeus-component-specs.md` — eleven components with states: the star LED, the status strip, table rows with the expand well, actions (inline / primary / destructive / confirm-inline), task progress, tabs, forms, log rows, dump rows, doctor rows, empty states.

Identity in one line: the shepherd's star — every status LED is a four-point star; running services are a flock, accounted for.
One accent (lantern amber). Mono for anything a machine produced; sans for anything a human wrote. Motion carries information only.

## Where it lives in the code

| spec | implementation |
|---|---|
| tokens, keyframes, fonts | `resources/css/app.css` (the `@theme`; aliases for pre-8a class names until 8b) · `public/fonts/` (Geist Mono, Public Sans — both OFL, self-hosted) |
| §1 LED | `components/Led.tsx` (`Led`, `Star`, `ledStateFor`) |
| §2 status strip | `components/Layout.tsx` |
| §3 rows + expand | `pages/Services.tsx` |
| §4 actions | `components/Button.tsx` (`Button`, `ConfirmInline`) |
| §5 task progress, chips | `components/TaskProgress.tsx`, `components/Chip.tsx` |
| §10 doctor rows | `pages/Status.tsx` (`FixLine` runs the printed fix as a task via `POST /api/doctor/fix`) |
| §11 empty states, kbd pills | `components/EmptyState.tsx`, `components/Kbd.tsx` |
| panel card, page header, table, labels/inputs | `components/Panel.tsx` (`Panel`, `PageHeader`, `LABEL`, `INPUT`, `INPUT_SM`), `components/Table.tsx` (`Table`, `rowClass`, cell paddings) |
| §6 tabs | `components/Tabs.tsx` (Debug) |
| §7 forms | `components/Field.tsx` (`Field`, `ToggleChip`, `Toggle`); the new-site form on Sites is the reference |
| §8 log rows | `pages/Logs.tsx` |
| §9 dump rows | `pages/Debug.tsx` (the arrival wash on new rows) |

Class map: `text-gold → text-lantern`, `bg-green → bg-ok`, `bg-red → bg-fail`, `text-blue → text-info`, `text-mute → text-faint`, `text-fg → text-text`.
After 8b no page uses the old names and the aliases are gone from the `@theme` — `grep -rn 'text-gold' resources/js` is the test.
