<?php

namespace App\Services\Services;

abstract class AbstractDriver implements Driver
{
    public function lockFilesIn(string $dataDir): array
    {
        return [];
    }

    public function brewDataDir(string $prefix, string $formula): ?string
    {
        return null;
    }

    public function postAdopt(\App\Support\ServiceInstance $instance, string $binDir): array
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
