<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

final class RedisDriver extends AbstractDriver
{
    public function type(): string { return 'redis'; }

    public function label(): string { return 'Redis'; }

    public function formulae(): array { return ['redis']; }

    public function defaultPort(): int { return 6379; }

    public function initialize(ServiceInstance $i, string $binDir): array { return []; }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/redis-server",
            '--port', (string) $i->port,
            '--dir', $i->dataDir(),
            '--bind', '127.0.0.1',
            '--daemonize', 'no',
            '--save', '60', '1',
        ];
    }

    public function staleFiles(ServiceInstance $i): array
    {
        return [];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $i->port,
            'REDIS_PASSWORD' => 'null',
        ];
    }
}
