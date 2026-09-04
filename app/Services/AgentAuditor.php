<?php

namespace App\Services;

use App\Services\Php\XdebugWatcher;
use App\Services\Services\NomeusBound;
use App\Support\Shell;

/**
 * The agents that run *nomeus itself* — the dump server instance and the xdebug watcher — carry this app's
 * artisan path, its php and its working directory inside their unit files, written at creation. Move the
 * checkout, rename it (devkit → nomeus), relink php, and launchd exec()s a path that isn't there: exit 78,
 * crash loop, and nothing in the dashboard says why. This compares each unit with what would be written
 * today and rewrites the ones that differ. Brew-backed services aren't covered: their paths come from the
 * formula, and `services:upgrade` owns those.
 */
final class AgentAuditor
{
    public function __construct(
        private readonly ServiceManager $services,
        private readonly ProcessManager $launchd,
        private readonly XdebugWatcher $watcher,
        private readonly Shell $shell,
    ) {}

    /**
     * One entry per nomeus-bound agent that has a unit file.
     *
     * @return list<array{name:string, kind:string, stale:bool, reasons:list<string>, expected:array{argv:list<string>, cwd:string}, actual:array{argv:list<string>, cwd:?string}}>
     */
    public function audit(): array
    {
        $out = [];
        $php = $this->shell->phpBin();
        $artisan = base_path('artisan');

        foreach ($this->services->all() as $i) {
            $driver = $this->services->driver($i);
            if (! $driver instanceof NomeusBound) {
                continue;
            }
            $actual = $this->launchd->readAgent($i->name);
            if ($actual === null) {
                continue;   // no unit yet (created with --no-start, or deleted by hand): nothing to compare
            }
            $expected = ['argv' => $driver->programArguments($i->with(['options' => ['site_path' => base_path()] + $i->options]), dirname($php)), 'cwd' => base_path()];
            $out[] = $this->entry($i->name, 'service', $expected, $actual);
        }

        $actual = $this->launchd->readAgent(XdebugWatcher::NAME);
        if ($actual !== null) {
            $out[] = $this->entry(XdebugWatcher::NAME, 'watcher', ['argv' => [$php, $artisan, 'xdebug:watch'], 'cwd' => base_path()], $actual);
        }

        return $out;
    }

    /** @return list<array> the stale entries only */
    public function stale(): array
    {
        return array_values(array_filter($this->audit(), fn (array $e) => $e['stale']));
    }

    /**
     * Rewrite every stale agent from today's paths and bounce the ones launchd holds. Idempotent.
     *
     * @return list<string> names rewritten
     */
    public function rewrite(?callable $log = null): array
    {
        $log ??= fn () => null;
        $done = [];
        foreach ($this->stale() as $e) {
            $log("{$e['name']}: ".implode('; ', $e['reasons']));
            if ($e['kind'] === 'watcher') {
                // enable() writes the unit from scratch and bootstraps it; disable() first so launchd drops the old one
                $this->watcher->disable();
                $this->watcher->enable();
            } else {
                $i = $this->services->find($e['name']);
                if ($i === null) {
                    continue;
                }
                $loaded = $this->launchd->state($i->name)['loaded'];
                if ($loaded) {
                    $this->services->stop($i);
                }
                $i = $this->services->refreshAgent($i);
                if ($loaded) {
                    $this->services->start($i);
                }
            }
            $log("{$e['name']}: rewritten → ".implode(' ', array_slice($e['expected']['argv'], 0, 2)));
            $done[] = $e['name'];
        }

        return $done;
    }

    private function entry(string $name, string $kind, array $expected, array $actual): array
    {
        $reasons = [];
        foreach (array_slice($actual['argv'], 0, 2) as $path) {   // php, then artisan — the two absolute paths
            if (str_starts_with($path, '/') && ! file_exists($path)) {
                $reasons[] = "{$path} is missing";
            }
        }
        if ($actual['cwd'] !== null && ! is_dir($actual['cwd'])) {
            $reasons[] = "working directory {$actual['cwd']} is missing";
        }
        if (($actual['argv'][1] ?? null) !== $expected['argv'][1]) {
            $reasons[] = 'runs '.($actual['argv'][1] ?? '(nothing)').', this app is '.$expected['argv'][1];
        }
        if (($actual['argv'][0] ?? null) !== $expected['argv'][0]) {
            $reasons[] = 'php '.($actual['argv'][0] ?? '(none)').', now '.$expected['argv'][0];
        }
        if ($actual['argv'] !== $expected['argv'] && array_slice($actual['argv'], 0, 2) === array_slice($expected['argv'], 0, 2)) {
            $reasons[] = 'arguments changed: '.implode(' ', array_slice($actual['argv'], 2)).' → '.implode(' ', array_slice($expected['argv'], 2));
        }
        if ($actual['argv'] === $expected['argv'] && $actual['cwd'] !== $expected['cwd']) {
            $reasons[] = "working directory {$actual['cwd']}, this app is {$expected['cwd']}";
        }

        return ['name' => $name, 'kind' => $kind, 'stale' => $reasons !== [], 'reasons' => array_values(array_unique($reasons)), 'expected' => $expected, 'actual' => $actual];
    }
}
