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

    /**
     * Valet's version, read from cli/valet.php next to the binary. Running `valet --version`
     * on 4.12 escalates through sudo (the read-only whitelist is newer than that release), so
     * the subprocess is only a fallback. Cached briefly either way.
     */
    public function version(): ?string
    {
        return Cache::remember('devkit.valet.version', 60, function (): ?string {
            $bin = $this->shell->valetBin();
            $real = realpath($bin);
            if ($real !== false) {
                $file = dirname($real).'/cli/valet.php';
                if (is_file($file) && preg_match("/\\\$version\s*=\s*'([^']+)'/", (string) file_get_contents($file), $m)) {
                    return $m[1];
                }
            }

            $result = $this->shell->run([$bin, '--version'], timeout: 30);
            preg_match('/(\d+\.\d+\.\d+)/', $result->output(), $m);

            return $m[1] ?? null;
        });
    }

    /** True when `valet trust` has installed its NOPASSWD sudoers rule — required for dashboard actions. */
    public function isTrusted(): bool
    {
        return is_file('/etc/sudoers.d/valet');
    }

    public function isLinked(string $site): bool
    {
        return is_link("{$this->configDir}/Sites/{$site}");
    }
}
