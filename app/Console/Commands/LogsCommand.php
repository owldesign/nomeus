<?php

namespace App\Console\Commands;

use App\Services\LogSources;
use App\Services\LogTailer;
use Illuminate\Console\Command;
use RuntimeException;

class LogsCommand extends Command
{
    protected $signature = 'logs
        {site? : a parked/linked site; its newest storage/logs/*.log is shown}
        {--file= : a specific log file name in that site (e.g. laravel-2026-09-02.log)}
        {--nginx : valet\'s nginx error log instead}
        {--fpm : valet\'s php-fpm log instead}
        {--lines=50 : how many recent entries to start with}
        {--level= : only entries at this severity (error, warning, info, debug)}
        {--follow : keep printing new entries until Ctrl-C}';

    protected $description = 'Show (and follow) a site\'s Laravel log, or valet\'s nginx / php-fpm logs';

    public function handle(LogSources $sources, LogTailer $tailer): int
    {
        try {
            $source = $this->pick($sources);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("<fg=gray>{$source['path']}</>");

        $page = $tailer->read($source['path']);
        $entries = array_slice($page['entries'], -max(1, (int) $this->option('lines')));
        foreach ($entries as $e) {
            $this->print($e);
        }
        if (! $this->option('follow')) {
            return self::SUCCESS;
        }

        $offset = $page['offset'];
        while (true) {   // @phpstan-ignore-line — ends with Ctrl-C
            usleep(1_000_000);
            $page = $tailer->read($source['path'], $offset);
            if ($page['reset']) {
                $this->line('<fg=gray>— log was truncated, starting over —</>');
            }
            foreach ($page['entries'] as $e) {
                $this->print($e);
            }
            $offset = $page['offset'];
        }
    }

    private function pick(LogSources $sources): array
    {
        $all = $sources->all();
        if ($this->option('nginx') || $this->option('fpm')) {
            $kind = $this->option('nginx') ? 'nginx' : 'php-fpm';
            foreach ($all as $s) {
                if ($s['kind'] === $kind) {
                    return $s;
                }
            }
            throw new RuntimeException("No {$kind} log yet (valet writes it on first error).");
        }
        $site = $this->argument('site') ?: basename((string) getcwd());
        $mine = array_values(array_filter($all, fn ($s) => $s['group'] === $site));
        if ($mine === []) {
            throw new RuntimeException("No logs for [{$site}]: not a site, or storage/logs has no *.log yet. Sites with logs: ".implode(', ', array_unique(array_column($all, 'group'))));
        }
        if ($file = $this->option('file')) {
            foreach ($mine as $s) {
                if ($s['label'] === $file) {
                    return $s;
                }
            }
            throw new RuntimeException("[{$site}] has no {$file}. Files: ".implode(', ', array_column($mine, 'label')));
        }

        return $mine[0];
    }

    private function print(array $e): void
    {
        $want = $this->option('level');
        if ($want && $e['severity'] !== $want) {
            return;
        }
        $color = ['error' => 'red', 'warning' => 'yellow', 'info' => 'blue', 'debug' => 'gray'][$e['severity']];
        $this->line(sprintf('<fg=gray>%s</> <fg=%s>%-8s</> %s', $e['ts'] ?? '—', $color, strtoupper($e['level']), $e['message']));
        if ($e['trace'] !== '') {
            foreach (array_slice(explode("\n", $e['trace']), 0, 12) as $l) {
                $this->line("<fg=gray>    {$l}</>");
            }
            if (substr_count($e['trace'], "\n") >= 12) {
                $this->line('<fg=gray>    …</>');
            }
        }
    }
}
