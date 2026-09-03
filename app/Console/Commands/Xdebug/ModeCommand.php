<?php

namespace App\Console\Commands\Xdebug;

use App\Services\BrewBridge;
use App\Services\Php\XdebugManager;
use App\Services\Php\XdebugState;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

class ModeCommand extends Command
{
    protected $signature = 'xdebug:mode
        {mode : off | on | trigger | detect (follows the IDE: on while it listens)}
        {--php= : which version; defaults to the linked php}
        {--all : every version that has xdebug}
        {--no-restart : write the ini only (php-fpm keeps the old mode until valet restart php)}';

    protected $description = 'Switch Xdebug: off (not loaded) · on (every request) · trigger (XDEBUG_TRIGGER / browser helper)';

    public function handle(XdebugManager $xdebug, BrewBridge $brew, Shell $shell): int
    {
        $mode = (string) $this->argument('mode');
        if (! in_array($mode, XdebugState::MODES, true)) {
            $this->error('off, on, trigger or detect');

            return self::FAILURE;
        }
        $versions = $this->option('all')
            ? array_keys(array_filter($xdebug->status(), fn ($s) => $s['installed']))
            : [$this->option('php') ?: $brew->linkedPhp()];
        if ($versions === [] || $versions === [null]) {
            $this->error('No version with xdebug. nomeus xdebug:install <version>');

            return self::FAILURE;
        }

        $restart = false;
        foreach ($versions as $v) {
            try {
                $changed = $xdebug->setMode($v, $mode);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $effective = $xdebug->status()[$v]['effective'] ?? $mode;
            $this->line("php {$v}: xdebug <fg=".($mode === 'off' ? 'gray' : ($mode === 'on' ? 'yellow' : ($mode === 'detect' ? 'green' : 'blue'))).">{$mode}</>"
                .($mode === 'detect' ? " → {$effective} (IDE ".($effective === 'on' ? 'listening' : 'not listening').')' : '')
                .($changed ? '' : ' (ini unchanged)'));
            $restart = $restart || $changed;
        }

        if ($restart && ! $this->option('no-restart')) {
            try {
                $xdebug->restartAndWait(fn (string $l) => $this->line("<fg=gray>{$l}</>"));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } elseif ($restart) {
            $this->line('<fg=yellow>php-fpm still runs the previous mode:</> valet restart php');
        }
        if ($mode === 'on' && ! $xdebug->ideListening()) {
            $this->line("<fg=yellow>nothing is listening on 127.0.0.1:{$xdebug->port()}</> — every request will wait ~200 ms until your IDE listens; \"trigger\" avoids that.");
        }
        if ($mode === 'trigger') {
            $this->line('<fg=gray>start a session with the browser helper (Xdebug helper), ?XDEBUG_TRIGGER=1, or XDEBUG_TRIGGER=1 php artisan …</>');
        }
        if ($mode === 'detect') {
            $w = $xdebug->watcher();
            $this->line($w['running']
                ? "<fg=gray>watcher running (pid {$w['pid']}) — the ini follows your IDE, php-fpm restarts on each change</>"
                : '<fg=yellow>watcher not running</> — nomeus xdebug:watch --once syncs by hand; nomeus doctor names the cause');
        }

        return self::SUCCESS;
    }
}
