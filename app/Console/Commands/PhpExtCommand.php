<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\Php\PhpExtensions;
use Illuminate\Console\Command;
use RuntimeException;

class PhpExtCommand extends Command
{
    protected $signature = 'php:ext
        {name? : extension to install (redis, imagick, swoole, …); omit to list what each version has}
        {--php= : which version (default: linked)}
        {--all : every installed version}
        {--no-restart : do not restart php-fpm afterwards}';

    protected $description = 'List PHP extensions per version, or install one from the shivammathur/extensions tap';

    public function handle(PhpExtensions $ext, BrewBridge $brew): int
    {
        $versions = $this->option('all') ? $brew->installedPhp() : [$this->option('php') ?: $brew->linkedPhp()];
        $versions = array_values(array_filter($versions));
        if ($versions === []) {
            $this->error('No PHP version — nomeus php:install 8.4');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        if ($name === null) {
            foreach ($this->option('all') ? $brew->installedPhp() : $versions as $v) {
                $mods = $ext->loaded($v);
                $this->line("<fg=yellow>php {$v}</>  ".count($mods).' extensions');
                $this->line('  '.wordwrap(implode(' ', $mods), 100, "\n  "));
            }

            return self::SUCCESS;
        }

        foreach ($versions as $v) {
            try {
                $ext->install($v, $name, fn (string $l) => $this->line("<fg=gray>{$l}</>"), restart: ! $this->option('no-restart'));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }
        $this->info("{$name}: ready on php ".implode(', ', $versions));

        return self::SUCCESS;
    }
}
