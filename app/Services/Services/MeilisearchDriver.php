<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

final class MeilisearchDriver extends AbstractDriver
{
    public function type(): string
    {
        return 'meilisearch';
    }

    public function label(): string
    {
        return 'Meilisearch';
    }

    public function formulae(): array
    {
        return ['meilisearch'];
    }

    public function defaultPort(): int
    {
        return 7700;
    }

    public function binary(): string
    {
        return 'meilisearch';
    }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [];
    }

    /** Development mode: no master key required, so Scout works with MEILISEARCH_KEY empty. */
    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/meilisearch",
            '--http-addr', "127.0.0.1:{$i->port}",
            '--db-path', $i->dataDir().'/data.ms',
            '--dump-dir', $i->dataDir().'/dumps',
            '--env', 'development',
            '--no-analytics',
        ];
    }

    public function staleFiles(ServiceInstance $i): array
    {
        return [];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'SCOUT_DRIVER' => 'meilisearch',
            'MEILISEARCH_HOST' => "http://127.0.0.1:{$i->port}",
            'MEILISEARCH_KEY' => '',
        ];
    }
}
