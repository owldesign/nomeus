<?php

namespace App\Console\Commands;

use App\Support\Shell;
use Illuminate\Console\Command;

/**
 * Update devkit in place: git pull, composer, npm build, regenerate the php ini, doctor.
 * Runs as a task from the dashboard too — the dashboard being updated is fine, the task is detached.
 */
class SelfUpdateCommand extends Command
{
    protected $signature = 'self-update
        {--check : only report whether there is anything to pull}
        {--no-build : skip npm ci / npm run build}
        {--no-git : skip fetching/pulling (deps, build and ini only)}';

    protected $description = 'Pull, install dependencies, rebuild the dashboard, regenerate the php ini, then run the doctor';

    public function handle(Shell $shell): int
    {
        $root = base_path();
        $git = is_dir("{$root}/.git") && ! $this->option('no-git');
        $run = function (array $argv, string $label, int $timeout = 900) use ($shell, $root): bool {
            $this->line("<fg=yellow>▶ {$label}</>");
            $result = $shell->run($argv, $root, $timeout, fn ($t, $b) => $this->line('<fg=gray>  '.rtrim($b).'</>'));
            if (! $result->successful()) {
                $this->error("{$label} failed (exit {$result->exitCode()})");
            }

            return $result->successful();
        };

        if ($git && ! $shell->run(['git', 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $root, 20)->successful()) {
            $this->line('<fg=gray>no upstream branch configured — skipping git (deps, build and ini still refresh)</>');
            $git = false;
            if ($this->option('check')) {
                return self::SUCCESS;
            }
        }
        if ($git) {
            $dirty = trim($shell->run(['git', 'status', '--porcelain'], $root, 20)->output());
            if ($dirty !== '') {
                $this->error("Working tree has changes:\n{$dirty}\nCommit or stash them first (self-update only fast-forwards).");

                return self::FAILURE;
            }
            if (! $run(['git', 'fetch', '--quiet'], 'git fetch', 120)) {
                return self::FAILURE;
            }
            $behind = (int) trim($shell->run(['git', 'rev-list', '--count', 'HEAD..@{u}'], $root, 20)->output());
            $ahead = (int) trim($shell->run(['git', 'rev-list', '--count', '@{u}..HEAD'], $root, 20)->output());
            $this->line($behind === 0
                ? '<fg=green>up to date with upstream</>'.($ahead ? " ({$ahead} ahead)" : '')
                : "<fg=yellow>{$behind} commit(s) behind upstream</>");
            if ($this->option('check')) {
                return self::SUCCESS;
            }
            if ($behind > 0 && ! $run(['git', 'pull', '--ff-only', '--quiet'], 'git pull --ff-only', 300)) {
                return self::FAILURE;
            }
        } elseif ($this->option('check')) {
            $this->line('<fg=gray>not a git checkout — nothing to check; run without --check to refresh deps, build and ini</>');

            return self::SUCCESS;
        }

        if (! $run(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], 'composer install', 900)) {
            return self::FAILURE;
        }
        if (! $this->option('no-build')) {
            if (! $run(['npm', 'ci', '--no-audit', '--no-fund'], 'npm ci', 900) || ! $run(['npm', 'run', 'build'], 'npm run build', 600)) {
                return self::FAILURE;
            }
        }

        $this->line('<fg=yellow>▶ dumps:install</> <fg=gray>(regenerates 99-devkit.ini per php version — xdebug block included)</>');
        if ($this->call('dumps:install') !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->line('<fg=yellow>▶ doctor</>');
        $this->call('doctor');
        $this->info('devkit '.config('devkit.version').' — reload the dashboard.');

        return self::SUCCESS;
    }
}
