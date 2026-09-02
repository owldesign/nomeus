<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;
use App\Support\Shell;

final class PostgresDriver extends AbstractDriver
{
    public function type(): string { return 'postgresql'; }

    public function label(): string { return 'PostgreSQL'; }

    public function formulae(): array
    {
        return ['postgresql@17', 'postgresql@16', 'postgresql@15', 'postgresql@14'];
    }

    public function defaultPort(): int { return 5432; }

    public function binary(): string { return 'postgres'; }

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

    /** postmaster.pid names the pid that owns the data dir — a copy of it points at the source's live server. */
    public function staleFiles(ServiceInstance $i): array
    {
        return [$i->dataDir().'/postmaster.pid', $i->dataDir().'/postmaster.opts'];
    }

    public function lockFilesIn(string $dataDir): array
    {
        return ["{$dataDir}/postmaster.pid"];
    }

    /** brew: <prefix>/var/postgresql@17 (versioned) or <prefix>/var/postgresql (bare formula). */
    public function brewDataDir(string $prefix, string $formula): ?string
    {
        return "{$prefix}/var/".basename($formula);
    }

    /**
     * brew's initdb made your macOS username the superuser; devkit's .env convention is `postgres`.
     * Create it (superuser, no password — trust auth on loopback) if the cluster lacks it. Both keep working.
     */
    public function postAdopt(ServiceInstance $i, string $binDir): array
    {
        $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'postgres') THEN CREATE ROLE postgres SUPERUSER LOGIN; END IF; END \$\$;";

        return [[
            'label' => 'ensure role postgres',
            'argv' => ["{$binDir}/psql", '-h', '127.0.0.1', '-p', (string) $i->port, '-U', Shell::currentUser(), '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-c', $sql],
            'cwd' => null,
            'timeout' => 30,
        ]];
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
