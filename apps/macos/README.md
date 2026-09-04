# Nomeus.app

A menu bar shell over the nomeus dashboard and API that Valet already serves at `http://nomeus.test`. Nothing is bundled and nothing is ported: the app reads `/api/status`, `/api/sites`, `/api/services` and drives `start/stop/restart` as tasks, exactly like the SPA does.

```
swift test                 # NomeusCore: client, models, health, model transitions
swift run Nomeus           # menu bar icon appears; no bundle, so no notifications / launch-at-login
scripts/bundle.sh          # dist/Nomeus-<version>.zip, ad-hoc signed, with the icon
```

Requires macOS 14 and Xcode 15.3+ (or the matching command-line tools). No Apple developer account: the bundle is ad-hoc signed, so Gatekeeper blocks the first launch of a downloaded copy — `xattr -dr com.apple.quarantine Nomeus.app` (right-click → Open works on macOS 14–15; macOS 26 calls the app "damaged" and offers nothing else). Also: the notification prompt is asked once — answer it before quitting, or Settings will show "Notifications: not allowed" and the only reset is deleting every copy of the app, `killall usernoted NotificationCenter`, reinstalling. When a Developer ID exists, notarising `scripts/bundle.sh`'s output is the only change.

Redirects are not followed: if nginx 301s (http → https after `valet secure`), the app adopts the new origin, retries with the same method, and keeps it. Settings (⌘,) hold the dashboard URL (`defaults write dev.nomeus.app baseURL https://nomeus.test`), the idle poll interval and launch-at-login.
