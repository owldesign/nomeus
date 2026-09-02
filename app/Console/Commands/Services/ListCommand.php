<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    protected $signature = 'services:list {--json : Emit as JSON}';

    protected $description = 'Service instances and their state';

    public function handle(ServiceManager $services): int
    {
        $rows = [];
        foreach ($services->all() as $i) {
            $rows[] = $i->toArray() + ['status' => $services->status($i)];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($rows === []) {
            $this->line('No services. See devkit services:available');

            return self::SUCCESS;
        }

        $this->table(['name', 'type', 'formula', 'version', 'port', 'state', 'pid', 'data'], array_map(function ($r) {
            $s = $r['status'];
            $state = match (true) {
                $s['running'] => '<fg=green>running</>',
                $s['loaded'] => '<fg=yellow>starting</>',
                $s['disabled'] => '<fg=gray>stopped</>',
                ! $s['installed'] => '<fg=red>formula missing</>',
                default => '<fg=gray>stopped</>',
            };

            return [$r['name'], $r['type'], $r['formula'], $r['version'], $r['port'], $state, $s['pid'] ?? '', $r['dir'].'/data'];
        }, $rows));

        return self::SUCCESS;
    }
}
