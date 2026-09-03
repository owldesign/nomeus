<?php

use App\Support\NomeusConfig;

return [
    // Bumped per slice; `nomeus status --json | grep version` tells which cut is live.
    'version' => '1.9.0',

    // ~/.nomeus/config.json — written by install/install.sh
    'config_path' => env('NOMEUS_CONFIG_PATH') ?: NomeusConfig::defaultPath(),

    // ~/.config/valet — read-only; mutations shell out to `valet`
    'valet_config_dir' => env('NOMEUS_VALET_CONFIG_DIR') ?: NomeusConfig::homeDir().'/.config/valet',

    // Explicit valet binary (tests / unusual layouts). null = <brew>/bin/valet, then composer's.
    'valet_bin' => env('NOMEUS_VALET_BIN'),

    // `nomeus use` refuses anything older than nomeus's own requirement. Normally read from
    // vendor/composer/platform_check.php; these are the override (tests) and the fallback.
    'platform_check' => env('NOMEUS_PLATFORM_CHECK'),
    'min_php' => env('NOMEUS_MIN_PHP', '8.2'),

    // Services: launchd agents dir and domain uid (overrides for tests; null = ~/Library/LaunchAgents, your uid)
    'launch_agents_dir' => env('NOMEUS_LAUNCH_AGENTS_DIR'),
    'uid' => env('NOMEUS_UID'),

    // Site name the dashboard is linked as: http://<site>.<tld>
    'site' => env('NOMEUS_SITE', 'nomeus'),
];
