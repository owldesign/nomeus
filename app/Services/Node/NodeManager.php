<?php

namespace App\Services\Node;

use App\Services\BrewBridge;
use App\Support\Shell;
use App\Support\Site;
use RuntimeException;

/**
 * Node versions through fnm (a binary, unlike nvm's shell function — callable from php-fpm and tasks).
 * Pins live where they always did: a site's .nvmrc (or .node-version); fnm honours both.
 */
final class NodeManager
{
    public function __construct(private readonly BrewBridge $brew, private readonly Shell $shell) {}

    public function fnmBin(): ?string
    {
        $bin = $this->brew->prefix().'/bin/fnm';

        return is_executable($bin) ? $bin : $this->shell->which('fnm');
    }

    public function available(): bool
    {
        return $this->fnmBin() !== null;
    }

    /** @return array{versions: list<string>, default: ?string, lts: ?string}  versions like "22.11.0" (no v), newest first; lts = the version fnm aliases as lts-* */
    public function installed(): array
    {
        $bin = $this->fnmBin();
        if ($bin === null) {
            return ['versions' => [], 'default' => null, 'lts' => null];
        }
        $out = $this->shell->run([$bin, 'ls'], timeout: 20)->output();
        $versions = [];
        $default = null;
        $lts = null;
        foreach (preg_split('/\R/', $out) as $line) {
            if (preg_match('/v(\d+\.\d+\.\d+)(.*)$/', $line, $m)) {
                $versions[] = $m[1];
                if (str_contains($m[2], 'default')) {
                    $default = $m[1];
                }
                if (str_contains($m[2], 'lts')) {
                    $lts = $lts === null || version_compare($m[1], $lts) > 0 ? $m[1] : $lts;
                }
            }
        }
        usort($versions, fn ($a, $b) => version_compare($b, $a));

        return ['versions' => array_values(array_unique($versions)), 'default' => $default, 'lts' => $lts];
    }

    /** "22" / "22.11" / "22.11.0" / "lts" — is something matching installed? Returns the exact version or null. */
    public function satisfied(string $want): ?string
    {
        $want = ltrim(strtolower($want), 'v');
        $installed = $this->installed();
        if (in_array($want, ['lts', 'lts/*'], true)) {
            return $installed['lts'];   // only what fnm itself aliases as an lts
        }
        foreach ($installed['versions'] as $v) {
            if ($want === $v || str_starts_with($v, rtrim($want, '.').'.')) {
                return $v;
            }
        }

        return null;
    }

    /** The version a site pins, from .nvmrc or .node-version (without a leading v), or null. */
    public function pinOf(string $dir): ?string
    {
        foreach (['.nvmrc', '.node-version'] as $file) {
            if (is_file("{$dir}/{$file}")) {
                $v = trim((string) file_get_contents("{$dir}/{$file}"));

                return $v === '' ? null : ltrim($v, 'v');
            }
        }

        return null;
    }

    public function pin(string $dir, string $version): void
    {
        file_put_contents("{$dir}/.nvmrc", ltrim($version, 'v')."\n");
    }

    /** What `node` resolves to in a site: its pin if satisfied, else the default. */
    public function resolve(string $dir): array
    {
        $pin = $this->pinOf($dir);
        $installed = $this->installed();

        return [
            'pin' => $pin,
            'installed' => $pin === null ? null : $this->satisfied($pin),
            'effective' => $pin !== null ? ($this->satisfied($pin) ?? null) : $installed['default'],
            'default' => $installed['default'],
        ];
    }

    /** @param  callable(string):void  $log */
    public function install(string $version, callable $log): string
    {
        $bin = $this->fnmBin() ?? throw new RuntimeException('fnm is not installed: brew install fnm (install.sh does)');
        if ($v = $this->satisfied($version)) {
            $log("node {$v} already installed");

            return $v;
        }
        $arg = in_array(strtolower($version), ['lts', 'lts/*'], true) ? '--lts' : ltrim($version, 'v');
        $log("fnm install {$arg}");
        $result = $this->shell->run([$bin, 'install', $arg], null, 600, fn ($t, $b) => $log(rtrim($b)));
        if (! $result->successful()) {
            throw new RuntimeException("fnm install {$arg} failed: ".trim($result->errorOutput() ?: $result->output()));
        }

        return $this->satisfied($version) ?? throw new RuntimeException("fnm installed something, but nothing matches {$version} in fnm ls");
    }

    public function setDefault(string $version): void
    {
        $bin = $this->fnmBin() ?? throw new RuntimeException('fnm is not installed');
        $v = $this->satisfied($version) ?? throw new RuntimeException("node {$version} is not installed: nomeus node:install {$version}");
        $result = $this->shell->run([$bin, 'default', $v], timeout: 30);
        if (! $result->successful()) {
            throw new RuntimeException('fnm default failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /** Wrap a command so it runs under a version: fnm exec --using <v> … (null = no fnm; run as is). */
    public function execArgv(?string $version, array $argv): array
    {
        $bin = $this->fnmBin();
        if ($bin === null) {
            return $argv;
        }
        if ($version === null) {
            $version = $this->installed()['default'] ?? $this->installed()['versions'][0] ?? null;   // whatever node there is
            if ($version === null) {
                return $argv;
            }
        }

        return [$bin, 'exec', '--using', ltrim($version, 'v'), '--', ...$argv];
    }

    /** @return list<array{site:string, pin:string, installed:?string}> */
    public function pins(array $sites): array
    {
        $out = [];
        foreach ($sites as $site) {
            /** @var Site $site */
            $pin = $this->pinOf($site->path);
            if ($pin !== null) {
                $out[] = ['site' => $site->name, 'pin' => $pin, 'installed' => $this->satisfied($pin)];
            }
        }

        return $out;
    }
}
