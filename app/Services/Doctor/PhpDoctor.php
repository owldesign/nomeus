<?php

namespace App\Services\Doctor;

use App\Services\BrewBridge;
use App\Services\Dumps\PrependInstaller;
use App\Services\Php\XdebugManager;
use App\Services\PhpManager;

final class PhpDoctor implements Section
{
    public function __construct(
        private readonly BrewBridge $brew,
        private readonly PhpManager $php,
        private readonly PrependInstaller $prepend,
        private readonly XdebugManager $xdebug,
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
            return $r->fail('installed', 'no brew php — devkit php:install 8.4')->all();
        }
        $linked = $this->brew->linkedPhp();
        $r->expect($linked !== null, 'linked', "php {$linked} ({$this->brew->phpPatch((string) $linked)})", 'no linked php — devkit php:use 8.4');
        $running = $this->php->runningFpmVersions();
        $r->expect($running !== [] && $running !== ['unknown'], 'php-fpm', 'running: '.implode(', ', $running), 'no php-fpm socket answers — valet restart php');

        $outdated = $this->brew->outdatedPhp();
        $r->expect($outdated === [], 'brew outdated', 'all php versions current', 'outdated: '.implode(', ', array_map(fn ($v, $patch) => "php {$v} → {$patch}", array_keys($outdated), $outdated)).' — devkit php:update <version>', 'warn');

        $ini = $this->prepend->status();
        foreach ($installed as $v) {
            $st = $ini[$v] ?? ['ini' => false, 'current' => false];
            $r->expect($st['current'], "99-devkit.ini php {$v}", 'current', ($st['ini'] ? 'outdated' : 'missing').' — devkit dumps:install (then valet restart php)', 'warn');
        }
        $ide = $this->xdebug->ideListening();
        foreach ($this->xdebug->status() as $v => $x) {
            if (! $x['installed']) {
                continue;
            }
            if ($x['tap_ini']) {
                $r->warn("xdebug php {$v}", "the formula's 20-xdebug.ini is back (brew upgrade?) — devkit xdebug:mode {$x['mode']} --php={$v} re-quarantines it");
            } elseif ($x['mode'] === 'on' && ! $ide) {
                $r->warn("xdebug php {$v}", "mode on with nothing listening on {$this->xdebug->port()} — ~200 ms per request; devkit xdebug:mode trigger --php={$v}");
            } else {
                $r->ok("xdebug php {$v}", "mode {$x['mode']}");
            }
        }

        return $r->all();
    }
}
