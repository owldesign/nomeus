<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

/** nomeus's dump server: `artisan dumps:serve` under launchd, on the port the prepend file points at. */
final class DumpsDriver extends AbstractDriver implements NomeusBound
{
    public function type(): string { return 'dumps'; }

    public function label(): string { return 'Dump server'; }

    /** Not a brew formula; the name is informational. */
    public function formulae(): array { return ['nomeus/dumps']; }

    public function defaultPort(): int { return 9912; }

    public function binary(): string { return 'php'; }

    public function workingDirectory(ServiceInstance $i): string
    {
        return (string) ($i->options['site_path'] ?? base_path());
    }

    public function initialize(ServiceInstance $i, string $binDir): array { return []; }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return ["{$binDir}/php", base_path('artisan'), 'dumps:serve', '--port='.$i->port, '--no-interaction'];
    }

    public function staleFiles(ServiceInstance $i): array { return []; }

    /** Sites need nothing in .env: the prepend file does the routing. */
    public function env(ServiceInstance $i): array { return []; }
}
