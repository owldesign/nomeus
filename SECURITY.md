# Security

nomeus runs on your own machine and listens on loopback only (`LoopbackOnly` middleware on every route; mutations also need
the `X-Nomeus: 1` header, so a page you're browsing can't POST to it). `valet trust` gives it passwordless sudo for
Valet's own commands — that's the sensitive part, and it's the same grant Valet itself asks for.

If you find a way for a site nomeus serves, a page you visit, or a `nomeus.yml` you didn't write to make nomeus do something
on your behalf, don't open a public issue. Use GitHub's
[private vulnerability report](https://github.com/owldesign/nomeus/security/advisories/new) for this repository.
You'll get a reply within a week, and credit in the changelog unless you'd rather not.
