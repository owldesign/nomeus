<?php

namespace App\Services;

use App\Services\Services\DriverRegistry;
use App\Support\Probe;
use App\Support\Shell;
use RuntimeException;

/**
 * What `brew services` is running for the user: homebrew.mxcl.<formula> agents, from launchd
 * (currently loaded) and from ~/Library/LaunchAgents (start at login). Read-only except stop().
 */
final class BrewServices
{
    public const PREFIX = 'homebrew.mxcl.';

    public function __construct(
        private readonly Shell $shell,
        private readonly BrewBridge $brew,
        private readonly DriverRegistry $drivers,
        private readonly Probe $probe,
        private readonly string $agentsDir,
    ) {}

    /**
     * @return list<array{formula:string, label:string, loaded:bool, pid:?int, plist:?string,
     *                    type:?string, data_dir:?string, has_data:bool, port:?int, answering:?bool}>
     */
    public function list(): array
    {
        $found = [];
        foreach ($this->loadedLabels() as $label => $pid) {
            $found[$label] = ['loaded' => true, 'pid' => $pid];
        }
        foreach (glob(rtrim($this->agentsDir, '/').'/'.self::PREFIX.'*.plist') ?: [] as $plist) {
            $label = basename($plist, '.plist');
            $found[$label] = ($found[$label] ?? ['loaded' => false, 'pid' => null]) + ['plist' => $plist];
        }
        ksort($found);

        $out = [];
        foreach ($found as $label => $f) {
            $formula = substr($label, strlen(self::PREFIX));
            $driver = $this->drivers->driverForFormula($formula);
            $dataDir = $driver?->brewDataDir($this->brew->prefix(), $formula);
            $port = $driver?->defaultPort();
            $out[] = [
                'formula' => $formula,
                'label' => $label,
                'loaded' => $f['loaded'],
                'pid' => $f['pid'],
                'plist' => $f['plist'] ?? null,
                'type' => $driver?->type(),
                'data_dir' => $dataDir,
                'has_data' => $dataDir !== null && is_dir($dataDir),
                'port' => $port,
                'answering' => $port !== null ? $this->probe->tcp('127.0.0.1', $port) : null,
            ];
        }

        return $out;
    }

    /** Brew services devkit knows how to take over: a driver exists and brew's data dir is present. */
    public function adoptable(): array
    {
        return array_values(array_filter($this->list(), fn ($s) => $s['type'] !== null && $s['has_data']));
    }

    public function find(string $formula): ?array
    {
        foreach ($this->list() as $s) {
            if ($s['formula'] === $formula) {
                return $s;
            }
        }

        return null;
    }

    /** `brew services stop`: unloads the agent and removes the login item. No sudo for user services. */
    public function stop(string $formula): void
    {
        $result = $this->shell->run([$this->brew->bin(), 'services', 'stop', $formula], timeout: 120);
        if (! $result->successful()) {
            throw new RuntimeException("brew services stop {$formula} failed: ".trim($result->errorOutput() ?: $result->output()));
        }
    }

    /** @return array<string, ?int> label => pid for loaded homebrew.mxcl.* agents */
    private function loadedLabels(): array
    {
        $result = $this->shell->run(['launchctl', 'list'], timeout: 15);
        $out = [];
        foreach (preg_split('/\R/', $result->output()) as $line) {
            // "PID\tStatus\tLabel"; PID is "-" when not running
            if (preg_match('/^(\S+)\s+(-?\d+)\s+('.preg_quote(self::PREFIX, '/').'\S+)$/', trim($line), $m)) {
                $out[$m[3]] = is_numeric($m[1]) ? (int) $m[1] : null;
            }
        }

        return $out;
    }
}
