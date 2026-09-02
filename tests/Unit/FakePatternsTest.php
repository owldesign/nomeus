<?php

/**
 * Process::fake patterns are substring globs. A bare launchctl*list glob silently matches
 * "launchctl bootstrap … .plist" — which once made two tests wait 30 s for a port that
 * never answered. Any launchctl-list fake must use the exact quoted-argv form.
 */
it('never uses the ambiguous launchctl list fake pattern', function () {
    $ambiguous = "'*launchctl*"."list*'";   // built at runtime so this file does not match itself
    $offenders = [];
    $files = array_merge(
        glob(base_path('tests/*/*.php')) ?: [],
        glob(base_path('tests/*/*/*.php')) ?: [],
    );
    foreach ($files as $file) {
        if (str_contains(file_get_contents($file), $ambiguous)) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});
