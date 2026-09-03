<?php

namespace App\Console\Commands;

use App\Services\TaskRunner;
use Illuminate\Console\Command;

class TasksCommand extends Command
{
    protected $signature = 'tasks {--limit=20 : How many recent tasks to show}';

    protected $description = 'Recent background tasks (dashboard actions) and their outcome';

    public function handle(TaskRunner $tasks): int
    {
        $all = $tasks->all((int) $this->option('limit'));
        if ($all === []) {
            $this->line('No tasks yet. Dashboard actions (secure, isolate, …) show up here.');

            return self::SUCCESS;
        }

        $color = ['done' => 'green', 'failed' => 'red', 'running' => 'yellow', 'queued' => 'gray'];
        $this->table(['id', 'status', 'label', 'finished'], array_map(fn ($t) => [
            $t->id,
            sprintf('<fg=%s>%s</>', $color[$t->status] ?? 'white', $t->status),
            $t->label,
            $t->finishedAt ?? '',
        ], $all));
        $this->line('Log of one task: nomeus task:log <id>');

        return self::SUCCESS;
    }
}
