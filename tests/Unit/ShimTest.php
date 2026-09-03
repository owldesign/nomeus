<?php

/**
 * bin/nomeus must hand every Valet command straight to valet. `use` was missing once and
 * `nomeus use php@8.4` booted artisan instead — on a PHP artisan couldn't run on.
 */
function shimPassthrough(): array
{
    $shim = file_get_contents(base_path('bin/nomeus'));
    preg_match('/VALET_PASSTHROUGH=\((.*?)\)/s', $shim, $m);

    return preg_split('/\s+/', trim($m[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
}

it('passes every valet command through the shim', function () {
    // Valet 4.12 command names (cli/app.php) that must never reach artisan.
    $valet = [
        'park', 'parked', 'forget', 'paths', 'link', 'links', 'unlink', 'open',
        'isolate', 'unisolate', 'isolated', 'php', 'composer', 'which-php', 'which',
        'secure', 'unsecure', 'secured', 'proxy', 'unproxy', 'proxies',
        'share', 'fetch-share-url', 'share-tool', 'set-ngrok-token',
        'loopback', 'directory-listing', 'tld', 'start', 'stop', 'restart', 'log', 'use',
        'trust', 'install', 'uninstall', 'on-latest-version', 'diagnose',
    ];

    expect(array_values(array_diff($valet, shimPassthrough())))->toBe([]);
});

it('never lists a nomeus command as valet passthrough', function () {
    $nomeus = ['status', 'sites', 'site-information', 'tasks', 'php:list', 'php:install', 'php:update', 'ini', 'db', 'edit', 'config:get', 'config:set', 'services:create', 'services:list', 'services:upgrade', 'services:adopt', 'mail', 'init', 'logs', 'dumps', 'dumps:install', 'xdebug', 'xdebug:mode', 'doctor', 'self-update', 'migrate:devkit', 'new', 'rm', 'php:ext', 'mcp', 'node', 'node:install'];

    expect(array_values(array_intersect($nomeus, shimPassthrough())))->toBe([]);
});
