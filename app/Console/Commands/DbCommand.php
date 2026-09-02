<?php

namespace App\Console\Commands;

use App\Services\DatabaseUrl;
use App\Services\SiteResolver;
use App\Support\DevkitConfig;
use App\Support\Shell;
use App\Support\SiteEnv;
use Illuminate\Console\Command;
use RuntimeException;

class DbCommand extends Command
{
    public const CLIENTS = [
        'tableplus' => 'TablePlus',
        'sequel-ace' => 'Sequel Ace',
        'dbeaver' => 'DBeaver',
        'datagrip' => 'DataGrip',
    ];

    protected $signature = 'db
        {name? : Site name; defaults to the site containing the current directory}
        {--print : Print the connection (password masked) instead of opening it}';

    protected $description = 'Open the site\'s database from its .env in the GUI client from config.json ("db_client")';

    public function handle(SiteResolver $sites, DevkitConfig $config, Shell $shell): int
    {
        $site = $sites->resolve($this->argument('name'), (string) getcwd());
        if ($site === null || $site->type === 'proxy') {
            $this->error($site === null
                ? ($this->argument('name') ? "Site [{$this->argument('name')}] is not parked or linked." : 'Current directory is not inside a Valet site. Pass a name: devkit db <name>')
                : "[{$site->name}] is a proxy; it has no .env.");

            return self::FAILURE;
        }

        $env = SiteEnv::read($site->path);
        if ($env === null) {
            $this->error("No .env in {$site->path}.");

            return self::FAILURE;
        }

        try {
            $db = DatabaseUrl::fromEnv($env, $site->path, $site->name);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('print')) {
            $this->line($db['display']);

            return self::SUCCESS;
        }

        if ($db['kind'] === 'file' && ! is_file($db['target'])) {
            $this->error("SQLite file not found: {$db['target']}   (php artisan migrate creates it)");

            return self::FAILURE;
        }

        $client = (string) $config->get('db_client', 'tableplus');
        $app = self::CLIENTS[$client] ?? $client;
        $result = $shell->run(['open', '-a', $app, $db['target']], timeout: 30);
        if (! $result->successful()) {
            $this->error("Could not open {$app}: ".(trim($result->errorOutput()) ?: 'is it installed? config:set db_client <tableplus|sequel-ace|dbeaver|datagrip>'));

            return self::FAILURE;
        }
        $this->line("<fg=gray>{$db['display']} → {$app}</>");

        return self::SUCCESS;
    }
}
