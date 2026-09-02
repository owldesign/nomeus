# zhuk/devkit-client

In-app hooks for devkit:

- `X-Tags: <app-slug>` on every outgoing message, so the Mail page shows one inbox per app.
- When devkit's dump capture is on (its prepend file sets `DEVKIT_DUMP_SERVER`), `dump()`/`dd()`
  go to the Debug page with the request they belong to, and queries, queue jobs, rendered views,
  outgoing HTTP requests and log messages are recorded alongside them.

Installed by `devkit init` via a Composer path repository, or by hand:

    composer config repositories.devkit path ~/Code/devkit/packages/devkit-client
    composer require --dev zhuk/devkit-client:@dev

`DEVKIT_MAIL_TAG=` overrides the mail tag; `DEVKIT_DUMPS=false` turns the recorders off.
Registers only in local/development/testing.
