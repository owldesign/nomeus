<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\SiteResolver;
use App\Support\Editor;
use Illuminate\Console\Command;
use RuntimeException;

class IniCommand extends Command
{
    protected $signature = 'ini
        {version? : PHP version, e.g. 8.3. Defaults to the site\'s isolated version, else the global one}
        {--site= : Resolve the version from this site instead of the current directory}
        {--fpm : The Valet fpm pool config for that version instead of php.ini}
        {--print : Print the path instead of opening it}';

    protected $description = 'Open (or print) the php.ini that serves a site or version';

    public function handle(BrewBridge $brew, SiteResolver $sites, Editor $editor): int
    {
        $version = $this->argument('version');
        if ($version === null) {
            $site = $sites->resolve($this->option('site'), (string) getcwd());
            if ($this->option('site') && $site === null) {
                $this->error("Site [{$this->option('site')}] is not parked or linked.");

                return self::FAILURE;
            }
            $version = $site?->php ?? $brew->linkedPhp();
            if ($version === null) {
                $this->error('No global PHP is linked; pass a version: devkit ini 8.4');

                return self::FAILURE;
            }
        }
        $version = $brew->assertVersion($version);
        if (! in_array($version, $brew->installedPhp(), true)) {
            $this->error("php@{$version} is not installed. Installed: ".implode(', ', $brew->installedPhp()));

            return self::FAILURE;
        }

        $path = $this->option('fpm')
            ? $brew->prefix()."/etc/php/{$version}/php-fpm.d/valet-fpm.conf"
            : $brew->prefix()."/etc/php/{$version}/php.ini";

        if (! is_file($path)) {
            $this->error("Not found: {$path}".($this->option('fpm') ? ' — Valet writes it on first `use`/`isolate` of that version.' : ''));

            return self::FAILURE;
        }

        if ($this->option('print')) {
            $this->line($path);

            return self::SUCCESS;
        }

        try {
            $editor->openFile($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("<fg=gray>opened {$path} in {$editor->ide()} — restart fpm after edits: devkit restart</>");

        return self::SUCCESS;
    }
}
