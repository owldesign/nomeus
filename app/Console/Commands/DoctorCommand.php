<?php

namespace App\Console\Commands;

use App\Services\Doctor\DoctorAggregate;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'doctor {--section= : valet, php, devkit, services, dumps, mail, retention} {--json}';

    protected $description = 'Check every layer devkit depends on and name the fix for anything wrong';

    public function handle(DoctorAggregate $doctor): int
    {
        $only = $this->option('section') ?: null;
        if ($only !== null && ! in_array($only, $doctor->sectionNames(), true)) {
            $this->error('Sections: '.implode(', ', $doctor->sectionNames()));

            return self::FAILURE;
        }
        $result = $doctor->run($only);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $color = ['ok' => 'green', 'warn' => 'yellow', 'fail' => 'red'];
            $this->table(['', 'section', 'check', 'detail'], array_map(fn ($r) => [
                "<fg={$color[$r['level']]}>".strtoupper($r['level']).'</>', $r['section'], $r['check'], $r['detail'],
            ], $result['rows']));
            $c = $result['counts'];
            $this->line(sprintf('<fg=green>%d ok</> · <fg=yellow>%d warn</> · <fg=red>%d fail</>', $c['ok'], $c['warn'], $c['fail']));
        }

        return $result['counts']['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
