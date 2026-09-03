<?php

namespace App\Console\Commands\Node;

use App\Services\Node\NodeManager;
use App\Services\ValetBridge;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'node {--json}';

    protected $description = 'Node versions (fnm): installed, the default, and which sites pin what';

    public function handle(NodeManager $node, ValetBridge $valet): int
    {
        $installed = $node->installed();
        $pins = $node->pins($valet->isInstalled() ? $valet->sites() : []);
        if ($this->option('json')) {
            $this->line(json_encode(['fnm' => $node->fnmBin(), 'versions' => $installed['versions'], 'default' => $installed['default'], 'pins' => $pins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if (! $node->available()) {
            $this->line('<fg=yellow>fnm is not installed</> — brew install fnm, then: eval "$(fnm env --use-on-cd)" in your shell (install.sh does both)');

            return self::SUCCESS;
        }
        $this->line('<fg=gray>'.$node->fnmBin().'</>');
        if ($installed['versions'] === []) {
            $this->line('no node versions — nomeus node:install lts');
        }
        foreach ($installed['versions'] as $v) {
            $this->line("  node {$v}".($v === $installed['default'] ? ' <fg=green>default</>' : ''));
        }
        if ($pins !== []) {
            $this->table(['site', '.nvmrc', 'installed'], array_map(fn ($p) => [$p['site'], $p['pin'], $p['installed'] ?? '<fg=red>no — nomeus node:install '.$p['pin'].'</>'], $pins));
        }

        return self::SUCCESS;
    }
}
