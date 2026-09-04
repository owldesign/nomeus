<?php

namespace App\Console\Commands;

use App\Services\AgentAuditor;
use Illuminate\Console\Command;

/** The fix the doctor prints when a nomeus-bound agent points at a checkout or php that moved. */
class AgentsRewriteCommand extends Command
{
    protected $signature = 'agents:rewrite {--dry-run : list what would change and exit}';

    protected $description = 'Rewrite the dump server and xdebug watcher agents so they run this app and this php';

    public function handle(AgentAuditor $auditor): int
    {
        $audit = $auditor->audit();
        if ($audit === []) {
            $this->line('<fg=gray>no nomeus-bound agents installed</>');

            return self::SUCCESS;
        }
        $stale = array_filter($audit, fn ($e) => $e['stale']);
        foreach ($audit as $e) {
            $this->line(($e['stale'] ? '<fg=red>✗</>' : '<fg=green>✓</>')." {$e['name']}".($e['stale'] ? ' — '.implode('; ', $e['reasons']) : ' — runs this app'));
        }
        if ($stale === []) {
            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->line('<fg=gray>dry run — nothing rewritten</>');

            return self::SUCCESS;
        }
        $done = $auditor->rewrite(fn (string $l) => $this->line("<fg=gray>  {$l}</>"));
        $this->info(count($done).' agent(s) rewritten: '.implode(', ', $done));

        return self::SUCCESS;
    }
}
