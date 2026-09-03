<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

final class MariaDbDriver extends AbstractDriver
{
    public function type(): string { return 'mariadb'; }

    public function label(): string { return 'MariaDB'; }

    public function formulae(): array
    {
        return ['mariadb', 'mariadb@11.4', 'mariadb@10.11'];
    }

    public function defaultPort(): int { return 3306; }

    public function binary(): string { return 'mariadbd'; }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [[
            'label' => 'mariadb-install-db',
            'argv' => ["{$binDir}/mariadb-install-db", '--datadir='.$i->dataDir(), '--auth-root-authentication-method=normal', '--skip-test-db'],
            'cwd' => null,
            'timeout' => 180,
        ]];
    }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/mariadbd",
            '--datadir='.$i->dataDir(),
            '--port='.$i->port,
            '--socket='.$i->runDir().'/mysql.sock',
            '--pid-file='.$i->runDir().'/mysql.pid',
            '--bind-address=127.0.0.1',
            '--log-error='.$i->logDir().'/mariadb-error.log',
        ];
    }

    public function staleFiles(ServiceInstance $i): array
    {
        return [$i->runDir().'/mysql.pid', $i->runDir().'/mysql.sock'];
    }

    public function lockFilesIn(string $dataDir): array
    {
        return glob("{$dataDir}/*.pid") ?: [];
    }

    /** brew's mariadb and mysql share <prefix>/var/mysql — one of them at a time. */
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
            'argv' => ["{$binDir}/mariadb", '-h', '127.0.0.1', '-P', (string) $i->port, '-u', 'root', '-e', "CREATE DATABASE IF NOT EXISTS `{$safe}`"],
            'cwd' => null,
            'timeout' => 60,
        ];
    }

    public function dropDatabasePlan(ServiceInstance $i, string $binDir, string $name): ?array
    {
        $safe = str_replace('`', '', $name);

        return ['label' => "drop database {$name}", 'argv' => ["{$binDir}/mariadb", '-h', '127.0.0.1', '-P', (string) $i->port, '-u', 'root', '-e', "DROP DATABASE IF EXISTS `{$safe}`"], 'cwd' => null, 'timeout' => 60];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $i->port,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ];
    }
}
