<?php

namespace App\Console\Commands\Services;

class StopCommand extends LifecycleCommand
{
    protected $signature = 'services:stop {name}';

    protected $description = 'Stop a service instance (stays stopped across logins)';

    protected function verb(): string
    {
        return 'stop';
    }
}
