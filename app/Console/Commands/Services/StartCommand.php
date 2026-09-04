<?php

namespace App\Console\Commands\Services;

class StartCommand extends LifecycleCommand
{
    protected $signature = 'services:start {name}';

    protected $description = 'Start a service instance (and keep it starting at login)';

    protected function verb(): string
    {
        return 'start';
    }
}
