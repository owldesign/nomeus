<?php

namespace App\Console\Commands\Xdebug;

use App\Services\Php\XdebugManager;
use Illuminate\Console\Command;

/**
 * Detect mode\'s loop, kept alive by launchd (nomeus xdebug:mode detect installs the agent).
 * Polls the IDE port; after two consistent polls that differ from the ini, flips it and restarts fpm.
 */
class WatchCommand extends Command
{
    protected $signature = 'xdebug:watch
        {--interval=2 : seconds between polls}
        {--once : one poll, apply, exit (tests / a manual sync)}';

    protected $description = 'Follow the IDE: xdebug on while it listens, off when it stops (the agent behind xdebug:mode detect)';

    public function handle(XdebugManager $xdebug): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $log = fn (string $l) => $this->line(date('H:i:s')." {$l}");
        $stable = null;   // the last state seen twice in a row
        $last = null;

        do {
            if ($xdebug->detecting() === []) {
                $log('no version in detect mode — exiting');

                return self::SUCCESS;
            }
            $now = $xdebug->ideListening();
            if ($this->option('once') || ($last === $now && $stable !== $now)) {
                $stable = $now;
                $changed = $xdebug->applyDetect($now, $log);
                if ($changed === [] && $this->option('once')) {
                    $log('in sync: IDE '.($now ? 'listening' : 'not listening'));
                }
            }
            $last = $now;
            if (! $this->option('once')) {
                sleep($interval);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
