<?php

namespace App\Console\Commands;

use App\Services\Init\InitRunner;
use App\Support\Manifest;
use Illuminate\Console\Command;
use RuntimeException;

class InitCommand extends Command
{
    protected $signature = 'init
        {path? : site directory containing dev.yml; defaults to the current directory}
        {--dry-run : show the plan and change nothing}
        {--skip-scripts : do everything except the post-init commands}';

    protected $description = 'Set a site up from its dev.yml: link, tls, php, node, services, databases, mail, client package, .env, scripts';

    public function handle(InitRunner $init): int
    {
        $dir = realpath((string) ($this->argument('path') ?: getcwd())) ?: (string) $this->argument('path');
        try {
            $m = Manifest::load($dir);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("<fg=gray>{$m->path}/dev.yml → {$m->domain}</>");
            $this->table(['step', 'action', ''], array_map(fn ($s) => [
                $s->id,
                $s->skip ? "<fg=gray>{$s->label}</>" : $s->label,
                $s->skip ? "<fg=green>skip</> {$s->skip}" : '<fg=yellow>run</> '.($s->detail ?? ''),
            ], $init->plan($m)));

            return self::SUCCESS;
        }

        try {
            $result = $init->run($m, fn (string $id, string $line) => $this->line(
                str_starts_with($line, '▶') ? "<fg=yellow>{$line}</>" : "<fg=gray>{$line}</>"
            ), skipScripts: (bool) $this->option('skip-scripts'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s.%s ready — %d step%s run, %d already in place',
            $m->domain, app(\App\Services\ValetBridge::class)->tld(),
            count($result['ran']), count($result['ran']) === 1 ? '' : 's', count($result['skipped'])));

        return self::SUCCESS;
    }
}
