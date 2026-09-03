# Where things live

## `~/.nomeus/`

```
config.json                 machine facts + your choices (code_dir, ide, db_client, ports); `nomeus config:get/set`
tasks/<id>.json, <id>.log   every dashboard mutation; newest 100 kept
services/<name>/
  service.json              type, formula, version, port, options (aux ports, secrets, adopted_from, site)
  data/  conf/  run/  logs/ the instance; logs/service.log is launchd's stdout+stderr
dumps/capture               present = dump()/dd() go to the Debug page
dumps/dumps.sqlite          the dump store, newest 5,000 rows
php/prepend.php             the auto_prepend_file (generated; `nomeus dumps:install`)
php/xdebug.json             per version: xdebug.so path and last mode
```

## Homebrew

```
<prefix>/etc/php/X.Y/conf.d/99-nomeus.ini            nomeus's one ini per version: prepend + xdebug block (generated)
<prefix>/etc/php/X.Y/conf.d/20-xdebug.ini.nomeus-off  the tap's always-on ini, quarantined by `xdebug:install`
<prefix>/var/{postgresql@N,mysql,db/redis}            brew's own data dirs — left untouched by `services:adopt`
<prefix>/bin/nomeus → ~/Code/nomeus/bin/nomeus        the shim
```

## launchd (user agents, `~/Library/LaunchAgents/`)

```
dev.nomeus.svc.<name>.plist    one per service instance; KeepAlive; env = nomeus's (PATH, HOME, LC_ALL, LANG)
homebrew.mxcl.*.plist               brew services — what `services:adopt` takes over
```

`launchctl print gui/<uid>/dev.nomeus.svc.<name>` shows state, pid and last exit code.

## Ports (defaults; the next free one when taken)

| what | port |
|---|---|
| postgresql | 5432 |
| mysql / mariadb | 3306 |
| redis | 6379 |
| meilisearch | 7700 |
| typesense | 8108 (+ peering 8107) |
| seaweedfs S3 | 8333 (+ master 9333, volume 8080, filer 8888) |
| reverb | 8080 |
| mailpit | smtp 1025, ui/api 8025 |
| dumps server | 9912 |
| xdebug (IDE listens) | 9003 |

## sudo

`valet trust` writes `/etc/sudoers.d/valet` and `/etc/sudoers.d/brew`: NOPASSWD for `<prefix>/bin/valet` and `brew`.
That is what lets php-fpm-spawned tasks link sites, secure them, isolate PHP and restart fpm. nomeus never sudo's anything else.

## Valet

`~/.config/valet/` — `config.json` (tld, loopback, parked paths), `Sites/` (links), `Certificates/`, `Nginx/`, `Log/`, `*.sock` (php-fpm per version).
