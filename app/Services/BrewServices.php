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
        $mac = \App\Support\Platform::isMac();
        foreach ($this->loadedLabels() as $label => $pid) {
            $found[$label] = ['loaded' => true, 'pid' => $pid];
        }
        // the unit files brew services wrote: plists on macOS, homebrew.<formula>.service under ~/.config/systemd/user on Linux
        $pattern = $mac ? self::PREFIX.'*.plist' : 'homebrew.*.service';
        foreach (glob(rtrim($this->agentsDir, '/').'/'.$pattern) ?: [] as $unit) {
            $label = $mac ? basename($unit, '.plist') : basename($unit, '.service');
            $found[$label] = ($found[$label] ?? ['loaded' => false, 'pid' => null]) + ['plist' => $unit];
        }
        ksort($found);

        $out = [];
        foreach ($found as $label => $f) {
            $formula = $mac ? substr($label, strlen(self::PREFIX)) : substr($label, strlen('homebrew.'));
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

    /** Brew services nomeus knows how to take over: a driver exists and brew's data dir is present. */
    public function adoptable(): array
    {
        return array_values(array_filter($this->list(), fn ($s) => $s['type'] !== null && $s['has_data']));
    }

    /** brew services' unit name for a formula on this platform. */
    public static function label(string $formula): string
    {
        return \App\Support\Platform::isMac() ? self::PREFIX.$formula : "homebrew.{$formula}";
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

    /** @return array<string, ?int> label => pid for loaded brew-services units (launchd agents / systemd --user units) */
    private function loadedLabels(): array
    {
        if (! \App\Support\Platform::isMac()) {
            $result = $this->shell->run(['systemctl', '--user', 'list-units', '--type=service', '--all', '--no-legend', '--plain', 'homebrew.*'], timeout: 15);
            $out = [];
            foreach (preg_split('/\R/', $result->output()) as $line) {
                // "homebrew.postgresql@17.service loaded active running …"
                if (preg_match('/^(homebrew\.\S+)\.service\s+\S+\s+(\S+)\s+(\S+)/', trim($line), $m)) {
                    $pid = null;
                    if ($m[2] === 'active') {
                        $show = $this->shell->run(['systemctl', '--user', 'show', $m[1].'.service', '--property=MainPID'], timeout: 15)->output();
                        $pid = preg_match('/MainPID=(\d+)/', $show, $p) && (int) $p[1] > 0 ? (int) $p[1] : null;
                    }
                    $out[$m[1]] = $pid;
                }
            }

            return $out;
        }
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
