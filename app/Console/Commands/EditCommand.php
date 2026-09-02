<?php

namespace App\Console\Commands;

use App\Services\SiteResolver;
use App\Support\Editor;
use Illuminate\Console\Command;
use RuntimeException;

class EditCommand extends Command
{
    protected $signature = 'edit {name? : Site name; defaults to the site containing the current directory}';

    protected $description = 'Open a site in the IDE from config.json ("ide": phpstorm, vscode, cursor, sublime, zed, open)';

    public function handle(SiteResolver $sites, Editor $editor): int
    {
        $site = $sites->resolve($this->argument('name'), (string) getcwd());
        if ($site === null) {
            $this->error($this->argument('name')
                ? "Site [{$this->argument('name')}] is not parked or linked."
                : 'Current directory is not inside a Valet site. Pass a name: devkit edit <name>');

            return self::FAILURE;
        }
        if ($site->type === 'proxy') {
            $this->error("[{$site->name}] is a proxy to {$site->path}; nothing to edit.");

            return self::FAILURE;
        }

        try {
            $editor->openDir($site->path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("<fg=gray>opened {$site->path} in {$editor->ide()}</>");

        return self::SUCCESS;
    }
}
