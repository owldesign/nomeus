<?php

namespace App\Services\Doctor;

use App\Services\Dumps\DumpStore;
use App\Services\LogSources;
use App\Services\ServiceManager;
use App\Services\TaskRunner;

/** What grows on a machine that stays on for months, and the command that trims it. */
final class RetentionDoctor implements Section
{
    public const SITE_LOG_WARN = 50 * 1024 * 1024;
    public const SERVICE_LOG_WARN = 50 * 1024 * 1024;

    public function __construct(
        private readonly TaskRunner $tasks,
        private readonly DumpStore $dumps,
        private readonly ServiceManager $services,
        private readonly LogSources $logs,
    ) {}

    public function name(): string
    {
        return 'retention';
    }

    public function checks(): array
    {
        $r = new Rows;

        $files = glob($this->tasks->dir().'/*') ?: [];
        $size = array_sum(array_map(fn ($f) => (int) @filesize($f), $files));
        $r->ok('tasks', count($files).' files, '.self::human($size).' — pruned to the newest '.TaskRunner::KEEP.' automatically');

        $dbFile = $this->dumps->path();
        if (is_file($dbFile)) {
            clearstatcache(true, $dbFile);
            $r->expect(filesize($dbFile) < 200 * 1024 * 1024, 'dumps', self::human((int) filesize($dbFile)).' (kept to the newest '.DumpStore::KEEP.' rows)', self::human((int) filesize($dbFile)).' — devkit dumps:clear', 'warn');
        } else {
            $r->ok('dumps', 'no store yet');
        }

        foreach ($this->services->all() as $i) {
            $total = 0;
            foreach (glob($i->logDir().'/*') ?: [] as $f) {
                $total += (int) @filesize($f);
            }
            if ($total > self::SERVICE_LOG_WARN) {
                $r->warn("service logs {$i->name}", self::human($total)." — devkit services:logs {$i->name} --clear");
            }
        }

        foreach ($this->logs->all() as $s) {
            if ($s['kind'] === 'laravel' && $s['size'] > self::SITE_LOG_WARN) {
                $r->warn("site log {$s['group']}", "{$s['label']} is ".self::human($s['size']).' — clear it from the Logs page, or set LOG_CHANNEL=daily in that site');
            }
        }

        return $r->all();
    }

    public static function human(int $bytes): string
    {
        return $bytes < 1024 ? "{$bytes} B" : ($bytes < 1048576 ? round($bytes / 1024).' KB' : round($bytes / 1048576, 1).' MB');
    }
}
