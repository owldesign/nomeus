# Claude Design brief — nomeus.dev

Paste this into Claude Design as the opening message.

---

**The product.** Nomeus (νομεύς, *shepherd*) is a free, open-source, self-hosted replacement for Laravel Herd Pro on macOS.
It is built on things developers already run — Laravel Valet and Homebrew — and adds what Herd charges for: multi-instance
services (Postgres, MySQL, MariaDB, Redis, Meilisearch, Typesense, an S3 store), a mail catcher with one inbox per app,
`nomeus init` from a `nomeus.yml`, a log viewer with IDE links, a dumps/debug window that needs no package in your app,
and Xdebug with honest off / on / trigger modes. One command installs it:

```
curl -fsSL https://nomeus.dev/install | bash
```

Repo: github.com/owldesign/nomeus · MIT · macOS today, Linux later.

**Audience.** Laravel and PHP developers who know Valet, have looked at Herd's pricing page, and would rather own their
local environment than subscribe to it. They read code on landing pages; they distrust hype; they respect "here is exactly
what it does and does not do".

**The page (single page, sections in this order).**

1. **Hero**: the name, one line ("Herd Pro, without the subscription — on Valet and Homebrew."), the install one-liner as
   the primary action with a copy button, a secondary "GitHub" link. A screenshot or short looping capture of the
   dashboard's Services page behind/beside it.
2. **The table**: Herd Pro feature → how Nomeus does it (the README has this table; keep it factual, one line per row).
3. **Three or four short "how it works" facts**, each with a code snippet or a screenshot: a `nomeus.yml` next to the
   `init` output; `nomeus services:adopt` taking over a `brew services` cluster; a `dump()` arriving on the Debug page
   with the request's queries beside it; `nomeus doctor`.
4. **Honesty block**: what it is not — not notarized (Nomeus.app is ad-hoc signed; the menubar line used to point at PHP Monitor, until 10a), no Windows/Linux yet, no support
   contract; and what it costs: nothing, MIT.
5. **Install** section repeating the one-liner with the two prerequisites (Xcode CLT, Homebrew) and what the script does.
6. Footer: GitHub, docs, the runbooks as "the build log", ZHUK LLC / Owl Design.

**Tone.** Plain, specific, slightly dry. No exclamation marks, no "supercharge", no "blazing". Numbers and commands over
adjectives. The name's meaning can appear once.

**Visual direction.** Should feel like the dashboard's sibling: dark, precise, with the same accent and type as the
dashboard tokens (see the dashboard brief; if you produce both, share the tokens). Readable in light mode too, since
this is a public page — dark-first with a light variant is ideal. One strong motif is enough; screenshots and code do the
rest. Wide screens should not stretch text lines past ~70 characters.

**Constraints.** Static HTML + CSS (Tailwind v4 is fine), no framework, no tracking, no cookie banner, local fonts,
works without JavaScript except the copy button. `/install` on this domain must serve (or redirect to) the raw
`install/bootstrap.sh` from the repo — the page and the script share the host. Lighthouse-friendly; the whole page
under 300 KB without the video.

**Deliverables**: the page at desktop and mobile widths, the copy for every section (I will edit), the asset list
(which screenshots to capture at what size), and the HTML/CSS ready to drop into a `site/` directory of the repo.
