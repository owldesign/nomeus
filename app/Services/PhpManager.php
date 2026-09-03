<?php

namespace App\Services;

use App\Support\PhpVersion;
use App\Support\Probe;
use App\Support\Shell;
use RuntimeException;

/** Installed PHP versions as one merged view: brew kegs × Valet sockets × sites, plus the plans that change them. */
final class PhpManager
{
    public function __construct(
        private readonly BrewBridge $brew,
        private readonly ValetBridge $valet,
        private readonly Shell $shell,
        private readonly Probe $probe,
    ) {}

    /** @return list<PhpVersion> */
    public function versions(): array
    {
        $linked = $this->brew->linkedPhp();
        $outdated = $this->brew->outdatedPhp();
        $fpm = $this->runningFpmVersions();
        $prefix = $this->brew->prefix();

        $usedBy = [];
        if ($this->valet->isInstalled()) {
            foreach ($this->valet->sites() as $site) {
                if ($site->type === 'proxy') {
                    continue;
                }
                $v = $site->php ?? $linked;
                if ($v !== null) {
                    $usedBy[$v][] = $site->name;
                }
            }
        }

        $out = [];
        foreach ($this->brew->installedPhp() as $v) {
            $out[] = new PhpVersion(
                version: $v,
                patch: $this->brew->phpPatch($v),
                linked: $v === $linked,
                fpm: in_array($v, $fpm, true),
                sites: $usedBy[$v] ?? [],
                ini: "{$prefix}/etc/php/{$v}/php.ini",
                confd: "{$prefix}/etc/php/{$v}/conf.d",
                outdated: $outdated[$v] ?? null,
            );
        }

        return $out;
    }

    public function find(string $version): ?PhpVersion
    {
        $version = $this->brew->assertVersion($version);
        foreach ($this->versions() as $v) {
            if ($v->version === $version) {
                return $v;
            }
        }

        return null;
    }

    /** @return list<string> versions the tap offers that aren't installed */
    public function installable(): array
    {
        return array_values(array_diff($this->brew->availablePhp(), $this->brew->installedPhp()));
    }

    /** @return list<string> */
    public function valetSockets(): array
    {
        if (! $this->valet->isInstalled()) {
            return [];
        }
        $found = glob($this->valet->configDir().'/*.sock') ?: [];
        sort($found);

        return $found;
    }

    /**
     * Versions with a live php-fpm. Primary: Valet's sockets — valet.sock is the global version,
     * valetXY.sock an isolated one. Fallback: the fpm master's argv, accepting the launch path
     * (opt/php@X.Y) and macOS's rewritten title (…/etc/php/X.Y/php-fpm.conf).
     *
     * @return list<string>
     */
    public function runningFpmVersions(): array
    {
        $versions = [];
        foreach ($this->valetSockets() as $path) {
            if (! $this->probe->unix($path)) {
                continue;
            }
            $name = basename($path, '.sock');
            if ($name === 'valet') {
                $global = $this->brew->linkedPhp();
                if ($global !== null) {
                    $versions[] = $global;
                }
            } elseif (preg_match('/^valet(\d)(\d+)$/', $name, $m)) {
                $versions[] = "{$m[1]}.{$m[2]}";
            }
        }

        if ($versions === []) {
            $result = $this->shell->run(['pgrep', '-fl', 'php-fpm'], timeout: 10);
            if (! $result->successful() || trim($result->output()) === '') {
                return [];
            }
            preg_match_all('#(?:php@|/etc/php/)(\d+\.\d+)#', $result->output(), $m);
            $versions = $m[1] ?: ['unknown'];
        }

        $versions = array_values(array_unique($versions));
        sort($versions, SORT_NATURAL);

        return $versions;
    }

    /**
     * The oldest PHP nomeus itself can run on. The dashboard is served by the global fpm, so
     * `use` below this takes nomeus down with it (Composer's platform check answers 500).
     * Read from vendor/composer/platform_check.php, which Composer writes from the lock file.
     */
    public function minPhp(): string
    {
        // `?:` not a config() default: the key exists (as null) in config/nomeus.php, and config()
        // only falls back for keys that are absent — a null value is returned as null.
        $file = (string) (config('nomeus.platform_check') ?: base_path('vendor/composer/platform_check.php'));
        if (is_file($file) && preg_match('/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', (string) file_get_contents($file), $m)) {
            $id = (int) $m[1];

            return intdiv($id, 10000).'.'.intdiv($id % 10000, 100);
        }

        return (string) config('nomeus.min_php', '8.2');
    }

    // ── plans (executed by TaskRunner from the API, inline from the CLI) ──────────

    /** @return array{label:string, argv:list<string>, cwd:null, timeout:int} */
    public function usePlan(string $version): array
    {
        $version = $this->brew->assertVersion($version);
        if (! in_array($version, $this->brew->installedPhp(), true)) {
            throw new RuntimeException("php@{$version} is not installed. Install it first: nomeus php:install {$version}");
        }
        $min = $this->minPhp();
        if (version_compare($version, $min, '<')) {
            throw new RuntimeException("nomeus's dashboard runs on the global PHP and its dependencies need {$min}+; isolate sites that need php@{$version} instead: nomeus isolate php@{$version} --site=<name>");
        }

        return [
            'label' => "valet use php@{$version}",
            'argv' => [$this->shell->valetBin(), 'use', "php@{$version}"],
            'cwd' => null,
            'timeout' => 600,
        ];
    }

    public function installPlan(string $version): array
    {
        $version = $this->brew->assertVersion($version);
        if (in_array($version, $this->brew->installedPhp(), true)) {
            throw new RuntimeException("php@{$version} is already installed.");
        }
        if (! in_array($version, $this->brew->availablePhp(), true)) {
            throw new RuntimeException("php@{$version} is not offered by the ".BrewBridge::TAP.' tap. Available: '.implode(', ', $this->installable()));
        }

        return $this->brew->installPlan($version);
    }

    public function updatePlan(string $version): array
    {
        $version = $this->brew->assertVersion($version);
        if (! in_array($version, $this->brew->installedPhp(), true)) {
            throw new RuntimeException("php@{$version} is not installed.");
        }

        return $this->brew->upgradePlan($version);
    }
}
