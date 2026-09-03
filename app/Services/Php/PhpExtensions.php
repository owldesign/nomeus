<?php

namespace App\Services\Php;

use App\Services\BrewBridge;
use App\Services\Dumps\PrependInstaller;
use App\Support\Shell;
use RuntimeException;

/**
 * PHP extensions per version, from the shivammathur/extensions tap (redis, imagick, swoole, …).
 * Unlike xdebug, these should load unconditionally, so the tap's ini stays where it is.
 */
final class PhpExtensions
{
    public function __construct(
        private readonly BrewBridge $brew,
        private readonly Shell $shell,
        private readonly PrependInstaller $prepend,
    ) {}

    public function formula(string $version, string $ext): string
    {
        return "shivammathur/extensions/{$ext}@{$version}";
    }

    /** Lowercased `php -m` of that version's binary. @return list<string> */
    public function loaded(string $version): array
    {
        $php = $this->brew->prefix()."/opt/php@{$version}/bin/php";
        if (! is_executable($php)) {
            return [];
        }
        $out = $this->shell->run([$php, '-m'], timeout: 20)->output();
        $mods = [];
        foreach (preg_split('/\R/', $out) as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '[') {
                $mods[] = $line;
            }
        }
        sort($mods);

        return array_values(array_unique($mods));
    }

    public function has(string $version, string $ext): bool
    {
        return in_array(strtolower($ext), $this->loaded($version), true);
    }

    /**
     * Trust the tap, install, restart fpm. Idempotent: already loaded → only a log line.
     *
     * @param  callable(string):void  $log
     */
    public function install(string $version, string $ext, callable $log, bool $restart = true): void
    {
        $ext = strtolower($ext);
        if (! preg_match('/^[a-z0-9_]+$/', $ext)) {
            throw new RuntimeException("Extension name [{$ext}] is not valid.");
        }
        if (! in_array($version, $this->brew->installedPhp(), true)) {
            throw new RuntimeException("php@{$version} is not installed: nomeus php:install {$version}");
        }
        if ($this->has($version, $ext)) {
            $log("php {$version}: {$ext} already loaded");

            return;
        }
        $formula = $this->formula($version, $ext);
        foreach (array_filter([$this->brew->trustTapPlan($formula), $this->brew->installFormulaPlan($formula)]) as $plan) {
            $log($plan['label']);
            $result = $this->shell->run($plan['argv'], null, $plan['timeout'], fn ($t, $b) => $log(rtrim($b)));
            if (! $result->successful()) {
                throw new RuntimeException("{$plan['label']} failed (exit {$result->exitCode()}) — brew search shivammathur/extensions/{$ext} lists what exists for {$version}");
            }
        }
        if ($restart) {
            $this->prepend->restartAndWait($log);
        }
        if (! $this->has($version, $ext)) {
            throw new RuntimeException("{$ext} installed but php {$version} does not list it — {$this->brew->prefix()}/opt/php@{$version}/bin/php --ini");
        }
        $log("php {$version}: {$ext} loaded");
    }
}
