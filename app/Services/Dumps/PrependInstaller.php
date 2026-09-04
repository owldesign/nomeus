<?php

namespace App\Services\Dumps;

use App\Services\Php\IniManager;
use App\Services\Php\PhpProvider;
use App\Services\Php\XdebugState;
use App\Services\PhpManager;
use App\Support\NomeusConfig;
use App\Support\Shell;
use RuntimeException;

/**
 * The generated prepend file and the 99-nomeus.ini that loads it in every installed PHP version.
 * php-fpm reads ini files at start, so a new ini needs one `valet restart php`; the CLI sees it at once.
 */
final class PrependInstaller
{
    public const INI = IniManager::FILE;

    public function __construct(
        private readonly NomeusConfig $config,
        private readonly PhpProvider $brew,   // "brew" by history: the macOS provider is BrewBridge; on Linux it is AptPhp
        private readonly CaptureFlag $flag,
        private readonly Shell $shell,
        private readonly XdebugState $xdebug,
    ) {}

    private function ini(): IniManager
    {
        return new IniManager((int) $this->config->get('xdebug.port', 9003));
    }

    public function prependPath(): string
    {
        return $this->config->dir().'/php/prepend.php';
    }

    /** The first ini location (macOS has one; Linux has cli and fpm — see iniPaths). */
    public function iniPath(string $version): string
    {
        return ($this->brew->iniDirs($version)[0] ?? "/etc/php/{$version}/conf.d").'/'.self::INI;
    }

    /** @return list<string> every ini location for the version */
    public function iniPaths(string $version): array
    {
        return array_map(fn ($d) => "{$d}/".self::INI, $this->brew->iniDirs($version));
    }

    public function host(): string
    {
        return '127.0.0.1:'.(int) $this->config->get('dumps.port', 9912);
    }

    /** What the prepend file must contain right now. */
    public function prependSource(): string
    {
        $stub = (string) file_get_contents(base_path('resources/php/prepend.stub.php'));

        return str_replace(['{{FLAG}}', '{{HOST}}'], [addslashes($this->flag->path()), $this->host()], $stub);
    }

    /** The whole 99-nomeus.ini for a version: prepend section + whatever xdebug state that version has. */
    public function iniSource(string $version = ''): string
    {
        return $this->ini()->render($this->prependPath(), $version !== '' ? $this->xdebug->get($version) : null);
    }

    /**
     * @return array{prepend:string, written:list<string>, unchanged:list<string>, versions:list<string>}
     */
    public function install(): array
    {
        $prepend = $this->prependPath();
        if (! is_dir(dirname($prepend))) {
            mkdir(dirname($prepend), 0755, true);
        }
        $this->write($prepend, $this->prependSource());

        $written = [];
        $unchanged = [];
        foreach ($this->brew->installedPhp() as $version) {
            $paths = $this->iniPaths($version);
            if ($paths === []) {
                throw new RuntimeException("php@{$version} has no conf.d directory");
            }
            $current = true;
            foreach ($paths as $ini) {
                if (! is_file($ini) || file_get_contents($ini) !== $this->iniSource($version)) {
                    $current = false;
                }
            }
            if ($current) {
                $unchanged[] = $version;

                continue;
            }
            $this->brew->writeIni($version, self::INI, $this->iniSource($version));   // direct on macOS, root helper on Linux
            $written[] = $version;
        }

        return ['prepend' => $prepend, 'written' => $written, 'unchanged' => $unchanged, 'versions' => $this->brew->installedPhp()];
    }

    /** @return array<string, array{ini:bool, current:bool}> per installed version */
    public function status(): array
    {
        $out = [];
        foreach ($this->brew->installedPhp() as $version) {
            $paths = $this->iniPaths($version);
            $exists = $paths !== [] && array_reduce($paths, fn ($c, $p) => $c && is_file($p), true);
            $current = $exists && array_reduce($paths, fn ($c, $p) => $c && file_get_contents($p) === $this->iniSource($version), true);
            $out[$version] = ['ini' => $exists, 'current' => $current];
        }

        return $out;
    }

    public function prependCurrent(): bool
    {
        return is_file($this->prependPath()) && file_get_contents($this->prependPath()) === $this->prependSource();
    }

    /** The first restart plan (macOS: `valet restart php`, all versions at once); Linux has one per version — see restartPlans(). */
    public function restartPlan(): array
    {
        return $this->brew->restartFpmPlans()[0] ?? ['label' => 'valet restart php', 'argv' => [$this->shell->valetBin(), 'restart', 'php'], 'cwd' => null, 'timeout' => 120];
    }

    /** @return list<array> */
    public function restartPlans(): array
    {
        return $this->brew->restartFpmPlans();
    }

    /**
     * Restart fpm and wait until it answers again. `valet restart php` returns as soon as brew has
     * asked the daemons to restart; the sockets come back a moment later, and a request in that gap
     * is a 502. Throws when the restart fails or nothing answers within $seconds.
     *
     * @param  callable(string):void  $log
     */
    public function restartAndWait(callable $log, int $seconds = 15): void
    {
        foreach ($this->restartPlans() as $plan) {
            $log($plan['label']);
            $result = $this->shell->run($plan['argv'], null, $plan['timeout'], fn ($t, $b) => $log(rtrim($b)));
            if (! $result->successful()) {
                throw new RuntimeException("{$plan['label']} failed: ".trim($result->errorOutput() ?: $result->output()));
            }
        }
        $php = app(PhpManager::class);
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $up = $php->runningFpmVersions();
            if ($up !== [] && $up !== ['unknown']) {
                $log('php-fpm back: '.implode(', ', $up));

                return;
            }
            usleep(250_000);
        }
        throw new RuntimeException("php-fpm did not come back within {$seconds}s — valet restart php, then nomeus status");
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }
}
