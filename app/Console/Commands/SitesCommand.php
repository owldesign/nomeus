<?php

namespace App\Console\Commands;

use App\Services\StatusService;
use App\Services\ValetBridge;
use Illuminate\Console\Command;

class SitesCommand extends Command
{
    protected $signature = 'sites {--json : Emit as JSON}';

    protected $description = 'List every site Valet serves: parked, linked and proxied';

    public function handle(ValetBridge $valet, StatusService $status): int
    {
        $sites = $valet->sites();

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn ($s) => $s->toArray(), $sites), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($sites === []) {
            $this->warn('No sites. Park a directory (nomeus park) or link one (nomeus link).');

            return self::SUCCESS;
        }

        $global = $status->snapshot()['php']['global'] ?? null;
        $globalShort = $global && preg_match('/^(\d+\.\d+)/', $global, $m) ? $m[1] : '?';

        $this->table(
            ['site', 'url', 'type', 'php', 'tls', 'path'],
            array_map(fn ($s) => [
                $s->name,
                $s->url(),
                $s->type,
                $s->php ? "<fg=yellow>{$s->php}</> isolated" : "{$globalShort} <fg=gray>global</>",
                $s->secured ? '<fg=green>yes</>' : '<fg=gray>no</>',
                $s->path,
            ], $sites),
        );

        return self::SUCCESS;
    }
}
