<?php

namespace App\Services;

use App\Support\Site;

/**
 * Every log the dashboard may read: each site's storage/logs/*.log, plus Valet's nginx error
 * and php-fpm logs. Paths from a browser are only honoured when they resolve to one of these.
 */
final class LogSources
{
    public function __construct(private readonly ValetBridge $valet) {}

    /** @return list<array{id:string, group:string, label:string, path:string, size:int, mtime:int, kind:string}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->valet->sites() as $site) {
            foreach ($this->siteLogs($site) as $file) {
                $out[] = $this->describe($site->name, basename($file), $file, 'laravel');
            }
        }
        $logDir = $this->valet->configDir().'/Log';
        foreach (['nginx-error.log' => 'nginx', 'fpm-php.www.log' => 'php-fpm'] as $file => $kind) {
            if (is_file("{$logDir}/{$file}")) {
                $out[] = $this->describe('valet', $file, "{$logDir}/{$file}", $kind);
            }
        }

        return $out;
    }

    /** Newest first. */
    public function siteLogs(Site $site): array
    {
        if ($site->type === 'proxy' || ! is_dir("{$site->path}/storage/logs")) {
            return [];
        }
        $files = glob("{$site->path}/storage/logs/*.log") ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files;
    }

    public function latestFor(string $siteName): ?array
    {
        foreach ($this->all() as $s) {
            if ($s['group'] === $siteName) {
                return $s;
            }
        }

        return null;
    }

    /** The source record for a path, or null when it is not one of ours. */
    public function resolve(string $path): ?array
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        foreach ($this->all() as $s) {
            if (realpath($s['path']) === $real) {
                return $s;
            }
        }

        return null;
    }

    private function describe(string $group, string $label, string $path, string $kind): array
    {
        clearstatcache(true, $path);

        return [
            'id' => sha1($path),
            'group' => $group,
            'label' => $label,
            'path' => $path,
            'size' => (int) @filesize($path),
            'mtime' => (int) @filemtime($path),
            'kind' => $kind,
        ];
    }
}
