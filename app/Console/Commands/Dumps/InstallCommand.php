<?php

namespace App\Console\Commands\Dumps;

use App\Services\Dumps\PrependInstaller;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'dumps:install {--restart : run `valet restart php` afterwards so php-fpm loads the ini}';

    protected $description = 'Write the auto_prepend_file ini into every installed PHP version (needed once; again after a new PHP version)';

    public function handle(PrependInstaller $installer, Shell $shell): int
    {
        try {
            $r = $installer->install();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("<fg=gray>prepend {$r['prepend']}</>");
        foreach ($r['written'] as $v) {
            $this->line("<fg=yellow>php {$v}</>  ".$installer->iniPath($v).' written');
        }
        foreach ($r['unchanged'] as $v) {
            $this->line("<fg=gray>php {$v}  already current</>");
        }
        if ($r['versions'] === []) {
            $this->warn('No PHP versions installed under brew.');
        }

        if ($r['written'] !== []) {
            if ($this->option('restart')) {
                $plan = $installer->restartPlan();
                $this->line("<fg=gray>{$plan['label']}</>");
                $result = $shell->run($plan['argv'], null, $plan['timeout'], fn ($t, $b) => $this->line('<fg=gray>'.rtrim($b).'</>'));
                if (! $result->successful()) {
                    $this->error('valet restart php failed: '.trim($result->errorOutput() ?: $result->output()));

                    return self::FAILURE;
                }
            } else {
                $this->line('<fg=yellow>php-fpm reads ini files at start:</> valet restart php   (or: devkit dumps:install --restart)');
            }
        }

        return self::SUCCESS;
    }
}
