<?php

namespace App\Console\Commands;

use App\Services\TaskRunner;
use Illuminate\Console\Command;

/** Internal: the detached worker behind every dashboard mutation. Spawned by TaskRunner::spawn(). */
class TaskRunCommand extends Command
{
    protected $signature = 'task:run {id}';

    protected $description = 'Run a queued devkit task (internal)';

    protected $hidden = true;

    public function handle(TaskRunner $tasks): int
    {
        // New session first: brew services stop/start (which Valet does for nginx and fpm)
        // makes launchd kill the old service's whole process group, and we were born in it.
        if (function_exists('posix_setsid') && ! app()->runningUnitTests()) {
            @posix_setsid();
        }

        return $tasks->run((string) $this->argument('id')) ? self::SUCCESS : self::FAILURE;
    }
}
