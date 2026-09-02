<?php

namespace App\Console\Commands\Xdebug;

use App\Services\BrewBridge;
use App\Services\Php\XdebugManager;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'xdebug:install {version? : e.g. 8.4; defaults to the linked php}';

    protected $description = 'Install Xdebug for a PHP version (shivammathur/extensions tap) and take over its configuration';

    public function handle(XdebugManager $xdebug, BrewBridge $brew): int
    {
        $version = $this->argument('version') ?: $brew->linkedPhp();
        if (! $version) {
            $this->error('Which PHP? devkit xdebug:install 8.4');

            return self::FAILURE;
        }
        try {
            $r = $xdebug->install($version, fn (string $l) => $this->line("<fg=gray>{$l}</>"));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("php {$version}: xdebug installed, mode {$r['mode']}. Next: devkit xdebug:mode on --php={$version}   (or trigger)");

        return self::SUCCESS;
    }
}
