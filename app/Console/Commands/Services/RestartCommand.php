<?php

namespace App\Console\Commands\Services;

class RestartCommand extends LifecycleCommand
{
    protected $signature = 'services:restart {name}';

    protected $description = 'Restart a service instance';

    protected function verb(): string { return 'restart'; }
}
