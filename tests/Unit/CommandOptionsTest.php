<?php

use Illuminate\Support\Facades\Artisan;

/** Symfony reserves --version/-V, --help/-h, --quiet/-q, --verbose/-v on every command. */
it('declares no option that collides with symfony\'s global options', function () {
    $reserved = ['version', 'help', 'quiet', 'verbose', 'ansi', 'no-ansi', 'no-interaction', 'env'];
    $offenders = [];
    foreach (Artisan::all() as $name => $command) {
        if (! str_contains(get_class($command), 'App\\Console\\Commands')) {
            continue;
        }
        foreach ($command->getNativeDefinition()->getOptions() as $option) {   // native: before the app's globals are merged in
            if (in_array($option->getName(), $reserved, true)) {
                $offenders[] = "$name --{$option->getName()}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
