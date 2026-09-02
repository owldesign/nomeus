<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\PhpManager;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

class PhpUpdateCommand extends Command
{
    protected $signature = 'php:update {version? : e.g. 8.4; omit to update every outdated version}';

    protected $description = 'Upgrade installed PHP versions with brew (streams output). Run `brew update` first to refresh what counts as outdated.';

    public function handle(PhpManager $php, BrewBridge $brew, Shell $shell): int
    {
        $targets = $this->argument('version')
            ? [$brew->assertVersion((string) $this->argument('version'))]
            : array_keys($brew->outdatedPhp(fresh: true));

        if ($targets === []) {
            $this->info('Every installed PHP is current (per brew\'s last update).');

            return self::SUCCESS;
        }

        foreach ($targets as $version) {
            try {
                $plan = $php->updatePlan($version);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line("<fg=gray>{$plan['label']}</>");
            $result = $shell->run($plan['argv'], null, $plan['timeout'], fn (string $type, string $buf) => $this->output->write($buf));
            if (! $result->successful()) {
                $this->error("brew exited {$result->exitCode()} on php@{$version}.");

                return self::FAILURE;
            }
        }
        $brew->outdatedPhp(fresh: true);
        $this->info('Done. Restart fpm for updated versions: devkit restart');

        return self::SUCCESS;
    }
}
