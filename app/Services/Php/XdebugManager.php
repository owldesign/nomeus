<?php

namespace App\Services\Php;

use App\Services\BrewBridge;
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
        private readonly BrewBridge $brew,
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

    public function tapIniPath(string $version): string
    {
        return $this->brew->prefix()."/etc/php/{$version}/conf.d/".self::TAP_INI;
    }

    /** Where the tap puts the extension; the tap's ini is authoritative when present. */
    public function discoverSo(string $version): ?string
    {
        foreach ([$this->tapIniPath($version), $this->tapIniPath($version).self::QUARANTINE_SUFFIX] as $ini) {
            if (is_file($ini) && preg_match('/^\s*zend_extension\s*=\s*"?([^"\s]+)"?/m', (string) file_get_contents($ini), $m) && is_file($m[1])) {
                return $m[1];
            }
        }
        $guess = $this->brew->prefix()."/opt/xdebug@{$version}/xdebug.so";

        return is_file($guess) ? $guess : null;
    }

    /** Move the tap's unconditional ini aside so ours is the only xdebug config. Idempotent. */
    public function quarantine(string $version): bool
    {
        $ini = $this->tapIniPath($version);
        if (! is_file($ini)) {
            return false;
        }
        if (! rename($ini, $ini.self::QUARANTINE_SUFFIX)) {
            throw new RuntimeException("Could not move {$ini} aside.");
        }

        return true;
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
                'tap_ini' => is_file($this->tapIniPath($version)),          // reappeared after a brew upgrade → needs quarantine
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
        $formula = $this->formula($version);
        if ($this->discoverSo($version) === null) {
            foreach (array_filter([$this->brew->trustTapPlan($formula), $this->brew->installFormulaPlan($formula)]) as $plan) {
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
        $so = $this->discoverSo($version) ?? throw new RuntimeException("Installed, but no xdebug.so found for php {$version} (looked in ".$this->tapIniPath($version).' and '.$this->brew->prefix()."/opt/xdebug@{$version}).");
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
