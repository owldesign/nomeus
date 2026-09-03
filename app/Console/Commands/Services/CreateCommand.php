<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;
use RuntimeException;

class CreateCommand extends Command
{
    // "version" is positional: --version is Symfony's global option on every artisan command.
    protected $signature = 'services:create
        {type : postgresql, mysql, redis, …}
        {version? : e.g. 17 or postgresql@17; defaults to the newest the driver knows}
        {--name= : instance name; defaults to the type, then type-2, …}
        {--port= : defaults to the standard port when free, else the next free one}
        {--site= : for site-bound types (reverb): the parked/linked site it runs inside}
        {--no-start : create without starting}';

    protected $description = 'Create a service instance: install the formula if needed, initialize data, register with launchd, start';

    public function handle(ServiceManager $services): int
    {
        try {
            $i = $services->create(
                type: (string) $this->argument('type'),
                version: $this->argument('version') ?: null,
                name: $this->option('name') ?: null,
                port: $this->option('port') !== null ? (int) $this->option('port') : null,
                start: ! $this->option('no-start'),
                log: fn (string $line) => $this->line("<fg=gray>{$line}</>"),
                site: $this->option('site') ?: null,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$i->name}: {$i->formula} {$i->version} on 127.0.0.1:{$i->port}".($this->option('no-start') ? ' (not started)' : ''));
        $this->line('<fg=gray>.env for it: nomeus services:env '.$i->name.'</>');

        return self::SUCCESS;
    }
}
