<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

final class MySqlDriver extends AbstractDriver
{
    public function type(): string { return 'mysql'; }

    public function label(): string { return 'MySQL'; }

    /**
     * LTS lines first (9.7 is current, 8.4 previous); bare `mysql` is the Innovation line, which
     * since 26.7 is calendar-versioned and can only be upgraded to from the preceding LTS.
     */
    public function formulae(): array
    {
        return ['mysql@9.7', 'mysql@8.4', 'mysql@8.0', 'mysql'];
    }

    public function defaultPort(): int { return 3306; }

    public function binary(): string { return 'mysqld'; }

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

    /** pid + socket locks, and auto.cnf: the server UUID, which a clone must not share. */
    public function staleFiles(ServiceInstance $i): array
    {
        return [
            $i->runDir().'/mysql.pid',
            $i->runDir().'/mysql.sock',
            $i->runDir().'/mysql.sock.lock',
            $i->dataDir().'/auto.cnf',
        ];
    }

    /** brew's mysqld writes <datadir>/<hostname>.pid. */
    public function lockFilesIn(string $dataDir): array
    {
        return glob("{$dataDir}/*.pid") ?: [];
    }

    public function brewDataDir(string $prefix, string $formula): ?string
    {
        return "{$prefix}/var/mysql";
    }

    public function databaseEnvKey(): ?string { return 'DB_DATABASE'; }

    public function createDatabasePlan(ServiceInstance $i, string $binDir, string $name): ?array
    {
        $safe = str_replace('`', '', $name);

        return [
            'label' => "create database {$name}",
            'argv' => ["{$binDir}/mysql", '-h', '127.0.0.1', '-P', (string) $i->port, '-u', 'root', '-e', "CREATE DATABASE IF NOT EXISTS `{$safe}`"],
            'cwd' => null,
            'timeout' => 60,
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
