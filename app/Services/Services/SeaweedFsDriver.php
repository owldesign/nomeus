<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

/** S3-compatible object store; the main port is the S3 API. No auth config = any key is accepted, which is the point on loopback. */
final class SeaweedFsDriver extends AbstractDriver
{
    public function type(): string
    {
        return 'seaweedfs';
    }

    public function label(): string
    {
        return 'SeaweedFS (S3)';
    }

    public function formulae(): array
    {
        return ['seaweedfs'];
    }

    public function defaultPort(): int
    {
        return 8333;
    }

    public function binary(): string
    {
        return 'weed';
    }

    public function versionArgs(): array
    {
        return ['version'];
    }

    public function auxPorts(): array
    {
        return ['master' => 9333, 'volume' => 8080, 'filer' => 8888];
    }

    public function initialize(ServiceInstance $i, string $binDir): array
    {
        return [];
    }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/weed", 'server',
            '-dir='.$i->dataDir(),
            '-ip=127.0.0.1', '-ip.bind=127.0.0.1',
            '-master.port='.$i->options['master_port'],
            '-master.volumeSizeLimitMB=1024',
            '-volume.port='.$i->options['volume_port'],
            '-volume.max=0',
            '-filer', '-filer.port='.$i->options['filer_port'],
            '-s3', '-s3.port='.$i->port,
        ];
    }

    public function staleFiles(ServiceInstance $i): array
    {
        return [];
    }

    public function databaseEnvKey(): ?string
    {
        return 'AWS_BUCKET';
    }

    /** PUT /<bucket> on the S3 gateway; SeaweedFS answers 200 for new and existing buckets alike. */
    public function createDatabasePlan(ServiceInstance $i, string $binDir, string $name): ?array
    {
        return [
            'label' => "create bucket {$name}",
            'argv' => ['curl', '-sf', '-o', '/dev/null', '-X', 'PUT', "http://127.0.0.1:{$i->port}/{$name}"],
            'cwd' => null,
            'timeout' => 30,
            'tolerate' => '/BucketAlreadyOwnedByYou|BucketAlreadyExists/',
        ];
    }

    public function env(ServiceInstance $i): array
    {
        return [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => 'nomeus',
            'AWS_SECRET_ACCESS_KEY' => 'nomeus',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => '',
            'AWS_ENDPOINT' => "http://127.0.0.1:{$i->port}",
            'AWS_URL' => "http://127.0.0.1:{$i->port}",
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ];
    }
}
