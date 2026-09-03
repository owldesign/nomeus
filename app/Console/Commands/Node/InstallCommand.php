<?php

namespace App\Console\Commands\Node;

use App\Services\Node\NodeManager;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'node:install {version : 22, 22.11, 22.11.0, or lts} {--default : make it the default}';

    protected $description = 'Install a Node version with fnm';

    public function handle(NodeManager $node): int
    {
        try {
            $v = $node->install((string) $this->argument('version'), fn (string $l) => $this->line("<fg=gray>{$l}</>"));
            if ($this->option('default')) {
                $node->setDefault($v);
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("node {$v}".($this->option('default') ? ' (default)' : ''));

        return self::SUCCESS;
    }
}
