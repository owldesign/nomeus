<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

abstract class AbstractDriver implements Driver
{
    public function versionArgs(): array
    {
        return ['--version'];
    }

    public function databaseEnvKey(): ?string
    {
        return null;
    }

    public function createDatabasePlan(ServiceInstance $instance, string $binDir, string $name): ?array
    {
        return null;
    }

    public function dropDatabasePlan(ServiceInstance $instance, string $binDir, string $name): ?array
    {
        return null;
    }

    public function defaultOptions(): array
    {
        return [];
    }

    public function auxPorts(): array
    {
        return [];
    }

    public function workingDirectory(ServiceInstance $instance): string
    {
        return $instance->dir;
    }

    public function lockFilesIn(string $dataDir): array
    {
        return [];
    }

    public function brewDataDir(string $prefix, string $formula): ?string
    {
        return null;
    }

    public function postAdopt(ServiceInstance $instance, string $binDir): array
    {
        return [];
    }

    public function formulaFor(?string $version): ?string
    {
        $formulae = $this->formulae();
        if ($version === null || $version === '') {
            return $formulae[0] ?? null;
        }
        if (in_array($version, $formulae, true)) {
            return $version;
        }
        foreach ($formulae as $formula) {
            if (str_ends_with($formula, '@'.$version)) {
                return $formula;
            }
        }

        return null;
    }
}
