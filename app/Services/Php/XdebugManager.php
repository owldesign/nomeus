<?php

namespace App\Services\Php;

use App\Services\Dumps\PrependInstaller;
use App\Support\NomeusConfig;
use App\Support\Probe;
use App\Support\Shell;
use RuntimeException;

/**
 * Xdebug per PHP version, three modes:
 *   off     — extension not loaded (zero cost)
 *   on      — mode=debug,develop, start_with_request=yes  (every request connects: use while stepping)
 *   trigger — loaded, starts only with XDEBUG_TRIGGER / the browser helper's cookie
 *
 * Installed from shivammathur/extensions/xdebug@X.Y. That formula's own ini (20-xdebug.ini, loads
 * xdebug unconditionally) is moved aside; nomeus's 99-nomeus.ini is the only place xdebug is configured.
 */
final class XdebugManager
{
    public const TAP_INI = '20-xdebug.ini';
    public const QUARANTINE_SUFFIX = '.nomeus-off';

    public function __construct(
        private readonly NomeusConfig $config,
        private readonly PhpProvider $brew,   // BrewBridge on macOS, AptPhp on Linux
        private readonly Shell $shell,
        private readonly Probe $probe,
        private readonly XdebugState $state,
        private readonly PrependInstaller $prepend,
        private readonly XdebugWatcher $watcher,   // required on purpose: an optional nullable dependency resolves to null in the container
    ) {}

    public function port(): int
    {
        return (int) $this->config->get('xdebug.port', 9003);
    }

    public function formula(string $version): string
    {
        return "shivammathur/extensions/xdebug@{$version}";
    }

    /** macOS: the tap's 20-xdebug.ini path (kept for messages); Linux: apt's conf.d symlink. */
    public function tapIniPath(string $version): string
    {
        $dirs = $this->brew->iniDirs($version);

        return ($dirs[0] ?? "/etc/php/{$version}/conf.d").'/'.self::TAP_INI;
    }

    /** The first xdebug.so that exists among the provider's candidates. */
    public function discoverSo(string $version): ?string
    {
        foreach ($this->brew->xdebugSoCandidates($version) as $so) {
            if (is_file($so)) {
                return $so;
            }
        }

        return null;
    }

    /** Neutralise the vendor's always-on ini so ours is the only xdebug config. Idempotent. */
    public function quarantine(string $version): bool
    {
        return $this->brew->quarantineXdebug($version);
    }

    /**
     * @return array<string, array{installed:bool, so:?string, mode:string, effective:string, tap_ini:bool, ini_current:bool}>
     */
    public function status(): array
    {
        $out = [];
        $ini = $this->prepend->status();
        foreach ($this->brew->installedPhp() as $version) {
            $st = $this->state->get($version);
            $so = $st['so'] ?? $this->discoverSo($version);
            $mode = $st['mode'] ?? 'off';
            $out[$version] = [
                'installed' => $so !== null && is_file($so),
                'so' => $so,
                'mode' => $mode,
                'effective' => $mode === 'detect' ? ($st['effective'] ?? 'off') : $mode,   // what the ini says right now
                'tap_ini' => $this->brew->xdebugVendorIniPresent($version),   // reappeared after an upgrade → needs quarantine
                'ini_current' => $ini[$version]['current'] ?? false,
            ];
        }

        return $out;
    }

    /** Versions in detect mode. @return list<string> */
    public function detecting(): array
    {
        return array_keys(array_filter($this->state->all(), fn ($s) => $s['mode'] === 'detect'));
    }

    /** The watcher agent's state (installed/running/pid). */
    public function watcher(): array
    {
        return $this->watcher->status();
    }

