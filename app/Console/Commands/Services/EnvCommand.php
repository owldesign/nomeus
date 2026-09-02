<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;

class EnvCommand extends Command
{
    protected $signature = 'services:env {name}';

    protected $description = 'Print the .env lines a Laravel app needs to use a service instance';

    public function handle(ServiceManager $services): int
    {
        $i = $services->find((string) $this->argument('name'));
        if ($i === null) {
            // stderr on purpose: this command's stdout gets redirected into .env files.
            $this->output->getErrorStyle()->writeln("<error>No service [{$this->argument('name')}].</error>");

            return self::FAILURE;
        }
        foreach ($services->env($i) as $k => $v) {
            $this->line("{$k}={$v}");
        }

        return self::SUCCESS;
    }
}
