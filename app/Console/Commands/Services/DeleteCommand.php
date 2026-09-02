<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;
use RuntimeException;

class DeleteCommand extends Command
{
    protected $signature = 'services:delete {name} {--keep-data : leave the data directory in place} {--force : skip the confirmation}';

    protected $description = 'Stop and remove a service instance';

    public function handle(ServiceManager $services): int
    {
        $i = $services->find((string) $this->argument('name'));
        if ($i === null) {
            $this->error("No service [{$this->argument('name')}].");

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Delete {$i->name} ({$i->formula}, {$i->dataDir()})".($this->option('keep-data') ? ' keeping its data' : ' AND its data').'?')) {
            return self::SUCCESS;
        }

        try {
            $services->delete($i, (bool) $this->option('keep-data'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$i->name} deleted".($this->option('keep-data') ? " — data kept at {$i->dataDir()}" : ''));

        return self::SUCCESS;
    }
}