    /**
     * Detect's heartbeat: make every detect-mode version's ini match whether the IDE listens.
     * Returns the versions whose ini changed (fpm was restarted when any did).
     *
     * @param  callable(string):void  $log
     * @return list<string>
     */
    public function applyDetect(bool $listening, callable $log): array
    {
        $changed = [];
        foreach ($this->detecting() as $version) {
            $st = $this->state->get($version);
            $want = $listening ? 'on' : 'off';
            if (($st['effective'] ?? 'off') === $want) {
                continue;
            }
            $this->state->set($version, $st['so'], 'detect', $want);
            $changed[] = $version;
        }
        if ($changed !== []) {
            $this->quarantineAll($changed);
            $this->prepend->install();
            $log('php '.implode(', ', $changed).': xdebug → '.($listening ? 'on (IDE listening)' : 'off (IDE gone)'));
            $this->prepend->restartAndWait($log);
        }

        return $changed;
    }

    private function quarantineAll(array $versions): void
    {
        foreach ($versions as $v) {
            $this->quarantine($v);
        }
    }

    public function ideListening(): bool
    {
        return $this->probe->tcp('127.0.0.1', $this->port());
    }

    /**
     * brew install (tap trusted first), then adopt: read the .so path, quarantine the tap ini,
     * write our ini with mode off. No fpm restart needed for off.
     *
     * @param  callable(string):void  $log
     */
    public function install(string $version, callable $log): array
    {
        if (! in_array($version, $this->brew->installedPhp(), true)) {
            throw new RuntimeException("php@{$version} is not installed: nomeus php:install {$version}");
        }
        if ($this->discoverSo($version) === null) {
            foreach ($this->brew->xdebugInstallPlans($version) as $plan) {
                $log($plan['label']);
                $result = $this->shell->run($plan['argv'], null, $plan['timeout'], fn ($t, $b) => $log(rtrim($b)));
                if (! $result->successful()) {
                    throw new RuntimeException("{$plan['label']} failed (exit {$result->exitCode()}).");
                }
            }
        } else {
            $log("xdebug for php {$version} already present");
        }

        return $this->adopt($version, $log);
    }

    /** Record the .so, quarantine the tap ini, write our ini (keeping the mode we had, default off). */
    public function adopt(string $version, callable $log): array
    {
        $so = $this->discoverSo($version) ?? throw new RuntimeException("Installed, but no xdebug.so found for php {$version} (looked at ".implode(', ', $this->brew->xdebugSoCandidates($version)).').');
        $mode = $this->state->get($version)['mode'] ?? 'off';
        $this->state->set($version, $so, $mode);
        if ($this->quarantine($version)) {
            $log('moved the formula\'s '.self::TAP_INI.' aside (it loads xdebug unconditionally)');
        }
        $this->prepend->install();
        $log("php {$version}: xdebug {$so} · mode {$mode}");

        return ['so' => $so, 'mode' => $mode];
    }

    /** @return bool whether php-fpm must be restarted for the change to take effect */
    public function setMode(string $version, string $mode): bool
    {
        if (! in_array($mode, XdebugState::MODES, true)) {
            throw new RuntimeException('Mode must be off, on or trigger.');
        }
        $current = $this->state->get($version);
        $so = $current['so'] ?? $this->discoverSo($version);
        if ($so === null || ! is_file($so)) {
            throw new RuntimeException("Xdebug is not installed for php {$version}: nomeus xdebug:install {$version}");
        }
        $beforeEffective = $current === null ? 'off' : ($current['mode'] === 'detect' ? ($current['effective'] ?? 'off') : $current['mode']);
        if ($mode === 'detect') {
            $effective = $this->ideListening() ? 'on' : 'off';
            $this->state->set($version, $so, 'detect', $effective);
        } else {
            $effective = $mode;
            $this->state->set($version, $so, $mode);
        }
        $this->quarantine($version);
        $this->prepend->install();

        // the watcher runs while any version is in detect mode, and only then
        $this->detecting() !== [] ? $this->watcher->enable() : $this->watcher->disable();

        return $beforeEffective !== $effective;
    }

    public function restartPlan(): array
    {
        return $this->prepend->restartPlan();
    }

    /** @param  callable(string):void  $log */
    public function restartAndWait(callable $log): void
    {
        $this->prepend->restartAndWait($log);
    }
}
