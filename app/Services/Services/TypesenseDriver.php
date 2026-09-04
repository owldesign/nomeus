<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;
use Illuminate\Support\Str;

final class TypesenseDriver extends AbstractDriver
{
    public function type(): string
    {
        return 'typesense';
    }

    public function label(): string
    {
        return 'Typesense';
    }

    /** Tapped formula, always fully qualified: under Homebrew 6 that installs with item-level trust. */
    public function formulae(): array
    {
        return ['typesense/tap/typesense-server'];
    }

    public function defaultPort(): int
    {
        return 8108;
    }

    public function binary(): string
    {
        return 'typesense-server';
    }

    public function defaultOptions(): array
    {
        return ['api_key' => Str::random(40)];
    }

    /** Raft peering listener; the default 8107 would collide between instances. */
    public function auxPorts(): array
    {
        return ['peering' => 8107];
    }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [];
    }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/typesense-server",
            '--data-dir='.$i->dataDir(),
            '--api-key='.$i->options['api_key'],
            '--api-address=127.0.0.1',
            '--api-port='.$i->port,
            '--peering-address=127.0.0.1',
            '--peering-port='.$i->options['peering_port'],
            '--log-dir='.$i->logDir(),
        ];
    }

    public function staleFiles(ServiceInstance $i): array
    {
        return [];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'SCOUT_DRIVER' => 'typesense',
            'TYPESENSE_HOST' => '127.0.0.1',
            'TYPESENSE_PORT' => (string) $i->port,
            'TYPESENSE_PROTOCOL' => 'http',
            'TYPESENSE_API_KEY' => (string) $i->options['api_key'],
        ];
    }
}
