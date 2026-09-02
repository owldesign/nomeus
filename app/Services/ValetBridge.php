<?php

namespace App\Services;

use App\Support\Shell;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Reads Valet state from ~/.config/valet (config.json, Sites/, Certificates/, Nginx/)
 * and shells out to `valet` for every mutation. Valet's PHP classes are never loaded
 * in-process: its global helpers (resolve(), info(), warning()) collide with Laravel's.
 *
 * Slice 1a: read-only subset needed for status. 1b adds sites, links, proxies and mutations.
 */
final class ValetBridge
{
    private ?array $config = null;

    public function __construct(
        private readonly Shell $shell,
        private readonly string $configDir,
    ) {}

    public function configDir(): string
    {
        return $this->configDir;
    }

    public function isInstalled(): bool
    {
        return is_file("{$this->configDir}/config.json");
    }

    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        if (! $this->isInstalled()) {
            return $this->config = [];
        }
        $decoded = json_decode((string) file_get_contents("{$this->configDir}/config.json"), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in {$this->configDir}/config.json");
        }

        return $this->config = $decoded;
    }

    public function tld(): string
    {
        return (string) ($this->config()['tld'] ?? 'test');
    }

    public function loopback(): string
    {
        return (string) ($this->config()['loopback'] ?? '127.0.0.1');
    }

    /** @return list<string> parked directories — Valet's own Sites/ (links) dir is excluded */
    public function paths(): array
    {
        $links = rtrim($this->configDir, '/').'/Sites';

        return array_values(array_filter(
            (array) ($this->config()['paths'] ?? []),
            fn (string $p) => rtrim($p, '/') !== $links,
        ));
    }

    /** `valet --version` → "4.12.0". Cached: it boots Valet's PHP app every call. */
    public function version(): ?string
    {
        return Cache::remember('devkit.valet.version', 60, function (): ?string {
            $result = $this->shell->run(['valet', '--version'], timeout: 30);
            preg_match('/(\d+\.\d+\.\d+)/', $result->output(), $m);

            return $m[1] ?? null;
        });
    }

    public function isLinked(string $site): bool
    {
        return is_link("{$this->configDir}/Sites/{$site}");
    }
}
