<?php

namespace App\Services\New;

use App\Support\Shell;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Creates the application directory: composer create-project, a named starter package, or an empty directory. */
final class SiteScaffolder
{
    public const DEFAULT = 'laravel/laravel';

    public function __construct(private readonly Shell $shell) {}

    /**
     * @param  string|null  $from  composer package (with optional constraint), null = empty directory
     * @param  callable(string):void  $log
     */
    public function scaffold(string $dir, ?string $from, callable $log): void
    {
        $exists = is_dir($dir);
        $nonEmpty = $exists && array_diff(scandir($dir) ?: [], ['.', '..', '.DS_Store']) !== [];
        if ($from === null) {
            if (! $exists) {
                mkdir($dir, 0755, true);
                $log("created {$dir}");
            } else {
                $log("using existing {$dir}");
            }

            return;
        }
        if ($nonEmpty) {
            throw new RuntimeException("{$dir} is not empty — pick another name/--dir, or use --empty to keep what is there.");
        }
        if (! is_dir(dirname($dir))) {
            mkdir(dirname($dir), 0755, true);
        }
        $log("composer create-project {$from} ".basename($dir));
        $result = Process::env($this->shell->env())->path(dirname($dir))->timeout(1800)
            ->run(['composer', 'create-project', '--no-interaction', '--prefer-dist', '--no-progress', $from, basename($dir)], fn ($t, $b) => $log(rtrim($b)));
        if (! $result->successful()) {
            throw new RuntimeException("composer create-project failed (exit {$result->exitCode()})");
        }
    }
}
