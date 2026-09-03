<?php

namespace App\Services;

use App\Exceptions\ValetCommandFailed;
use App\Support\Shell;
use App\Support\Site;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Reads Valet state from ~/.config/valet (config.json, Sites/, Certificates/, Nginx/)
 * and shells out to <brew>/bin/valet for every mutation. Valet's PHP classes are never
 * loaded in-process: its global helpers (resolve(), info(), warning()) collide with Laravel's.
 *
 * On-disk facts verified against laravel/valet v4.12.0, cli/Valet/Site.php:
 *   links     Sites/<name>              symlink → directory
 *   parked    <path>/<dir>              for each config paths[] entry except Sites/; links win on name clash
 *   secured   Certificates/<host>.crt
 *   isolated  Nginx/<host> first line   "# ISOLATED_PHP_VERSION=php@X.Y"
 *   proxies   Nginx/<host> not a link, containing "proxy_pass http(s)://…;"
 */
final class ValetBridge
{
    private ?array $config = null;

    public function __construct(
        private readonly Shell $shell,
        private readonly string $configDir,
    ) {}

    // ── read ──────────────────────────────────────────────────────────────────

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
        $links = $this->sitesDir();

        return array_values(array_filter(
            (array) ($this->config()['paths'] ?? []),
            fn (string $p) => rtrim($p, '/') !== $links,
        ));
    }

    public function sitesDir(): string
    {
        return rtrim($this->configDir, '/').'/Sites';
    }

    public function nginxConfPath(string $name): string
    {
        return rtrim($this->configDir, '/').'/Nginx/'.$name.'.'.$this->tld();
    }

    /**
     * Valet's version, read from cli/valet.php next to the binary. Running `valet --version`
     * on 4.12 escalates through sudo (the read-only whitelist is newer than that release), so
     * the subprocess is only a fallback. Cached briefly either way.
     */
    public function version(): ?string
    {
        return Cache::remember('nomeus.valet.version', 60, function (): ?string {
            $bin = $this->shell->valetBin();
            $real = realpath($bin);
            if ($real !== false) {
                foreach ([dirname($real).'/cli/valet.php', dirname($real).'/cli/app.php', dirname(dirname($real)).'/cli/valet.php'] as $file) {   // laravel/valet · valet-linux-plus layouts
                    if (is_file($file) && preg_match("/\\\$version\s*=\s*'([^']+)'/", (string) file_get_contents($file), $m)) {
                        return $m[1];
                    }
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
        return is_link($this->sitesDir().'/'.$site);
    }

    /** @return list<string> site names with a certificate */
    public function secured(): array
    {
        $tld = '.'.$this->tld();
        $out = [];
        foreach (glob(rtrim($this->configDir, '/').'/Certificates/*.crt') ?: [] as $crt) {
            $host = basename($crt, '.crt');
            $out[] = str_ends_with($host, $tld) ? substr($host, 0, -strlen($tld)) : $host;
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** Isolated PHP version for a site ("8.3"), or null when it uses the global version. */
    public function isolatedVersion(string $name): ?string
    {
        $conf = $this->nginxConfPath($name);
        if (! is_file($conf)) {
            return null;
        }
        $first = strtok((string) file_get_contents($conf), "\n") ?: '';
        if (preg_match('/^#\s*ISOLATED_PHP_VERSION\s*=\s*(?:php@)?(\d)\.?(\d+)/', $first, $m)) {
            return "{$m[1]}.{$m[2]}";
        }

        return null;
    }

    /** @return list<Site> links first (they shadow parked sites of the same name), then parked, then proxies */
    public function sites(): array
    {
        $tld = $this->tld();
        $secured = array_flip($this->secured());
        $seen = [];
        $sites = [];

        $make = function (string $name, string $type, string $path) use ($tld, $secured): Site {
            $conf = $this->nginxConfPath($name);

            return new Site(
                name: $name,
                type: $type,
                path: $path,
                tld: $tld,
                secured: isset($secured[$name]),
                php: $type === 'proxy' ? null : $this->isolatedVersion($name),
                nginxConf: is_file($conf) ? $conf : null,
            );
        };

        foreach ($this->scanSiteDirs($this->sitesDir()) as $name => $path) {
            $seen[$name] = true;
            $sites[] = $make($name, 'linked', $path);
        }

        foreach ($this->paths() as $parked) {
            foreach ($this->scanSiteDirs($parked) as $name => $path) {
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $sites[] = $make($name, 'parked', $path);
            }
        }

        $suffix = '.'.$tld;
        foreach (glob(rtrim($this->configDir, '/').'/Nginx/*'.$suffix) ?: [] as $conf) {
            $name = substr(basename($conf), 0, -strlen($suffix));
            if (isset($seen[$name])) {
                continue;
            }
            if (preg_match('~proxy_pass\s+(https?://[^\s;]+)\s*;~', (string) file_get_contents($conf), $m)) {
                $seen[$name] = true;
                $sites[] = $make($name, 'proxy', $m[1]);
            }
        }

        usort($sites, fn (Site $a, Site $b) => strcmp($a->name, $b->name));

        return $sites;
    }

    public function find(string $name): ?Site
    {
        $tld = '.'.$this->tld();
        if (str_ends_with($name, $tld)) {
            $name = substr($name, 0, -strlen($tld));
        }
        foreach ($this->sites() as $site) {
            if ($site->name === $name) {
                return $site;
            }
        }

        return null;
    }

    /** @return array<string, string> name → real directory, like Valet's Site::getSites() */
    private function scanSiteDirs(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $full = "$dir/$entry";
            $real = is_link($full) ? (readlink($full) ?: '') : (realpath($full) ?: '');
            if ($real !== '' && ! str_starts_with($real, '/')) {
                $real = realpath("$dir/$real") ?: $real;
            }
            if ($real !== '' && is_dir($real)) {
                $out[$entry] = $real;
            }
        }
        ksort($out);

        return $out;
    }

    // ── write: every call runs <brew>/bin/valet, which sudo's itself under the trust rule ──

    /**
     * Build a Valet command without running it — the API hands these to TaskRunner, because
     * secure/isolate restart nginx and would sever the request running them inline.
     *
     * @param  array{php?:string, path?:string}  $opts
     * @return array{label:string, argv:list<string>, cwd:?string, timeout:int}
     */
    public function command(string $action, string $name, array $opts = []): array
    {
        $site = $this->assertName($name);

        [$args, $cwd, $timeout] = match ($action) {
            'secure' => [['secure', $site], null, 180],
            'unsecure' => [['unsecure', $site], null, 180],
            'isolate' => [['isolate', 'php@'.$this->assertPhp($opts['php'] ?? ''), '--site='.$site], null, 600],
            'unisolate' => [['unisolate', '--site='.$site], null, 180],
            'link' => [['link', $site], $this->assertDir($opts['path'] ?? ''), 60],
            'unlink' => [['unlink', $site], null, 180],
            default => throw new RuntimeException("Unknown valet action [{$action}]."),
        };

        return [
            'label' => 'valet '.implode(' ', $args),
            'argv' => [$this->shell->valetBin(), ...$args],
            'cwd' => $cwd,
            'timeout' => $timeout,
        ];
    }

    public function secure(string $name): string
    {
        return $this->execute($this->command('secure', $name));
    }

    public function unsecure(string $name): string
    {
        return $this->execute($this->command('unsecure', $name));
    }

    public function isolate(string $name, string $php): string
    {
        return $this->execute($this->command('isolate', $name, ['php' => $php]));
    }

    public function unisolate(string $name): string
    {
        return $this->execute($this->command('unisolate', $name));
    }

    /** `valet link` reads the cwd, so the command runs inside the target directory. */
    public function link(string $name, string $path): string
    {
        return $this->execute($this->command('link', $name, ['path' => $path]));
    }

    public function unlink(string $name): string
    {
        return $this->execute($this->command('unlink', $name));
    }

    /** Synchronous execution — CLI and tests. The dashboard goes through TaskRunner instead. */
    private function execute(array $plan): string
    {
        $result = $this->shell->run($plan['argv'], $plan['cwd'], $plan['timeout']);
        $this->config = null; // Valet may have rewritten config.json

        if (! $result->successful()) {
            throw ValetCommandFailed::from(array_slice($plan['argv'], 1), $result);
        }

        return trim($result->output());
    }

    private function assertPhp(string $php): string
    {
        if (! preg_match('/^(?:php@)?(\d+\.\d+)$/', $php, $m)) {
            throw new RuntimeException("PHP version must look like 8.3 or php@8.3, got [{$php}].");
        }

        return $m[1];
    }

    private function assertDir(string $path): string
    {
        $real = $path !== '' ? realpath($path) : false;
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Directory not found: {$path}");
        }

        return $real;
    }

    private function assertName(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
            throw new RuntimeException("Invalid site name [{$name}].");
        }

        return $name;
    }
}
