# nomeus/client

In-app hooks for nomeus:

- `X-Tags: <app-slug>` on every outgoing message, so the Mail page shows one inbox per app.
- When nomeus's dump capture is on (its prepend file sets `NOMEUS_DUMP_SERVER`), `dump()`/`dd()`
  go to the Debug page with the request they belong to, and queries, queue jobs, rendered views,
  outgoing HTTP requests and log messages are recorded alongside them.

Installed by `nomeus init` via a Composer path repository, or by hand:

    composer config repositories.nomeus path ~/Code/nomeus/packages/client
    composer require --dev nomeus/client:@dev

`NOMEUS_MAIL_TAG=` overrides the mail tag; `NOMEUS_DUMPS=false` turns the recorders off.
Registers only in local/development/testing.
