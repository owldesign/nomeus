<?php

namespace App\Services;

use App\Services\Services\Driver;
use App\Services\Services\DriverRegistry;
use App\Services\Services\NomeusBound;
use App\Services\Services\SiteBound;
use App\Support\NomeusConfig;
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
        private readonly NomeusConfig $config,
        private readonly BrewBridge $brew,
        private readonly DriverRegistry $drivers,
        private readonly ProcessManager $launchd,
        private readonly Shell $shell,
        private readonly Probe $probe,
        private readonly BrewServices $brewServices,
        private readonly ValetBridge $valet,
        private readonly \App\Services\Php\PhpProvider $php,
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

    /** @return array{running:bool, loaded:bool, pid:?int, last_exit:?int, crashing:bool, disabled:bool, installed:bool} */
    public function status(ServiceInstance $i): array
    {
        $launchd = $this->launchd->state($i->name);
        $running = $this->probe->tcp('127.0.0.1', $i->port);
        $driver = $this->driver($i);

        return [
            'running' => $running,
            'loaded' => $launchd['loaded'],
            'pid' => $launchd['pid'],
            'last_exit' => $launchd['last_exit'],
            // loaded, not answering, and its last run ended non-zero: launchd is relaunching a dying process
            'crashing' => $launchd['loaded'] && ! $running && $launchd['pid'] === null && ($launchd['last_exit'] ?? 0) !== 0,
            'disabled' => $launchd['disabled'],
            'installed' => match (true) {
                $driver instanceof SiteBound => is_dir(rtrim((string) ($i->options['site_path'] ?? ''), '/').'/'.$driver->siteRequirement()),
                $driver instanceof NomeusBound => true,
                default => $this->brew->isFormulaInstalled($i->formula),
            },
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
    public function create(string $type, ?string $version = null, ?string $name = null, ?int $port = null, bool $start = true, ?callable $log = null, ?string $site = null): ServiceInstance
    {
        $log ??= fn () => null;
        $driver = $this->drivers->get($type);
        $formula = $driver->formulaFor($version) ?? throw new RuntimeException("No {$driver->label()} formula for version [{$version}]. Known: ".implode(', ', $driver->formulae()));

        // ── runtime: a brew formula, or a site's own PHP + vendor dir ──────────────
        $options = $driver->defaultOptions();
        if ($driver instanceof SiteBound) {
            if ($site === null || $site === '') {
                throw new RuntimeException("{$driver->label()} runs inside a site: nomeus services:create {$type} --site=<name>");
            }
            $siteObj = $this->valet->find($site) ?? throw new RuntimeException("Site [{$site}] is not parked or linked.");
            if ($siteObj->type === 'proxy') {
                throw new RuntimeException("[{$siteObj->name}] is a proxy; {$driver->label()} needs a site with code.");
            }
            $requirement = rtrim($siteObj->path, '/').'/'.$driver->siteRequirement();
            if (! is_dir($requirement)) {
                throw new RuntimeException("{$driver->sitePackage()} is not installed in {$siteObj->name} ({$requirement} missing): cd {$siteObj->path} && composer require {$driver->sitePackage()}");
            }
            $binDir = $siteObj->php !== null
                ? (($bin = $this->php->phpBin($siteObj->php)) ? dirname($bin) : $this->brew->prefix()."/opt/php@{$siteObj->php}/bin")
                : $this->brew->prefix().'/bin';
            $problem = $this->brew->binaryRuns("{$binDir}/php");
            if ($problem !== null) {
                throw new RuntimeException("The PHP for {$siteObj->name} ({$binDir}/php) does not run:\n{$problem}");
            }
            $version = $this->packageVersion($siteObj->path, $driver->sitePackage()) ?? '';
            $options += ['site' => $siteObj->name, 'site_path' => $siteObj->path, 'php_bin_dir' => $binDir];
            $slug = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($siteObj->name)), '-');
            $name ??= $this->defaultName(str_starts_with($slug, $type) ? $slug : "{$type}-{$slug}");   // reverb-test stays reverb-test
        } elseif ($driver instanceof NomeusBound) {
            $binDir = dirname($this->shell->phpBin());
            $problem = $this->brew->binaryRuns("{$binDir}/php");
            if ($problem !== null) {
                throw new RuntimeException("nomeus's PHP ({$binDir}/php) does not run:\n{$problem}");
            }
            $version = (string) config('nomeus.version');
            $options += ['site' => 'nomeus', 'site_path' => base_path(), 'php_bin_dir' => $binDir];
        }

        $name ??= $this->defaultName($type);
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            throw new RuntimeException("Instance name must be lowercase letters, digits and dashes, got [{$name}].");
        }
        if ($this->find($name) !== null) {
            throw new RuntimeException("Service [{$name}] already exists.");
        }

        $port = $this->allocatePort($port ?? $driver->defaultPort(), explicit: $port !== null);
        $options += $this->allocateAuxPorts($driver, [$port]);

        if (! $driver instanceof SiteBound && ! $driver instanceof NomeusBound) {
            if (! $this->brew->isFormulaInstalled($formula)) {
                $this->installFormula($formula, $log);
            }
            $binDir = $this->brew->formulaBinDir($formula) ?? throw new RuntimeException("Formula {$formula} has no bin dir under ".$this->brew->prefix().'/opt');
            $this->preflight($formula, $driver);
            $version = $this->brew->formulaVersion($formula) ?? '';
        }

        $instance = $this->materialize(new ServiceInstance(
            name: $name,
            type: $type,
            formula: $formula,
            version: $version,
            port: $port,
            dir: $this->dir().'/'.$name,
            createdAt: now()->toIso8601String(),
            options: $options,
        ));

        try {
            $this->runSteps($driver->initialize($instance, $binDir), $log);
            $this->writeAgent($instance, $driver, $binDir);
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
                throw new RuntimeException($e->getMessage()."\n\nKept {$name} stopped for inspection: nomeus services:logs {$name}   ·   nomeus services:delete {$name}");
            }
        }

        return $instance;
    }

    // ── adopt: a `brew services` cluster becomes a nomeus instance, data copied ───

    /**
     * @param  callable(string):void|null  $log
     */
    /**
     * @param  string|null  $runAs  run the adopted data under this formula instead of brew's — e.g. brew's
     *                              `mysql` (9.6 data) under `mysql@9.7`, which upgrades it in place on start.
     * @param  callable(string):void|null  $log
     */
    public function adopt(string $formula, ?string $name = null, ?int $port = null, ?callable $log = null, ?string $runAs = null): ServiceInstance
    {
        $log ??= fn () => null;
        $driver = $this->drivers->driverForFormula($formula)
            ?? throw new RuntimeException("No nomeus driver for [{$formula}]. Adoptable: ".implode(', ', array_map(fn ($s) => $s['formula'], $this->brewServices->adoptable())));
        $formula = $this->brew->shortName($formula);
        $src = $driver->brewDataDir($this->brew->prefix(), $formula);
        if ($src === null || ! is_dir($src)) {
            throw new RuntimeException("brew has no data directory for {$formula}".($src ? " at {$src}" : '').'.');
        }

        $runFormula = $runAs !== null ? $this->brew->shortName($runAs) : $formula;
        if ($runAs !== null && $this->drivers->driverForFormula($runFormula)?->type() !== $driver->type()) {
            throw new RuntimeException("[{$runFormula}] is not a {$driver->label()} formula; adopting {$formula} data needs one of: ".implode(', ', $driver->formulae()));
        }
        if (! $this->brew->isFormulaInstalled($runFormula)) {
            $this->installFormula($runFormula, $log);
        }
        $binDir = $this->brew->formulaBinDir($runFormula) ?? throw new RuntimeException("Formula {$runFormula} has no bin dir.");
        $this->preflight($runFormula, $driver); // before brew's service is stopped: a broken binary means no takeover at all

        $name = $name ?? $this->defaultName($driver->type());
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            throw new RuntimeException("Instance name must be lowercase letters, digits and dashes, got [{$name}].");
        }
        if ($this->find($name) !== null) {
            throw new RuntimeException("Service [{$name}] already exists.");
        }

        $brewSvc = $this->brewServices->find($formula);
        if ($brewSvc !== null && ($brewSvc['loaded'] || $brewSvc['plist'] !== null)) {
            $log("brew services stop {$formula}");
            $this->brewServices->stop($formula);
        }
        $this->waitForFilesGone($driver->lockFilesIn($src), 'brew\'s '.$formula.' to finish shutting down');

        // Standard port is usually free now — brew's copy just stopped.
        $port = $this->allocatePort($port ?? $driver->defaultPort(), explicit: $port !== null);

        $instance = $this->materialize(new ServiceInstance(
            name: $name,
            type: $driver->type(),
            formula: $runFormula,
            version: $this->brew->formulaVersion($runFormula) ?? '',
            port: $port,
            dir: $this->dir().'/'.$name,
            createdAt: now()->toIso8601String(),
            options: ['adopted_from' => $src, 'adopted_at' => now()->toIso8601String()]
                + ($runFormula !== $formula ? ['adopted_formula' => $formula] : []),
        ));

        try {
            $log("copying {$src} → {$instance->dataDir()} (brew's copy is left in place)");
            // cp -a: modes, ownership and timestamps intact — Postgres insists on 0700.
            $result = $this->shell->run(['cp', '-a', rtrim($src, '/').'/.', $instance->dataDir().'/'], null, 3600);
            if (! $result->successful()) {
                throw new RuntimeException('copy failed: '.trim($result->errorOutput() ?: $result->output()));
            }
            $this->removeStaleFiles($instance);
            $this->writeAgent($instance, $driver, $binDir);
        } catch (RuntimeException $e) {
            File::deleteDirectory($instance->dir);
            $this->launchd->removePlist($name);
            throw $e;
        }

        $log("starting {$name} on 127.0.0.1:{$port}");
        try {
            $this->start($instance);
            $this->runSteps($driver->postAdopt($instance, $binDir), $log);
        } catch (RuntimeException $e) {
            try {
                $this->stop($instance);
            } catch (RuntimeException) {
            }
            throw new RuntimeException($e->getMessage()."\n\nKept {$name} stopped for inspection: nomeus services:logs {$name}   ·   nomeus services:delete {$name}. brew's data is untouched at {$src}; `brew services start {$formula}` restores the old setup.");
        }

        return $instance;
    }

    // ── retarget: same instance, same data, different formula of the same type ───

    /**
     * Point an instance at another formula (e.g. mysql → mysql@9.7). The server performs whatever
     * in-place data upgrade it supports on the next start; nomeus only swaps the binary the agent runs.
     * One-way for databases — MySQL and Postgres do not downgrade a data dir.
     *
     * @param  callable(string):void|null  $log
     */
    public function retarget(ServiceInstance $i, string $formula, ?callable $log = null): ServiceInstance
    {
        $log ??= fn () => null;
        $formula = $this->brew->shortName($formula);
        $driver = $this->driver($i);
        if ($this->drivers->driverForFormula($formula)?->type() !== $i->type) {
            throw new RuntimeException("[{$formula}] is not a {$driver->label()} formula. Known: ".implode(', ', $driver->formulae()));
        }
        if ($formula === $i->formula) {
            throw new RuntimeException("{$i->name} already runs {$formula}.");
        }
        if (! $this->brew->isFormulaInstalled($formula)) {
            $this->installFormula($formula, $log);
        }
        $binDir = $this->brew->formulaBinDir($formula) ?? throw new RuntimeException("Formula {$formula} has no bin dir.");
        $this->preflight($formula, $driver);

        if ($this->launchd->state($i->name)['loaded']) {
            $log("stopping {$i->name}");
            $this->stop($i);
            $this->waitForStaleFilesGone($i);
        }

        $updated = $i->with([
            'formula' => $formula,
            'version' => $this->brew->formulaVersion($formula) ?? '',
            'options' => ['previous_formula' => $i->formula, 'retargeted_at' => now()->toIso8601String()] + $i->options,
        ]);
        $updated->save();
        $this->writeAgent($updated, $driver, $binDir);

        $log("starting {$updated->name} as {$formula} {$updated->version} on 127.0.0.1:{$updated->port}");
        try {
            $this->start($updated);
        } catch (RuntimeException $e) {
            try {
                $this->stop($updated);
            } catch (RuntimeException) {
            }
            throw new RuntimeException($e->getMessage()."\n\nKept {$updated->name} stopped (now {$formula}): nomeus services:logs {$updated->name}. To go back: nomeus services:upgrade {$updated->name} {$i->formula} — only if the server did not already rewrite the data.");
        }

        return $updated;
    }

    // ── lifecycle ─────────────────────────────────────────────────────────────

    public function start(ServiceInstance $i): void
    {
        // Not held by launchd → no live process owns the data dir → any lock file is stale.
        if (! $this->launchd->state($i->name)['loaded']) {
            $this->removeStaleFiles($i);
        }
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
        if ($driver instanceof SiteBound || $driver instanceof NomeusBound) {
            throw new RuntimeException("{$source->name} is a process, not a data service; nothing to clone.");
        }
        $binDir = $this->brew->formulaBinDir($source->formula) ?? throw new RuntimeException("Formula {$source->formula} is not installed.");
        $port = $this->allocatePort($port ?? $driver->defaultPort(), explicit: $port !== null);

        $wasRunning = $this->launchd->state($source->name)['loaded'];
        if ($wasRunning) {
            $log("stopping {$source->name} for a consistent copy");
            $this->stop($source);
            // The port closes before the shutdown checkpoint finishes; the lock file goes last.
            $this->waitForStaleFilesGone($source);
        }

        $clone = $source->with([
            'name' => $newName, 'port' => $port, 'dir' => $this->dir().'/'.$newName, 'created_at' => now()->toIso8601String(),
            'options' => $this->allocateAuxPorts($driver, [$port]) + $source->options,   // fresh listeners; keys and secrets carry over
        ]);
        foreach ([$clone->dir, $clone->confDir(), $clone->runDir(), $clone->logDir()] as $d) {
            mkdir($d, 0755, true);
        }
        $log("copying data ({$source->dataDir()} → {$clone->dataDir()})");
        File::copyDirectory($source->dataDir(), $clone->dataDir());
        // copyDirectory creates directories as 0777 & umask (0755); Postgres refuses anything but 0700/0750.
        // The source's mode is what initdb (or mysqld) chose, so mirror it.
        chmod($clone->dataDir(), fileperms($source->dataDir()) & 0777);
        if (is_dir($source->confDir())) {
            File::copyDirectory($source->confDir(), $clone->confDir());
        }
        $this->removeStaleFiles($clone); // whatever slipped into the copy must not name the source's pid or identity
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
    public function allocatePort(int $preferred, bool $explicit = false, array $reserved = []): int
    {
        $claimed = $reserved;
        foreach ($this->all() as $i) {
            $claimed[] = $i->port;
            foreach ($i->options as $k => $v) {
                if (str_ends_with((string) $k, '_port') && is_int($v)) {
                    $claimed[] = $v;   // aux listeners count too
                }
            }
        }
        $free = fn (int $p) => ! in_array($p, $claimed, true) && ! $this->probe->tcp('127.0.0.1', $p);

        if ($free($preferred)) {
            return $preferred;
        }
        if ($explicit) {
            $why = in_array($preferred, $claimed, true) ? 'is claimed by another nomeus service' : 'is already in use on this machine (brew services? another instance?)';
            throw new RuntimeException("Port {$preferred} {$why}.");
        }
        for ($p = $preferred + 1; $p < $preferred + 200; $p++) {
            if ($free($p)) {
                return $p;
            }
        }
        throw new RuntimeException("No free port near {$preferred}.");
    }

    /** brew install, trusting the formula's tap first when it has one. */
    private function installFormula(string $formula, callable $log): void
    {
        $trust = $this->brew->trustTapPlan($formula);
        if ($trust !== null) {
            $log($trust['label']);
            $result = $this->shell->run($trust['argv'], null, $trust['timeout']);
            if (! $result->successful()) {
                throw new RuntimeException("{$trust['label']} failed: ".trim($result->errorOutput() ?: $result->output()));
            }
        }
        $plan = $this->brew->installFormulaPlan($formula);
        $log("{$plan['label']} (not installed yet)");
        $result = $this->shell->run($plan['argv'], null, $plan['timeout'], fn ($type, $buf) => $log(rtrim($buf)));
        if (! $result->successful()) {
            throw new RuntimeException("brew install {$formula} failed (exit {$result->exitCode()}).");
        }
    }

    /** Fail in a second with the dyld error, not after a 30 s wait for a port that will never answer. */
    private function preflight(string $formula, Driver $driver): void
    {
        $problem = $this->brew->binaryCheck($formula, $driver->binary(), $driver->versionArgs());
        if ($problem !== null) {
            throw new RuntimeException("{$formula}'s {$driver->binary()} does not run:\n{$problem}\n\nUsually Homebrew dependency drift after an upgrade — fix with: brew reinstall {$formula}");
        }
    }

    /** Rewrite an instance's launchd plist from its current record (paths, label, env). */
    public function refreshAgent(ServiceInstance $i): void
    {
        $driver = $this->driver($i);
        $binDir = $driver instanceof SiteBound || $driver instanceof NomeusBound
            ? (string) ($i->options['php_bin_dir'] ?? dirname($this->shell->phpBin()))
            : ($this->brew->formulaBinDir($i->formula) ?? throw new RuntimeException("Formula {$i->formula} is not installed."));
        $this->writeAgent($i, $driver, $binDir);
    }

    /** Directories, service.json — the parts every instance has before anything runs. */
    private function materialize(ServiceInstance $instance): ServiceInstance
    {
        foreach ([$instance->dir, $instance->dataDir(), $instance->confDir(), $instance->runDir(), $instance->logDir()] as $d) {
            if (! is_dir($d)) {
                mkdir($d, 0755, true);
            }
        }
        $instance->save();

        return $instance;
    }

    /**
     * The agent gets nomeus's whole environment: PATH and HOME, but also LC_ALL/LANG,
     * which PostgreSQL on macOS needs to start at all.
     */
    private function writeAgent(ServiceInstance $instance, Driver $driver, string $binDir): void
    {
        $this->launchd->writePlist(
            $instance->name,
            $driver->programArguments($instance, $binDir),
            $driver->workingDirectory($instance),
            $instance->logFile(),
            $this->shell->env(),
        );
    }

    /** @return array<string,int> "<name>_port" => allocated port for each of the driver's aux listeners */
    private function allocateAuxPorts(Driver $driver, array $reserved): array
    {
        $out = [];
        foreach ($driver->auxPorts() as $key => $default) {
            $p = $this->allocatePort($default, explicit: false, reserved: $reserved);
            $out["{$key}_port"] = $p;
            $reserved[] = $p;
        }

        return $out;
    }

    /** Installed version of a composer package inside a site, from vendor/composer/installed.json. */
    private function packageVersion(string $sitePath, string $package): ?string
    {
        $file = rtrim($sitePath, '/').'/vendor/composer/installed.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        foreach ((array) ($data['packages'] ?? $data ?? []) as $pkg) {
            if (($pkg['name'] ?? null) === $package) {
                return ltrim((string) ($pkg['version'] ?? ''), 'v') ?: null;
            }
        }

        return null;
    }

    /** @param  list<array{label:string, argv:list<string>, cwd:?string, timeout:int}>  $steps */
    private function runSteps(array $steps, callable $log): void
    {
        foreach ($steps as $step) {
            $log($step['label']);
            $result = $this->shell->run($step['argv'], $step['cwd'], $step['timeout'], fn ($type, $buf) => $log(rtrim($buf)));
            if (! $result->successful()) {
                throw new RuntimeException("{$step['label']} failed (exit {$result->exitCode()}): ".trim($result->errorOutput() ?: $result->output()));
            }
        }
    }

    private function removeStaleFiles(ServiceInstance $i): void
    {
        foreach ($this->driver($i)->staleFiles($i) as $file) {
            if (file_exists($file) || is_link($file)) {
                @unlink($file);
            }
        }
    }

    /** Seconds to wait for a stopped server to finish its shutdown (lock file removed). Tests shorten it. */
    public int $shutdownTimeout = 15;

    private function waitForStaleFilesGone(ServiceInstance $i, ?int $seconds = null): void
    {
        $files = array_filter($this->driver($i)->staleFiles($i), fn ($f) => ! str_ends_with($f, 'auto.cnf'));
        $this->waitForFilesGone($files, "{$i->name} to finish shutting down", $seconds);
    }

    /** @param  list<string>  $files */
    private function waitForFilesGone(array $files, string $what, ?int $seconds = null): void
    {
        $deadline = microtime(true) + ($seconds ?? $this->shutdownTimeout);
        while (microtime(true) < $deadline) {
            clearstatcache();
            if (array_filter($files, 'file_exists') === []) {
                return;
            }
            usleep(250_000);
        }
        // Give up waiting for {$what}; the copy is stripped afterwards anyway.
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
