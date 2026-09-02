<?php

namespace App\Console\Commands\Services;

use App\Services\BrewBridge;
use App\Services\Services\DriverRegistry;
use Illuminate\Console\Command;
use RuntimeException;

class VersionsCommand extends Command
{
    protected $signature = 'services:versions {type : postgresql, mysql, redis, …}';

    protected $description = 'Formulae available for a service type, with what\'s installed';

    public function handle(DriverRegistry $drivers, BrewBridge $brew): int
    {
        try {
            $driver = $drivers->get($this->argument('type'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['formula', 'installed', 'version'], array_map(fn ($f) => [
            $f,
            $brew->isFormulaInstalled($f) ? '<fg=green>yes</>' : '<fg=gray>no</>',
            $brew->formulaVersion($f) ?? '<fg=gray>-</>',
        ], $driver->formulae()));

        return self::SUCCESS;
    }
}
