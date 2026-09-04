<?php

namespace App\Services\Init;

use App\Support\Manifest;
use RuntimeException;

final class InitRunner
{
    public function __construct(private readonly InitPlanner $planner) {}

    /** @return list<Step> */
    public function plan(Manifest $m): array
    {
        return $this->planner->plan($m);
    }

    /**
     * Executes in order; stops at the first failure with the step named.
     *
     * @param  callable(string $stepId, string $line):void  $log
     * @return array{ran: list<string>, skipped: list<string>}
     */
    public function run(Manifest $m, callable $log, bool $skipScripts = false): array
    {
        $ran = [];
        $skipped = [];
        foreach ($this->plan($m) as $step) {
            if ($skipScripts && str_starts_with($step->id, 'post-init:')) {
                $skipped[] = $step->id;
                $log($step->id, "skipped (--skip-scripts): {$step->label}");

                continue;
            }
            if ($step->skip !== null) {
                $skipped[] = $step->id;
                $log($step->id, "{$step->label} — {$step->skip}");

                continue;
            }
            $log($step->id, "▶ {$step->label}");
            try {
                $step->execute(fn (string $line) => $log($step->id, "  {$line}"));
            } catch (RuntimeException $e) {
                throw new RuntimeException("[{$step->id}] {$step->label}: {$e->getMessage()}");
            }
            $ran[] = $step->id;
        }

        return ['ran' => $ran, 'skipped' => $skipped];
    }
}
