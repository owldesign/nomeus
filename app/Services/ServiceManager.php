<?php

namespace App\Services;

use App\Services\Services\Driver;
use App\Services\Services\DriverRegistry;
use App\Support\DevkitConfig;
use App\Support\Probe;
use App\Support\ServiceInstance;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Service instances: create / start / stop / restart / clone / delete, each a brew binary
 * running under its own launchd agent with its own data dir and port. Runs inline; the API
 * (2b) invokes these through the CLI as tasks.
 */
final class ServiceManager
{
    public function __construct(
        private readonly DevkitConfig $config,
        private readonly BrewBridge $brew,
        private readonly DriverRegistry $drivers,
        private readonly LaunchdManager $launchd,
        private readonly Shell $shell,
        private readonly Probe $probe,
    ) {}

    public function dir(): string
    {
        return $this->config->dir().'/services';
    }

    /** @return list<ServiceInstance> */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir().'/*/service.json') ?: [] as $file) {
            $i = ServiceInstance::load(dirname($file));
            if ($i !== null) {
                $out[] = $i;
            }
        }
        usort($out, fn ($a, $b) => strcmp($a->name, $b->name));

        return $out;
    }

    public function find(string $name): ?ServiceInstance
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) ? ServiceInstance::load($this->dir().'/'.$name) : null;
    }

    public function driver(ServiceInstance $i): Driver
    {
        return $this->drivers->get($i->type);
    }

    /** @return array{running:bool, loaded:bool, pid:?int, disabled:bool, installed:bool} */
    public function status(ServiceInstance $i): array
    {
        $launchd = $this->launchd->state($i->name);

        return [
            'running' => $this->probe->tcp('127.0.0.1', $i->port),
            'loaded' => $launchd['loaded'],
            'pid' => $launchd['pid'],
            'disabled' => $launchd['disabled'],
            'installed' => $this->brew->isFormulaInstalled($i->formula),
        ];
    }

    /** @return array<string,string> */
    public function env(ServiceInstance $i): array
    {
        return $this->driver($i)->env($i);
    }

    // ── create ────────────────────────────────────────────────────────────────

    /**
     * @param  callable(string):void|null  $log  progress lines (CLI prints them; tasks capture them)
     */
    public function create(string $type, ?string $version = null, ?string $name = null, ?int $port = null, bool $start = true, ?callable $log = null): ServiceInstance
    {
        $log ??= fn () => null;
        $driver = $this->drivers->get($type);
        $formula = $driver->formulaFor($version) ?? throw new RuntimeException("No {$driver->label()} formula for version [{$version}]. Known: ".implode(', ', $driver->formulae()));

        $name = $name ?? $this->defaultName($type);
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            throw new RuntimeException("Instance name must be lowercase letters, digits and dashes, got [{$name}].");
        }
        if ($this->find($name) !== null) {
            throw new RuntimeException("Service [{$name}] already exists.");
        }

        $port = $this->allocatePort($port ?? $driver->defaultPort(), explicit: $port !== null);

        if (! $this->brew->isFormulaInstalled($formula)) {
            $plan = $this->brew->installFormulaPlan($formula);
            $log("{$plan['label']} (not installed yet)");
            $result = $this->shell->run($plan['argv'], null, $plan['timeout'], fn ($type, $buf) => $log(rtrim($buf)));
            if (! $result->successful()) {
                throw new RuntimeException("brew install {$formula} failed (exit {$result->exitCode()}).");
            }
        }
        $binDir = $this->brew->formulaBinDir($formula) ?? throw new RuntimeException("Formula {$formula} has no bin dir under ".$this->brew->prefix().'/opt');

        $instance = new ServiceInstance(
            name: $name,
            type: $type,
            formula: $formula,
            version: $this->brew->formulaVersion($formula) ?? '',
            port: $port,
            dir: $this->dir().'/'.$name,
            createdAt: now()->toIso8601String(),
        );
        foreach ([$instance->dir, $instance->dataDir(), $instance->confDir(), $instance->runDir(), $instance->logDir()] as $d) {
            if (! is_dir($d)) {
                mkdir($d, 0755, true);
            }
        }
        $instance->save();

        try {
            foreach ($driver->initialize($instance, $binDir) as $step) {
                $log($step['label']);
                $result = $this->shell->run($step['argv'], $step['cwd'], $step['timeout'], fn ($type, $buf) => $log(rtrim($buf)));
                if (! $result->successful()) {
                    throw new RuntimeException("{$step['label']} failed (exit {$result->exitCode()}): ".trim($result->errorOutput() ?: $result->output()));
                }
            }
            // The agent gets devkit's whole environment: PATH and HOME, but also LC_ALL/LANG,
            // which PostgreSQL on macOS needs to start at all.
            $this->launchd->writePlist($name, $driver->programArguments($instance, $binDir), $instance->dir, $instance->logFile(), $this->shell->env());
        } catch (RuntimeException $e) {
            File::deleteDirectory($instance->dir);
            $this->launchd->removePlist($name);
            throw $e;
        }

        if ($start) {
            $log("starting {$name} on 127.0.0.1:{$port}");
            try {
                $this->start($instance);
            } catch (RuntimeException $e) {
                // Don't leave launchd crash-looping it; keep the instance so the logs can be read.
                try {
                    $this->stop($instance);
                } catch (RuntimeException) {
                }
                throw new RuntimeException($e->getMessage()."\n\nKept {$name} stopped for inspection: devkit services:logs {$name}   ·   devkit services:delete {$name}");
            }
        }

        return $instance;
    }

    // ── lifecycle ─────────────────────────────────────────────────────────────

    public function start(ServiceInstance $i): void
    {
        $this->launchd->enable($i->name);
        $this->launchd->bootstrap($i->name);
        $this->waitFor($i, up: true);
    }

    public function stop(ServiceInstance $i): void
    {
        $this->launchd->bootout($i->name);
        $this->launchd->disable($i->name);
        $this->waitFor($i, up: false);
    }

    public function restart(ServiceInstance $i): void
    {
        if (! $this->launchd->state($i->name)['loaded']) {
            $this->start($i);

            return;
        }
        $this->launchd->kickstart($i->name);
        $this->waitFor($i, up: true);
    }

    public function clone(ServiceInstance $source, string $newName, ?int $port = null, ?callable $log = null): ServiceInstance
    {
        $log ??= fn () => null;
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $newName)) {
            throw new RuntimeException("Instance name must be lowercase letters, digits and dashes, got [{$newName}].");
        }
        if ($this->find($newName) !== null) {
            throw new RuntimeException("Service [{$newName}] already exists.");
        }
        $driver = $this->driver($source);
        $binDir = $this->brew->formulaBinDir($source->formula) ?? throw new RuntimeException("Formula {$source->formula} is not installed.");
        $port = $this->allocatePort($port ?? $driver->defaultPort(), explicit: $port !== null);

        $wasRunning = $this->launchd->state($source->name)['loaded'];
        if ($wasRunning) {
            $log("stopping {$source->name} for a consistent copy");
            $this->stop($source);
        }

        $clone = $source->with(['name' => $newName, 'port' => $port, 'dir' => $this->dir().'/'.$newName, 'created_at' => now()->toIso8601String()]);
        foreach ([$clone->dir, $clone->confDir(), $clone->runDir(), $clone->logDir()] as $d) {
            mkdir($d, 0755, true);
        }
        $log("copying data ({$source->dataDir()} → {$clone->dataDir()})");
        File::copyDirectory($source->dataDir(), $clone->dataDir());
        if (is_dir($source->confDir())) {
            File::copyDirectory($source->confDir(), $clone->confDir());
        }
        $clone->save();
        $this->launchd->writePlist($newName, $driver->programArguments($clone, $binDir), $clone->dir, $clone->logFile(), $this->shell->env());

        if ($wasRunning) {
            $log("starting {$source->name}");
            $this->start($source);
        }
        $log("starting {$newName} on 127.0.0.1:{$port}");
        $this->start($clone);

        return $clone;
    }

    public function delete(ServiceInstance $i, bool $keepData = false): void
    {
        $this->launchd->bootout($i->name);
        $this->launchd->removePlist($i->name);
        if ($keepData) {
            @unlink($i->file());
        } else {
            File::deleteDirectory($i->dir);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    public function logTail(ServiceInstance $i, int $lines = 50): string
    {
        $files = array_filter([$i->logFile(), $i->logDir().'/mysql-error.log'], 'is_file');
        $out = '';
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $tail = array_slice(explode("\n", rtrim($content)), -$lines);
            $out .= "== ".basename($file)." ==\n".implode("\n", $tail)."\n";
        }

        return $out;
    }

    /**
     * Standard port when free, else the next free one. "Free" = nothing answers on 127.0.0.1
     * and no other instance has claimed it (instances may be stopped). Explicit ports must be free.
     */
    public function allocatePort(int $preferred, bool $explicit = false): int
    {
        $claimed = array_map(fn ($i) => $i->port, $this->all());
        $free = fn (int $p) => ! in_array($p, $claimed, true) && ! $this->probe->tcp('127.0.0.1', $p);

        if ($free($preferred)) {
            return $preferred;
        }
        if ($explicit) {
            $why = in_array($preferred, $claimed, true) ? 'is claimed by another devkit service' : 'is already in use on this machine (brew services? another instance?)';
            throw new RuntimeException("Port {$preferred} {$why}.");
        }
        for ($p = $preferred + 1; $p < $preferred + 200; $p++) {
            if ($free($p)) {
                return $p;
            }
        }
        throw new RuntimeException("No free port near {$preferred}.");
    }

    private function defaultName(string $type): string
    {
        if ($this->find($type) === null) {
            return $type;
        }
        for ($n = 2; $n < 100; $n++) {
            if ($this->find("{$type}-{$n}") === null) {
                return "{$type}-{$n}";
            }
        }
        throw new RuntimeException("Too many {$type} instances.");
    }

    /** Seconds to wait for a port to start/stop answering. Tests shorten it. */
    public int $readyTimeout = 30;

    private function waitFor(ServiceInstance $i, bool $up, ?int $seconds = null): void
    {
        $seconds ??= $this->readyTimeout;
        $deadline = microtime(true) + $seconds;
        do {
            if ($this->probe->tcp('127.0.0.1', $i->port) === $up) {
                return;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        $what = $up ? 'did not start answering' : 'is still answering';
        throw new RuntimeException("{$i->name} {$what} on 127.0.0.1:{$i->port} after {$seconds}s. Last log lines:\n".$this->logTail($i, 15));
    }
}
