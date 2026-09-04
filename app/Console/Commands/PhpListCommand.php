<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\PhpManager;
use Illuminate\Console\Command;

class PhpListCommand extends Command
{
    protected $signature = 'php:list {--json : Emit as JSON} {--fresh : Re-check brew for updates instead of the cached answer}';

    protected $description = 'Installed PHP versions: global marker, fpm state, sites, ini path, available updates';

    public function handle(PhpManager $php): int
    {
        if ($this->option('fresh')) {
            app(BrewBridge::class)->outdatedPhp(fresh: true);
        }
        $versions = $php->versions();
        $installable = $php->installable();

        if ($this->option('json')) {
            $this->line(json_encode([
                'installed' => array_map(fn ($v) => $v->toArray(), $versions),
                'installable' => $installable,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($versions === []) {
            $this->warn('No php@X.Y kegs under '.app(BrewBridge::class)->prefix().'/opt. Install one: nomeus php:install 8.4');

            return self::SUCCESS;
        }

        $this->table(['version', 'patch', 'global', 'fpm', 'sites', 'update', 'ini'], array_map(fn ($v) => [
            $v->version,
            $v->patch ?? '?',
            $v->linked ? '<fg=yellow>*</>' : '',
            $v->fpm ? '<fg=green>up</>' : '<fg=gray>-</>',
            $v->sites ? implode(', ', $v->sites) : '<fg=gray>-</>',
            $v->outdated ? "<fg=yellow>{$v->outdated}</>" : '<fg=gray>-</>',
            $v->ini,
        ], $versions));

        if ($installable) {
            $this->line('<fg=gray>installable: '.implode(', ', $installable).'   (nomeus php:install <version>)</>');
        }

        return self::SUCCESS;
    }
}
