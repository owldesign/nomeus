<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

final class MySqlDriver extends AbstractDriver
{
    public function type(): string { return 'mysql'; }

    public function label(): string { return 'MySQL'; }

    /** mysql@8.4 is the LTS; bare `mysql` is the current innovation release. */
    public function formulae(): array
    {
        return ['mysql@8.4', 'mysql@8.0', 'mysql'];
    }

    public function defaultPort(): int { return 3306; }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [[
            'label' => 'mysqld --initialize-insecure',
            'argv' => ["{$binDir}/mysqld", '--initialize-insecure', '--datadir='.$i->dataDir(), '--log-error='.$i->logDir().'/mysql-error.log'],
            'cwd' => null,
            'timeout' => 180,
        ]];
    }

    /** mysqlx off: every instance would otherwise also claim 33060. */
    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/mysqld",
            '--datadir='.$i->dataDir(),
            '--port='.$i->port,
            '--socket='.$i->runDir().'/mysql.sock',
            '--pid-file='.$i->runDir().'/mysql.pid',
            '--bind-address=127.0.0.1',
            '--mysqlx=OFF',
            '--log-error='.$i->logDir().'/mysql-error.log',
        ];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $i->port,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ];
    }
}
