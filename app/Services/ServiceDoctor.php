<?php

namespace App\Services;

use App\Support\Probe;
use App\Support\Shell;
use RuntimeException;

/** The services layer, checked end to end. Every check answers ok / warn / fail with a next step. */
final class ServiceDoctor
{
    public function __construct(
        private readonly ServiceManager $services,
        private readonly LaunchdManager $launchd,
        private readonly BrewBridge $brew,
        private readonly BrewServices $brewServices,
        private readonly Shell $shell,
        private readonly Probe $probe,
        private readonly \App\Services\Dumps\PrependInstaller $prepend,
        private readonly ?\App\Services\Php\XdebugManager $xdebug = null,
    ) {}

    /** @return list<array{level:'ok'|'warn'|'fail', check:string, detail:string}> */
    public function checks(): array
    {
        $c = [];
        $add = function (string $level, string $check, string $detail) use (&$c): void {
            $c[] = ['level' => $level, 'check' => $check, 'detail' => $detail];
        };

        // launchd domain
        $domain = $this->shell->run(['launchctl', 'print', $this->launchd->domain()], timeout: 15);
        $domain->successful()
            ? $add('ok', 'launchd', $this->launchd->domain().' reachable')
            : $add('fail', 'launchd', $this->launchd->domain().' unreachable — run from a login session; dashboard tasks need `launchctl asuser`');

        // agents dir
        $agents = dirname($this->launchd->plistPath('x'));
        is_dir($agents) && is_writable($agents)
            ? $add('ok', 'agents dir', $agents)
            : $add('fail', 'agents dir', "{$agents} missing or not writable");

        // formula binaries actually load (dependency drift shows up here, not at start time)
        $instances = $this->services->all();
        $formulae = array_unique(array_map(fn ($i) => $i->formula, $instances));
        foreach ($formulae as $formula) {
            $driver = $this->services->driver($instances[array_search($formula, array_map(fn ($i) => $i->formula, $instances), true)]);
            if (! $this->brew->isFormulaInstalled($formula)) {
                continue; // reported per instance below
            }
            $problem = $this->brew->binaryCheck($formula, $driver->binary(), $driver->versionArgs());
            $problem === null
                ? $add('ok', "binary {$formula}", "{$driver->binary()} --version runs")
                : $add('fail', "binary {$formula}", strtok($problem, "\n").' — brew reinstall '.$formula);
        }

        // instances
        $ports = [];
        foreach ($instances as $i) {
            $ports[$i->port][] = $i->name;
            $st = $this->services->status($i);
            $name = "instance {$i->name}";

            if (! $st['installed']) {
                $driver = $this->services->driver($i);
                $add('fail', $name, $driver instanceof \App\Services\Services\SiteBound
                    ? "{$driver->sitePackage()} is no longer installed in site {$i->options['site']} — composer require {$driver->sitePackage()} there"
                    : "formula {$i->formula} is not installed — brew install {$i->formula}");
                continue;
            }
            if (! file_exists($this->launchd->plistPath($i->name))) {
                $add('fail', $name, 'launchd agent plist missing — services:delete --keep-data then recreate, or restore from git');
                continue;
            }
            match (true) {
                $st['running'] && $st['loaded'] => $add('ok', $name, "running on 127.0.0.1:{$i->port} (pid {$st['pid']})"),
                $st['running'] => $add('warn', $name, "port {$i->port} answers but launchd isn't holding the instance — something else on that port?"),
                $st['crashing'] => $add('fail', $name, "crash-looping (last exit {$st['last_exit']}) — services:logs {$i->name}"),
                $st['loaded'] => $add('warn', $name, "loaded, not answering yet on {$i->port}"),
                default => $add('ok', $name, $st['disabled'] ? 'stopped (disabled at login)' : 'stopped'),
            };
            if (! $st['loaded']) {
                $stale = array_filter($this->services->driver($i)->staleFiles($i), fn ($f) => file_exists($f) && ! str_ends_with($f, 'auto.cnf'));
                if ($stale !== []) {
                    $add('warn', $name, 'stale lock file(s) while stopped: '.implode(', ', array_map('basename', $stale)).' — start removes them');
                }
            }
        }
        foreach ($ports as $port => $names) {
            if (count($names) > 1) {
                $add('fail', 'ports', "instances share port {$port}: ".implode(', ', $names));
            }
        }

        // dumps: prepend ini present in every php version
        foreach ($this->prepend->status() as $version => $st) {
            $st['current']
                ? $add('ok', "dumps ini php {$version}", $this->prepend->iniPath($version))
                : $add('warn', "dumps ini php {$version}", ($st['ini'] ? 'outdated' : 'missing').' — nomeus dumps:install (then valet restart php)');
        }

        // xdebug: the formula's own ini must stay quarantined; "on" without a listener costs every request
        if ($this->xdebug !== null) {
            $ide = $this->xdebug->ideListening();
            foreach ($this->xdebug->status() as $version => $x) {
                if (! $x['installed']) {
                    continue;
                }
                if ($x['tap_ini']) {
                    $add('warn', "xdebug php {$version}", "the formula's 20-xdebug.ini is back (brew upgrade?) and loads xdebug unconditionally — nomeus xdebug:mode {$x['mode']} --php={$version} re-quarantines it");
                } elseif ($x['mode'] === 'on' && ! $ide) {
                    $add('warn', "xdebug php {$version}", "mode on but nothing listens on 127.0.0.1:{$this->xdebug->port()} — ~200 ms per request; nomeus xdebug:mode trigger --php={$version}");
                } else {
                    $add('ok', "xdebug php {$version}", "mode {$x['mode']}");
                }
            }
        }

        // brew services overlap
        foreach ($this->brewServices->adoptable() as $svc) {
            if ($svc['loaded'] || $svc['plist']) {
                $add('warn', 'brew services', "{$svc['formula']} runs under brew services".($svc['answering'] ? " on {$svc['port']}" : '')." — take it over with: nomeus services:adopt {$svc['formula']}");
            }
        }
        if ($instances === [] && $this->brewServices->adoptable() === []) {
            $add('ok', 'instances', 'none yet — services:create <type>');
        }

        return $c;
    }

    /** create → clone → connect → delete on a throwaway redis. Throws on the first failure; always cleans up. */
    public function selfTest(callable $log): void
    {
        $name = 'selftest-'.substr(md5((string) microtime(true)), 0, 6);
        $clone = null;
        $i = null;
        try {
            $log("create {$name}");
            $i = $this->services->create('redis', null, $name, null, true, $log);
            if (! $this->probe->tcp('127.0.0.1', $i->port)) {
                throw new RuntimeException("{$name} is not answering on {$i->port}");
            }
            $log("clone {$name} → {$name}-copy");
            $clone = $this->services->clone($i, "{$name}-copy", null, $log);
            if (! $this->probe->tcp('127.0.0.1', $clone->port)) {
                throw new RuntimeException("{$name}-copy is not answering on {$clone->port}");
            }
            if (! $this->probe->tcp('127.0.0.1', $i->port)) {
                throw new RuntimeException("{$name} did not come back after the clone");
            }
            $log('round trip ok');
        } finally {
            foreach (array_filter([$clone, $i]) as $x) {
                $log("delete {$x->name}");
                try {
                    $this->services->delete($x);
                } catch (RuntimeException $e) {
                    $log("cleanup of {$x->name} failed: {$e->getMessage()}");
                }
            }
        }
    }
}
