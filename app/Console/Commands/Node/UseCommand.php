<?php

namespace App\Console\Commands\Node;

use App\Services\Node\NodeManager;
use App\Services\ValetBridge;
use Illuminate\Console\Command;
use RuntimeException;

class UseCommand extends Command
{
    protected $signature = 'node:use {version : 22, 22.11.0, lts} {--site= : pin this site (writes its .nvmrc); default: the current directory if it is a site} {--default : also make it the fnm default}';

    protected $description = 'Pin a site to a Node version (.nvmrc), installing it if needed';

    public function handle(NodeManager $node, ValetBridge $valet): int
    {
        $version = (string) $this->argument('version');
        $dir = null;
        if ($site = $this->option('site')) {
            $s = $valet->find($site);
            if ($s === null) {
                $this->error("No site [{$site}].");

                return self::FAILURE;
            }
            $dir = $s->path;
        } elseif (! $this->option('default')) {
            $dir = getcwd() ?: null;
        }
        try {
            $v = $node->install($version, fn (string $l) => $this->line("<fg=gray>{$l}</>"));
            if ($dir !== null) {
                $node->pin($dir, in_array(strtolower($version), ['lts', 'lts/*'], true) ? $v : $version);
                $this->info(".nvmrc in {$dir} → ".$node->pinOf($dir)." (node {$v})");
            }
            if ($this->option('default')) {
                $node->setDefault($v);
                $this->info("default → node {$v}");
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
