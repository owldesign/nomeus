# site/ — drop into the repo

- `index.html` — the whole page, static HTML + CSS, one inline `<script>` for the copy buttons (everything else works without JS). No tracking, no cookies, dark by default with a `prefers-color-scheme: light` variant via CSS variables.
- `fonts/` — place `GeistMono[wght].woff2` and `PublicSans[wght].woff2` here (variable fonts, both OFL). Until then the page falls back to system fonts.
- `assets/` — place the two screenshots (see `nomeus-site-assets.md`):
  - `services.png` — 1600×1000, Services page, one row expanded
  - `debug.png` — 1200×660, Debug page, dump row open, Queries tab non-zero
- `/install` must serve or redirect to the raw `install/bootstrap.sh` (nginx: `location = /install { return 302 https://raw.githubusercontent.com/owldesign/nomeus/main/install/bootstrap.sh; }` or proxy it with `Content-Type: text/plain`).

Budget: HTML+CSS ≈ 16 KB; fonts ≈ 90 KB; keep both PNGs under ~180 KB combined to stay inside 300 KB.
