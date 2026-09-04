<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;
use Illuminate\Support\Str;

/** Laravel Reverb for one site: `php artisan reverb:start` under launchd, run with the site's PHP from the site's directory. */
final class ReverbDriver extends AbstractDriver implements SiteBound
{
    public function type(): string
    {
        return 'reverb';
    }

    public function label(): string
    {
        return 'Laravel Reverb';
    }

    /** Not a brew formula — the composer package the site must have. */
    public function formulae(): array
    {
        return ['laravel/reverb'];
    }

    public function siteRequirement(): string
    {
        return 'vendor/laravel/reverb';
    }

    public function sitePackage(): string
    {
        return 'laravel/reverb';
    }

    public function defaultPort(): int
    {
        return 8080;
    }

    public function binary(): string
    {
        return 'php';
    }

    public function defaultOptions(): array
    {
        return [
            'app_id' => (string) random_int(100000, 999999),
            'app_key' => Str::lower(Str::random(20)),
            'app_secret' => Str::lower(Str::random(20)),
        ];
    }

    public function workingDirectory(ServiceInstance $i): string
    {
        return (string) ($i->options['site_path'] ?? $i->dir);
    }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [];
    }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return ["{$binDir}/php", 'artisan', 'reverb:start', '--host=127.0.0.1', '--port='.$i->port, '--no-interaction'];
    }

    public function staleFiles(ServiceInstance $i): array
    {
        return [];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => (string) $i->options['app_id'],
            'REVERB_APP_KEY' => (string) $i->options['app_key'],
            'REVERB_APP_SECRET' => (string) $i->options['app_secret'],
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => (string) $i->port,
            'REVERB_SCHEME' => 'http',
            'VITE_REVERB_APP_KEY' => '"${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST' => '"${REVERB_HOST}"',
            'VITE_REVERB_PORT' => '"${REVERB_PORT}"',
            'VITE_REVERB_SCHEME' => '"${REVERB_SCHEME}"',
        ];
    }
}
