<?php

namespace App\Support;

/**
 * Fire-and-forget process launcher.
 *
 * The command runs in a background subshell whose stdin/stdout/stderr are ALL /dev/null.
 * That last part is what makes it return immediately: exec() reads the shell's stdout until
 * EOF, and a backgrounded `a && b > /dev/null &` still leaves the subshell holding that pipe
 * while it waits for b. Redirecting the subshell closes it, so nothing waits for the task.
 * Injectable so tests can capture the command instead of running it.
 */
class TaskSpawner
{
    public function spawn(string $shellCommand): void
    {
        exec('( '.$shellCommand.' ) < /dev/null > /dev/null 2>&1 &');
    }
}
