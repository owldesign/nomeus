# nomeus.yml

A site's manifest. Put it in the site root; `nomeus init [path]` makes the machine match it and
skips anything already in place, so re-running after an edit is the normal workflow.

```yaml
name: smoke                 # APP_NAME and the Mail page's tag for this app  (default: directory name)
domain: smoke               # site name without the tld → smoke.test         (default: directory name)
php: "8.4"                  # nomeus isolate php@8.4 (must be installed)
node: "22"                  # written to .nvmrc only — run `nvm install` yourself
secure: true                # valet secure

services:
  - type: postgresql        # reuses the named instance, else the first instance of the type, else creates one
    version: "17"           # only used when creating
    instance: pg17
    database: smoke         # created if missing; becomes DB_DATABASE
  - type: redis
  - { type: mysql, database: shop }
  - { type: meilisearch }
  - { type: seaweedfs, bucket: smoke }     # becomes AWS_BUCKET

mail: true                  # mailpit instance + MAIL_* + MAIL_FROM_ADDRESS + NOMEUS_MAIL_TAG + the client package
client: true                # nomeus/client; implied by mail

env:                        # written verbatim; wins over computed keys
  QUEUE_CONNECTION: redis

post-init:                  # run in the site, its php first on PATH, after .env is written
  - composer install
  - php artisan migrate --force
```

## What init does, in order

1. `link` the site if not parked/linked; `secure` if asked.
2. `isolate` PHP if asked and different.
3. `.nvmrc`.
4. Each service: ensure an instance exists and runs; create the database/bucket (idempotent); collect its `.env` keys.
5. Mail: ensure the mailpit instance; `MAIL_*`.
6. The client package via a Composer path repository (`~/Code/nomeus/packages/client`).
7. `.env`: keys replaced in place (Laravel reads the first definition), new keys appended under `# nomeus`, file created from `.env.example` if missing. `APP_URL` and `APP_NAME` are always set.
8. `post-init` scripts, unless `--skip-scripts`.

`--dry-run` prints the plan with skip/run per step. A failing step stops the run and is named.

## Types

`postgresql` (`postgresql@17`, `@16`, `@15`, `@14`), `mysql` (`mysql@9.7` LTS default, `@8.4`, `@8.0`, `mysql`), `mariadb`,
`redis`, `meilisearch`, `typesense`, `seaweedfs` (S3), `reverb` (per site — `--site` in the CLI, not via nomeus.yml),
`mailpit`, `dumps` (nomeus's own). `nomeus services:available` lists them with installed formulae.
