<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;
use RuntimeException;

/** Shared body for start / stop / restart. */
abstract class LifecycleCommand extends Command
{
    abstract protected function verb(): string;

    public function handle(ServiceManager $services): int
    {
        $name = (string) $this->argument('name');
        $i = $services->find($name);
        if ($i === null) {
            $this->error("No service [{$name}]. See nomeus services:list");

            return self::FAILURE;
        }

        try {
            $services->{$this->verb()}($i);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $done = ['start' => 'running', 'stop' => 'stopped', 'restart' => 'restarted'][$this->verb()];
        $this->info("{$i->name} {$done} (127.0.0.1:{$i->port})");

        return self::SUCCESS;
    }
}
