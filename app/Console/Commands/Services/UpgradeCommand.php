<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;
use RuntimeException;

class UpgradeCommand extends Command
{
    protected $signature = 'services:upgrade {name} {formula : another formula of the same type, e.g. mysql@9.7} {--yes : skip the confirmation}';

    protected $description = 'Run an instance under a different formula/version — the server upgrades its data in place on start';

    public function handle(ServiceManager $services): int
    {
        $i = $services->find((string) $this->argument('name'));
        if ($i === null) {
            $this->error("No service [{$this->argument('name')}].");

            return self::FAILURE;
        }

        $formula = (string) $this->argument('formula');
        $fallback = isset($i->options['adopted_from'])
            ? "brew's copy at {$i->options['adopted_from']} is your fallback"
            : 'there is no other copy of this data — back it up first';
        if (! $this->option('yes') && ! $this->confirm("Switch {$i->name} from {$i->formula} to {$formula}? Databases upgrade their data dir on the way and do not downgrade; {$fallback}. Continue?")) {
            return self::SUCCESS;
        }

        try {
            $u = $services->retarget($i, $formula, fn (string $l) => $this->line("<fg=gray>{$l}</>"));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$u->name}: {$u->formula} {$u->version} on 127.0.0.1:{$u->port} (was {$i->formula})");

        return self::SUCCESS;
    }
}
