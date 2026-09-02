<?php

namespace App\Console\Commands\Services;

use App\Services\ServiceManager;
use Illuminate\Console\Command;

class LogsCommand extends Command
{
    protected $signature = 'services:logs {name} {--lines=50} {--clear : truncate the instance\'s log files in place}';

    protected $description = 'Tail a service instance\'s logs';

    public function handle(ServiceManager $services): int
    {
        $i = $services->find((string) $this->argument('name'));
        if ($i === null) {
            $this->error("No service [{$this->argument('name')}].");

            return self::FAILURE;
        }
        if ($this->option('clear')) {
            $freed = 0;
            foreach (glob($i->logDir().'/*') ?: [] as $file) {
                $freed += (int) @filesize($file);
                $fh = fopen($file, 'r+');
                if ($fh) {
                    ftruncate($fh, 0);   // in place: launchd keeps the file handle open, so unlinking would not free space
                    fclose($fh);
                }
            }
            $this->info("{$i->name}: logs cleared (".\App\Services\Doctor\RetentionDoctor::human($freed).')');

            return self::SUCCESS;
        }
        $this->line(rtrim($services->logTail($i, (int) $this->option('lines'))) ?: '<fg=gray>(no log output yet)</>');

        return self::SUCCESS;
    }
}
