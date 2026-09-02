<?php

namespace App\Console\Commands;

use App\Services\TaskRunner;
use Illuminate\Console\Command;

class TaskLogCommand extends Command
{
    protected $signature = 'task:log {id}';

    protected $description = 'Print the output of a background task';

    public function handle(TaskRunner $tasks): int
    {
        $task = $tasks->find((string) $this->argument('id'));
        if ($task === null) {
            $this->error('Unknown task.');

            return self::FAILURE;
        }
        $this->line("<fg=gray>{$task->label}  ·  {$task->status}".($task->exitCode !== null ? "  ·  exit {$task->exitCode}" : '').'</>');
        $this->line(rtrim($tasks->log($task->id)));

        return self::SUCCESS;
    }
}
