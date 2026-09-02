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
            $this->error("No service [{$this->argument('name')}].");

            return self::FAILURE;
        }
        foreach ($services->env($i) as $k => $v) {
            $this->line("{$k}={$v}");
        }

        return self::SUCCESS;
    }
}
