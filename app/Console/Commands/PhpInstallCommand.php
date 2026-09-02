<?php

namespace App\Console\Commands;

use App\Services\PhpManager;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

class PhpInstallCommand extends Command
{
    protected $signature = 'php:install {version : e.g. 8.1 or php@8.1}';

    protected $description = 'Install a PHP version from the '.\App\Services\BrewBridge::TAP.' tap (streams brew output)';

    public function handle(PhpManager $php, Shell $shell): int
    {
        try {
            $plan = $php->installPlan((string) $this->argument('version'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("<fg=gray>{$plan['label']}</>");
        $result = $shell->run($plan['argv'], null, $plan['timeout'], fn (string $type, string $buf) => $this->output->write($buf));
        if (! $result->successful()) {
            $this->error("brew exited {$result->exitCode()}.");

            return self::FAILURE;
        }

        $v = preg_replace('/^php@/', '', (string) $this->argument('version'));
        $this->info("php@{$v} installed. Use it per site: devkit isolate php@{$v} --site=<name>   or globally: devkit use php@{$v}");

        return self::SUCCESS;
    }
}
