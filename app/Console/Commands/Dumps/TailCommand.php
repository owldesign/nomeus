<?php

namespace App\Console\Commands\Dumps;

use App\Services\Dumps\CaptureFlag;
use App\Services\Dumps\DumpStore;
use Illuminate\Console\Command;

class TailCommand extends Command
{
    protected $signature = 'dumps {--kind= : dump, query, job, view, request, log} {--lines=20} {--follow}';

    protected $description = 'Show recent dumps and recorded events in the terminal';

    public function handle(DumpStore $store, CaptureFlag $flag): int
    {
        if (! $flag->isOn()) {
            $this->line('<fg=gray>capture is off (nomeus dumps:capture on) — showing what is stored</>');
        }
        $rows = $store->page($this->option('kind'), null, null, max(1, (int) $this->option('lines')));
        foreach ($rows as $r) {
            $this->print($r);
        }
        if (! $this->option('follow')) {
            return self::SUCCESS;
        }
        $last = $rows ? end($rows)['id'] : 0;
        while (true) {   // @phpstan-ignore-line — Ctrl-C ends it
            usleep(700_000);
            foreach ($store->page($this->option('kind'), null, $last) as $r) {
                $this->print($r);
                $last = $r['id'];
            }
        }
    }

    private function print(array $r): void
    {
        $color = ['dump' => 'yellow', 'query' => 'blue', 'job' => 'magenta', 'view' => 'cyan', 'request' => 'green', 'log' => 'gray'][$r['kind']] ?? 'gray';
        $where = $r['file'] ? basename($r['file']).($r['line'] ? ":{$r['line']}" : '') : ($r['uri'] ?? $r['command'] ?? '');
        $this->line(sprintf('<fg=gray>%s</> <fg=%s>%-7s</> <fg=gray>%s</>', substr($r['created_at'], 11, 12), $color, $r['kind'], $where));
        foreach (explode("\n", rtrim($r['text'])) as $l) {
            $this->line("    {$l}");
        }
    }
}
