# zhuk/devkit-client

In-app hooks for devkit. Today: one listener that adds `X-Tags: <app-slug>` to every outgoing
message, so the devkit Mail page shows one inbox per application.

Installed by `devkit init` via a Composer path repository, or by hand:

    composer config repositories.devkit path ~/Code/devkit/packages/devkit-client
    composer require --dev zhuk/devkit-client:@dev

Override the tag with `DEVKIT_MAIL_TAG=` in `.env`. Registers only in local/development/testing.
