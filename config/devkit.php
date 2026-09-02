<?php

use App\Support\DevkitConfig;

return [
    // Bumped per slice; `devkit status --json | grep version` tells which cut is live.
    'version' => '0.2.5',

    // ~/.devkit/config.json — written by install/install.sh
    'config_path' => env('DEVKIT_CONFIG_PATH') ?: DevkitConfig::defaultPath(),

    // ~/.config/valet — read-only; mutations shell out to `valet`
    'valet_config_dir' => env('DEVKIT_VALET_CONFIG_DIR') ?: DevkitConfig::homeDir().'/.config/valet',

    // Explicit valet binary (tests / unusual layouts). null = <brew>/bin/valet, then composer's.
    'valet_bin' => env('DEVKIT_VALET_BIN'),

    // `devkit use` refuses anything older than devkit's own requirement. Normally read from
    // vendor/composer/platform_check.php; these are the override (tests) and the fallback.
    'platform_check' => env('DEVKIT_PLATFORM_CHECK'),
    'min_php' => env('DEVKIT_MIN_PHP', '8.2'),

    // Services: launchd agents dir and domain uid (overrides for tests; null = ~/Library/LaunchAgents, your uid)
    'launch_agents_dir' => env('DEVKIT_LAUNCH_AGENTS_DIR'),
    'uid' => env('DEVKIT_UID'),

    // Site name the dashboard is linked as: http://<site>.<tld>
    'site' => env('DEVKIT_SITE', 'devkit'),
];
