<?php

namespace App\Console\Commands\Services;

use App\Services\BrewBridge;
use App\Services\Services\DriverRegistry;
use Illuminate\Console\Command;

class AvailableCommand extends Command
{
    protected $signature = 'services:available';

    protected $description = 'Service types devkit can run, their brew formulae and default ports';

    public function handle(DriverRegistry $drivers, BrewBridge $brew): int
    {
        $this->table(['type', 'name', 'formulae (● installed)', 'default port'], array_map(fn ($d) => [
            $d->type(),
            $d->label(),
            implode('  ', array_map(fn ($f) => ($brew->isFormulaInstalled($f) ? '● ' : '○ ').$f, $d->formulae())),
            $d->defaultPort(),
        ], array_values($drivers->all())));
        $this->line('<fg=gray>create one: devkit services:create <type> [version] [--name=] [--port=]</>');

        return self::SUCCESS;
    }
}
