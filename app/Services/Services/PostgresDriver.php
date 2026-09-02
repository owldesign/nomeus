<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

final class PostgresDriver extends AbstractDriver
{
    public function type(): string { return 'postgresql'; }

    public function label(): string { return 'PostgreSQL'; }

    public function formulae(): array
    {
        return ['postgresql@17', 'postgresql@16', 'postgresql@15', 'postgresql@14'];
    }

    public function defaultPort(): int { return 5432; }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [[
            'label' => 'initdb',
            'argv' => ["{$binDir}/initdb", '-D', $i->dataDir(), '-U', 'postgres', '--auth=trust', '--encoding=UTF8', '--no-locale'],
            'cwd' => null,
            'timeout' => 120,
        ]];
    }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/postgres",
            '-D', $i->dataDir(),
            '-p', (string) $i->port,
            '-k', $i->runDir(),
            '-c', 'listen_addresses=127.0.0.1',
        ];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $i->port,
            'DB_USERNAME' => 'postgres',
            'DB_PASSWORD' => '',
        ];
    }
}
