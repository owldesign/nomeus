<?php

namespace App\Services\Php;

use App\Services\LaunchdManager;
use App\Support\NomeusConfig;
use App\Support\Shell;

/**
 * The agent behind detect mode: `nomeus xdebug:watch` kept alive by launchd, polling the IDE port
 * and flipping the ini between on and off. Registered like a service instance (same plist machinery),
 * named xdebug-detect.
 */
class XdebugWatcher
{
    public const NAME = 'xdebug-detect';

    public function __construct(
        private readonly LaunchdManager $launchd,
        private readonly NomeusConfig $config,
        private readonly Shell $shell,
    ) {}

    public function logFile(): string
    {
        return $this->config->dir().'/php/xdebug-detect.log';
    }

    public function plistPath(): string
    {
        return $this->launchd->plistPath(self::NAME);
    }

    public function enable(): void
    {
        $dir = dirname($this->logFile());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->launchd->writePlist(self::NAME, [$this->shell->phpBin(), base_path('artisan'), 'xdebug:watch'], base_path(), $this->logFile(), $this->shell->env());
        $this->launchd->enable(self::NAME);
        $this->launchd->bootstrap(self::NAME);
    }

    public function disable(): void
    {
        if (is_file($this->plistPath())) {
            $this->launchd->bootout(self::NAME);
            $this->launchd->removePlist(self::NAME);
        }
    }

    /** @return array{installed:bool, running:bool, pid:?int} */
    public function status(): array
    {
        if (! is_file($this->plistPath())) {
            return ['installed' => false, 'running' => false, 'pid' => null];
        }
        $st = $this->launchd->state(self::NAME);

        return ['installed' => true, 'running' => $st['loaded'] && $st['pid'] !== null, 'pid' => $st['pid']];
    }
}
