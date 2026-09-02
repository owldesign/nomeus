<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;

class LogsCommand extends Command
{
    protected $signature = 'services:logs {name} {--lines=50}';

    protected $description = 'Tail a service instance\'s logs';

    public function handle(ServiceManager $services): int
    {
        $i = $services->find((string) $this->argument('name'));
        if ($i === null) {
            $this->error("No service [{$this->argument('name')}].");

            return self::FAILURE;
        }
        $this->line(rtrim($services->logTail($i, (int) $this->option('lines'))) ?: '<fg=gray>(no log output yet)</>');

        return self::SUCCESS;
    }
}
