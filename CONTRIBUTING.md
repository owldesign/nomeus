# Contributing

Issues and pull requests at [github.com/owldesign/nomeus](https://github.com/owldesign/nomeus). Small, specific, tested.

## Two checkouts

The installer puts the running copy in `~/.nomeus/app` and `nomeus self-update` fast-forwards it. Don't develop there —
clone somewhere else:

```bash
git clone https://github.com/owldesign/nomeus.git ~/Code/nomeus && cd ~/Code/nomeus
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan status                          # same as `nomeus status` — the CLI is the app
```

The shim (`$(brew --prefix)/bin/nomeus`) is a symlink to `<app>/bin/nomeus` and resolves the app root through it, so to run
the dashboard and the `nomeus` command from your checkout instead, re-point the link:
`ln -sf ~/Code/nomeus/bin/nomeus $(brew --prefix)/bin/nomeus` (and `valet link nomeus` from the checkout). Point it back at
`~/.nomeus/app/bin/nomeus` when you're done.

## Tests

```bash
vendor/bin/pest                # all of it, ~10 s
vendor/bin/pest --filter=Doctor
```

Rules the suite enforces, not just asks for:

- **Nothing touches the machine.** Every `Process` and HTTP call is faked (`Process::fake`, `Http::fake`). `tests/Pest.php`
  turns on `preventStrayProcesses`, so an unfaked command throws instead of running.
- **Never the real Homebrew prefix.** A test that resolves `BrewBridge` must have pointed `nomeus.config_path` at a config whose
  `brew_prefix` is a `FakeBrew` root; the `afterEach` guard fails the test otherwise. That guard exists because a migration test
  once rewrote the php ini files on the machine it ran on.
- **Fake the world, not the class.** `tests/Support/FakeValet`, `FakeBrew`, `FakeServicesWorld` build a temp `~/.config/valet`,
  a Homebrew prefix, a `~/.nomeus` — use them rather than mocking `ValetBridge` or `ServiceManager`.
- `NOMEUS_PLATFORM=linux` runs the Linux code paths on a Mac (and `macos` runs the macOS ones on CI's ubuntu).

CI runs the suite on PHP 8.4 (the floor: the lock resolves Symfony 8), builds the dashboard, and checks that `docs/commands.md` matches the code and that
every tag has a `CHANGELOG.md` section.

## The shape of a change

- One behaviour per PR, with the test that proves it. Bug fixes start with the failing test.
- If a command, argument or option changed: `php artisan docs:commands` and commit `docs/commands.md`.
- Commit subjects are the changelog: `area: what changed` (`doctor: flag a port held by a non-nomeus process`).
  `docs:changelog` turns them into the Unreleased section at release time.
- Dashboard actions are tasks (`TaskRunner`) that run a CLI command; add the command first, the API and page after.
- Anything that shells out goes through `Shell::run` — it supplies the PATH/HOME that php-fpm doesn't, and unsets nomeus's own
  `.env` so it never leaks into a site's `artisan`.
- Style: `vendor/bin/pint` (Laravel preset). Comments say why, not what.

## Releases

Maintainers only:

```bash
sed -i '' "s/'version' => '.*'/'version' => '2.1.0'/" config/nomeus.php   # what status, doctor, mcp and self-update report
php artisan docs:changelog --next=2.1.0   # Unreleased → 2.1.0 with today's date; edit the bullets if you want
git commit -am "release: 2.1.0" && git push && git tag -a v2.1.0 -m "nomeus 2.1.0" && git push --tags
```

The `release` workflow tests the tag, checks `config/nomeus.php` matches it, and publishes the GitHub Release with that
changelog section as its notes.

## Linux

The code paths exist (`docs/linux.md`) but the Ubuntu proof doesn't. If you run it on a VM, a report of what happened —
even "it didn't" — with `nomeus doctor --json` is the most useful issue you can file right now.
