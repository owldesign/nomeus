<?php

use App\Support\DevkitConfig;

return [
    'version' => '0.1.0',

    // ~/.devkit/config.json — written by install/install.sh
    'config_path' => env('DEVKIT_CONFIG_PATH') ?: DevkitConfig::defaultPath(),

    // ~/.config/valet — read-only; mutations shell out to `valet`
    'valet_config_dir' => env('DEVKIT_VALET_CONFIG_DIR') ?: DevkitConfig::homeDir().'/.config/valet',

    // Explicit valet binary (tests / unusual layouts). null = <brew>/bin/valet, then composer's.
    'valet_bin' => env('DEVKIT_VALET_BIN'),

    // Site name the dashboard is linked as: http://<site>.<tld>
    'site' => env('DEVKIT_SITE', 'devkit'),
];
