<?php

namespace App\Services\Dumps;

use App\Services\BrewBridge;
use App\Services\Php\IniManager;
use App\Services\Php\XdebugState;
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
        private readonly BrewBridge $brew,
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

    public function iniPath(string $version): string
    {
        return $this->brew->prefix()."/etc/php/{$version}/conf.d/".self::INI;
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
            $ini = $this->iniPath($version);
            if (! is_dir(dirname($ini))) {
                throw new RuntimeException("php@{$version} has no conf.d at ".dirname($ini));
            }
            if (is_file($ini) && file_get_contents($ini) === $this->iniSource($version)) {
                $unchanged[] = $version;
                continue;
            }
            $this->write($ini, $this->iniSource($version));
            $written[] = $version;
        }

        return ['prepend' => $prepend, 'written' => $written, 'unchanged' => $unchanged, 'versions' => $this->brew->installedPhp()];
    }

    /** @return array<string, array{ini:bool, current:bool}> per installed version */
    public function status(): array
    {
        $out = [];
        foreach ($this->brew->installedPhp() as $version) {
            $ini = $this->iniPath($version);
            $out[$version] = ['ini' => is_file($ini), 'current' => is_file($ini) && file_get_contents($ini) === $this->iniSource($version)];
        }

        return $out;
    }

    public function prependCurrent(): bool
    {
        return is_file($this->prependPath()) && file_get_contents($this->prependPath()) === $this->prependSource();
    }

    /** `valet restart php` — all versions' fpm daemons, through valet's sudoers. */
    public function restartPlan(): array
    {
        return ['label' => 'valet restart php', 'argv' => [$this->shell->valetBin(), 'restart', 'php'], 'cwd' => null, 'timeout' => 120];
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
        $plan = $this->restartPlan();
        $log($plan['label']);
        $result = $this->shell->run($plan['argv'], null, $plan['timeout'], fn ($t, $b) => $log(rtrim($b)));
        if (! $result->successful()) {
            throw new RuntimeException('valet restart php failed: '.trim($result->errorOutput() ?: $result->output()));
        }
        $php = app(\App\Services\PhpManager::class);
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
