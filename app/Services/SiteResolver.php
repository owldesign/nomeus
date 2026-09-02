<?php

namespace App\Services;

use App\Support\Site;

/** Which Valet site a directory belongs to — the longest site path that prefixes it. */
final class SiteResolver
{
    public function __construct(private readonly ValetBridge $valet) {}

    public function fromDirectory(string $dir): ?Site
    {
        $real = realpath($dir);
        if ($real === false) {
            return null;
        }
        $best = null;
        foreach ($this->valet->sites() as $site) {
            if ($site->type === 'proxy') {
                continue;
            }
            $root = rtrim($site->path, '/');
            if ($real === $root || str_starts_with($real, $root.'/')) {
                if ($best === null || strlen($root) > strlen($best->path)) {
                    $best = $site;
                }
            }
        }

        return $best;
    }

    /** Explicit name wins; otherwise the current directory. */
    public function resolve(?string $name, string $cwd): ?Site
    {
        return $name !== null && $name !== '' ? $this->valet->find($name) : $this->fromDirectory($cwd);
    }
}
