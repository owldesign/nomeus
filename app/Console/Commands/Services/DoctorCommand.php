<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceDoctor;
use Illuminate\Console\Command;
use RuntimeException;

class DoctorCommand extends Command
{
    protected $signature = 'services:doctor {--self-test : also create, clone, connect and delete a throwaway redis} {--json}';

    protected $description = 'Check the services layer: launchd, agents, each instance, brew services overlaps';

    public function handle(ServiceDoctor $doctor): int
    {
        $checks = $doctor->checks();

        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $color = ['ok' => 'green', 'warn' => 'yellow', 'fail' => 'red'];
            $this->table(['', 'check', 'detail'], array_map(fn ($c) => [
                "<fg={$color[$c['level']]}>".strtoupper($c['level']).'</>', $c['check'], $c['detail'],
            ], $checks));
        }

        $failed = count(array_filter($checks, fn ($c) => $c['level'] === 'fail')) > 0;

        if ($this->option('self-test')) {
            $this->line('');
            try {
                $doctor->selfTest(fn (string $l) => $this->line("<fg=gray>{$l}</>"));
                $this->info('self-test passed: create → clone → connect → delete');
            } catch (RuntimeException $e) {
                $this->error('self-test failed: '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
