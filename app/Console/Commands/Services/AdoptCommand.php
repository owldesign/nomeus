<?php

namespace App\Console\Commands\Services;

use App\Services\BrewServices;
use App\Services\ServiceManager;
use Illuminate\Console\Command;
use RuntimeException;

class AdoptCommand extends Command
{
    protected $signature = 'services:adopt
        {formula? : a brew services formula, e.g. postgresql@14, mysql, redis; omit to list what is adoptable}
        {--name= : instance name; defaults to the type, then type-2, …}
        {--port= : defaults to the standard port (free once brew\'s copy stops)}
        {--formula= : run the data under this formula instead of brew\'s, e.g. mysql@9.7 for a 9.6 cluster brew has since moved past}';

    protected $description = 'Take over a `brew services` cluster as a devkit instance — data copied, brew\'s left in place';

    public function handle(ServiceManager $services, BrewServices $brew): int
    {
        if (! $this->argument('formula')) {
            $list = $brew->adoptable();
            if ($list === []) {
                $this->line('Nothing under brew services that devkit has a driver for.');

                return self::SUCCESS;
            }
            $this->table(['formula', 'type', 'state', 'port', 'data'], array_map(fn ($s) => [
                $s['formula'], $s['type'],
                $s['loaded'] ? '<fg=green>running</>' : ($s['plist'] ? 'stopped (starts at login)' : 'stopped'),
                $s['port'].($s['answering'] ? '' : ' (silent)'),
                $s['data_dir'],
            ], $list));
            $this->line('<fg=gray>devkit services:adopt <formula></>');

            return self::SUCCESS;
        }

        try {
            $i = $services->adopt(
                (string) $this->argument('formula'),
                $this->option('name') ?: null,
                $this->option('port') !== null ? (int) $this->option('port') : null,
                fn (string $line) => $this->line("<fg=gray>{$line}</>"),
                $this->option('formula') ?: null,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$i->name}: {$i->formula} {$i->version} on 127.0.0.1:{$i->port}, adopted from {$i->options['adopted_from']}");
        $this->line('<fg=gray>brew\'s data is untouched there; remove it when you\'re sure. .env: devkit services:env '.$i->name.'</>');

        return self::SUCCESS;
    }
}
