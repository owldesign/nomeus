<?php

namespace App\Services\Doctor;

use App\Services\Dumps\PrependInstaller;
use App\Services\Node\NodeManager;
use App\Services\Php\PhpExtensions;
use App\Services\Php\PhpProvider;
use App\Services\Php\XdebugManager;
use App\Services\PhpManager;
use App\Services\ValetBridge;

final class PhpDoctor implements Section
{
    public function __construct(
        private readonly PhpProvider $brew,
        private readonly PhpManager $php,
        private readonly PrependInstaller $prepend,
        private readonly XdebugManager $xdebug,
        private readonly PhpExtensions $extensions,
        private readonly ValetBridge $valet,
        private readonly NodeManager $node,
    ) {}

    public function name(): string
    {
        return 'php';
    }

    public function checks(): array
    {
        $r = new Rows;
        $installed = $this->brew->installedPhp();
        if ($installed === []) {
            return $r->fail('installed', 'no php from '.$this->brew->sourceName().' — nomeus php:install 8.4')->all();
        }
        $linked = $this->brew->linkedPhp();
        $r->expect($linked !== null, 'linked', "php {$linked} ({$this->brew->phpPatch((string) $linked)})", 'no linked php — nomeus php:use 8.4');
        $running = $this->php->runningFpmVersions();
        $r->expect($running !== [] && $running !== ['unknown'], 'php-fpm', 'running: '.implode(', ', $running), 'no php-fpm socket answers — valet restart php');

        $outdated = $this->brew->outdatedPhp();
        $r->expect($outdated === [], $this->brew->sourceName().' outdated', 'all php versions current', 'outdated: '.implode(', ', array_map(fn ($v, $patch) => "php {$v} → {$patch}", array_keys($outdated), $outdated)).' — nomeus php:update <version>', 'warn');

        $ini = $this->prepend->status();
        foreach ($installed as $v) {
            $st = $ini[$v] ?? ['ini' => false, 'current' => false];
            $r->expect($st['current'], "99-nomeus.ini php {$v}", 'current', ($st['ini'] ? 'outdated' : 'missing').' — nomeus dumps:install (then valet restart php)', 'warn');
        }
        $ide = $this->xdebug->ideListening();
        foreach ($this->xdebug->status() as $v => $x) {
            if (! $x['installed']) {
                continue;
            }
            if ($x['tap_ini']) {
                $r->warn("xdebug php {$v}", "the vendor's 20-xdebug.ini is back (an upgrade?) — nomeus xdebug:mode {$x['mode']} --php={$v} re-quarantines it");
            } elseif ($x['mode'] === 'detect' && ! $this->xdebug->watcher()['running']) {
                $r->warn("xdebug php {$v}", "detect mode but the watcher agent is not running — nomeus xdebug:mode detect --php={$v} re-installs it (log: ~/.nomeus/php/xdebug-detect.log)");
            } elseif ($x['mode'] === 'on' && ! $ide) {
                $r->warn("xdebug php {$v}", "mode on with nothing listening on {$this->xdebug->port()} — ~200 ms per request; nomeus xdebug:mode trigger --php={$v}");
            } else {
                $r->ok("xdebug php {$v}", "mode {$x['mode']}".($x['mode'] === 'detect' ? " → {$x['effective']}" : ''));
            }
        }

        // sites whose .env leans on redis need the extension on the php they run
        $loaded = [];
        foreach ($this->valet->isInstalled() ? $this->valet->sites() : [] as $site) {
            if ($site->type === 'proxy' || ! is_file("{$site->path}/.env")) {
                continue;
            }
            $env = (string) file_get_contents("{$site->path}/.env");
            if (! preg_match('/^(SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION|BROADCAST_CONNECTION)=redis\s*$/m', $env) || preg_match('/^REDIS_CLIENT=predis/m', $env)) {
                continue;
            }
            $v = $site->php ?? $linked;
            if ($v === null) {
                continue;
            }
            $loaded[$v] ??= $this->extensions->has($v, 'redis');
            if (! $loaded[$v]) {
                $r->warn("redis ext {$site->name}", "the site's .env uses redis but php {$v} has no redis extension — nomeus php:ext redis --php={$v}");
            }
        }

        // node: fnm present, every pinned version installed
        if (! $this->node->available()) {
            $r->warn('node', 'fnm is not installed — brew install fnm; sites\' .nvmrc pins are only honoured by hand');
        } else {
            $installed = $this->node->installed();
            $r->ok('node', ($installed['versions'] === [] ? 'no versions yet — nomeus node:install lts' : 'node '.implode(', ', $installed['versions'])).($installed['default'] ? " · default {$installed['default']}" : ''));
            foreach ($this->node->pins($this->valet->isInstalled() ? $this->valet->sites() : []) as $pin) {
                if ($pin['installed'] === null) {
                    $r->warn("node pin {$pin['site']}", ".nvmrc wants {$pin['pin']} but it is not installed — nomeus node:install {$pin['pin']}");
                }
            }
        }

        return $r->all();
    }
}
