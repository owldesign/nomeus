<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;
use RuntimeException;

class CloneCommand extends Command
{
    protected $signature = 'services:clone {name} {new-name} {--port= : port for the clone; defaults to the next free one}';

    protected $description = 'Copy a service instance (data included) to a new name and port';

    public function handle(ServiceManager $services): int
    {
        $source = $services->find((string) $this->argument('name'));
        if ($source === null) {
            $this->error("No service [{$this->argument('name')}].");

            return self::FAILURE;
        }

        try {
            $clone = $services->clone(
                $source,
                (string) $this->argument('new-name'),
                $this->option('port') !== null ? (int) $this->option('port') : null,
                fn (string $line) => $this->line("<fg=gray>{$line}</>"),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$clone->name}: {$clone->formula} on 127.0.0.1:{$clone->port}, cloned from {$source->name}");

        return self::SUCCESS;
    }
}
